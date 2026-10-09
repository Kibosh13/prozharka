<?php

declare(strict_types=1);

ini_set('display_errors', '0');
$backendRoot = getenv('PROZHARKA_BACKEND_ROOT') ?: __DIR__ . '/_backend';
$publicRoot = getenv('PROZHARKA_PUBLIC_ROOT') ?: __DIR__;
require_once $backendRoot . '/src/SiteContent.php';

try {
    $content = new Prozharka\SiteContent($publicRoot, $backendRoot . '/var', is_dir($backendRoot . '/templates') ? $backendRoot . '/templates' : null);
    $page = (string) ($_GET['cms_page'] ?? 'main');
    if (!isset(Prozharka\SiteContent::PAGES[$page])) {
        http_response_code(404);
        exit('Страница не найдена');
    }
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    echo $content->render($page);
} catch (Throwable $error) {
    error_log('[prozharka-cms] ' . $error->getMessage());
    http_response_code(503);
    echo 'Сайт временно недоступен. Пожалуйста, обновите страницу.';
}
