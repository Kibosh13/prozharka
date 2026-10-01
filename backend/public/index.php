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
        Http::json(['ok' => true] + $subscriptions->createCheckout(Http::jsonBody()), 201);
    }

    if ($method === 'GET' && preg_match('#^/orders/([A-Za-z0-9_-]{20,200})$#', $path, $matches)) {
        Http::json(['ok' => true] + $subscriptions->orderStatus($matches[1]));
    }

    if ($method === 'POST' && $path === '/webhooks/prodamus') {
        $payload = $_POST;
        $signature = Http::header('Sign');
        if (empty($payload) || !ProdamusHmac::verify($payload, $config->require('PRODAMUS_SECRET_KEY'), $signature)) {
            Http::json(['ok' => false, 'error' => 'invalid_signature'], 401);
        }
        Http::json(['ok' => true, 'result' => $subscriptions->handleProdamusWebhook($payload)]);
    }

    if ($method === 'POST' && $path === '/webhooks/telegram') {
        $expected = $config->require('TELEGRAM_WEBHOOK_SECRET');
        $actual = Http::header('X-Telegram-Bot-Api-Secret-Token');
        if ($actual === '' || !hash_equals($expected, $actual)) {
            Http::json(['ok' => false, 'error' => 'invalid_secret'], 401);
        }
        $update = Http::jsonBody();
        if (trim((string) $config->get('TELEGRAM_CHANNEL_ID', '')) === '') {
            $chat = $update['channel_post']['chat']
                ?? $update['my_chat_member']['chat']
                ?? $update['chat_member']['chat']
                ?? $update['message']['forward_origin']['chat']
                ?? $update['message']['forward_from_chat']
                ?? null;
            if (is_array($chat) && in_array((string) ($chat['type'] ?? ''), ['channel', 'supergroup'], true)) {
                $candidate = [
                    'id' => (string) ($chat['id'] ?? ''),
                    'type' => (string) ($chat['type'] ?? ''),
                    'title' => (string) ($chat['title'] ?? ''),
                    'seen_at' => gmdate(DATE_ATOM),
                ];
                if ($candidate['id'] !== '') {
                    file_put_contents(
                        dirname(__DIR__) . '/var/channel-discovery.json',
                        json_encode($candidate, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        LOCK_EX,
                    );
                }
            }
        }
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
