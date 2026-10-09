<?php

declare(strict_types=1);

$backendRoot = getenv('PROZHARKA_BACKEND_ROOT') ?: __DIR__ . '/_backend';
require_once $backendRoot . '/src/SiteContent.php';
$content = new Prozharka\SiteContent(__DIR__, $backendRoot . '/var');
$values = $content->read()['values'];
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
echo "User-agent: *\n";
if (($values['settings.indexing'] ?? '0') !== '1') {
    echo "Disallow: /\n";
} else {
    echo "Allow: /\nDisallow: /admin/\nDisallow: /api/\nDisallow: /_backend/\nDisallow: /payment.html\n";
}
