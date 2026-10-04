<?php

declare(strict_types=1);

use Prozharka\Http;
use Prozharka\ProdamusHmac;

$configuredRoot = trim((string) getenv('PROZHARKA_BACKEND_ROOT'));
$backendRoot = $configuredRoot !== '' ? rtrim($configuredRoot, '/\\') : dirname(__DIR__);
$container = require $backendRoot . '/src/bootstrap.php';
$config = $container['config'];
$subscriptions = $container['subscriptions'];
$bot = $container['bot'];
$worker = $container['worker'];

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$requestPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
$scriptDirectory = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/api/index.php'))), '/');
$path = '/' . ltrim(substr($requestPath, strlen($scriptDirectory)), '/');

try {
    if ($method === 'GET' && $path === '/health') {
        Http::json([
            'ok' => true,
            'service' => 'prozharka-access',
            'removals_enabled' => $config->bool('ACCESS_REMOVAL_ENABLED'),
            'dry_run' => $config->bool('DRY_RUN', true),
        ]);
    }

    if ($method === 'POST' && $path === '/checkout') {
        $checkoutInput = Http::jsonBody();
        $checkoutInput['_consent_ip'] = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
        $checkoutInput['_consent_user_agent'] = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
        Http::json(['ok' => true] + $subscriptions->createCheckout($checkoutInput), 201);
    }

    if ($method === 'GET' && preg_match('#^/orders/([A-Za-z0-9_-]{20,200})$#', $path, $matches)) {
        $orderStatus = $subscriptions->orderStatus($matches[1]);
        if (($orderStatus['status'] ?? '') === 'preparing_access') {
            $worker->run(10);
            $orderStatus = $subscriptions->orderStatus($matches[1]);
        }
        Http::json(['ok' => true] + $orderStatus);
    }

    if ($method === 'POST' && $path === '/webhooks/prodamus') {
        $payload = $_POST;
        $signature = Http::header('Sign');
        if (empty($payload) || !ProdamusHmac::verify($payload, $config->require('PRODAMUS_SECRET_KEY'), $signature)) {
            Http::json(['ok' => false, 'error' => 'invalid_signature'], 401);
        }
        $result = $subscriptions->handleProdamusWebhook($payload);
        $access = $result === 'activated_or_renewed' ? $worker->run(10) : null;
        Http::json(['ok' => true, 'result' => $result, 'access' => $access]);
    }

    if ($method === 'POST' && $path === '/webhooks/telegram') {
        $expected = $config->require('TELEGRAM_WEBHOOK_SECRET');
        $actual = Http::header('X-Telegram-Bot-Api-Secret-Token');
        if ($actual === '' || !hash_equals($expected, $actual)) {
            Http::json(['ok' => false, 'error' => 'invalid_secret'], 401);
        }
        $update = Http::jsonBody();
        $message = is_array($update['message'] ?? null) ? $update['message'] : [];
        $forwardOrigin = is_array($message['forward_origin'] ?? null) ? $message['forward_origin'] : [];
        $forwardChat = is_array($forwardOrigin['chat'] ?? null)
            ? $forwardOrigin['chat']
            : (is_array($message['forward_from_chat'] ?? null) ? $message['forward_from_chat'] : []);
        $diagnostic = [
            'update_id' => (int) ($update['update_id'] ?? 0),
            'update_types' => array_values(array_diff(array_keys($update), ['update_id'])),
            'message_chat_type' => (string) ($message['chat']['type'] ?? ''),
            'message_kind' => isset($message['text'])
                ? (str_starts_with(trim((string) $message['text']), '/') ? 'command' : 'text')
                : (isset($message['photo']) ? 'photo' : (isset($message['video']) ? 'video' : 'other')),
            'forward_origin_type' => (string) ($forwardOrigin['type'] ?? ''),
            'forward_chat_id' => (string) ($forwardChat['id'] ?? ''),
            'forward_chat_type' => (string) ($forwardChat['type'] ?? ''),
            'received_at' => gmdate(DATE_ATOM),
        ];
        file_put_contents(
            dirname(__DIR__) . '/var/telegram-last-update.json',
            json_encode($diagnostic, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX,
        );
        Http::json(['ok' => true, 'result' => $bot->handle($update)]);
    }

    Http::json(['ok' => false, 'error' => 'not_found'], 404);
} catch (\RuntimeException $error) {
    $message = $config->get('APP_ENV') === 'production' && $method !== 'POST'
        ? 'Запрос не может быть обработан'
        : $error->getMessage();
    Http::json(['ok' => false, 'error' => $message], 422);
} catch (\Throwable $error) {
    error_log('[prozharka] ' . $error->getMessage());
    Http::json(['ok' => false, 'error' => 'Временная ошибка сервиса'], 500);
}
