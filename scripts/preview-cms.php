<?php

declare(strict_types=1);

$root = dirname(__DIR__);
putenv('PROZHARKA_BACKEND_ROOT=' . $root . '/backend');
putenv('PROZHARKA_PUBLIC_ROOT=' . $root . '/dist');
$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if ($path === '/admin') {
    header('Location: /admin/');
    return true;
}
if (str_starts_with($path, '/admin/')) {
    if (in_array($path, ['/admin/admin.css', '/admin/admin.js'], true)) {
        header('Content-Type: ' . (str_ends_with($path, '.css') ? 'text/css' : 'text/javascript'));
        readfile($root . '/deploy' . $path);
    } elseif (in_array($path, ['/admin/', '/admin/index.php'], true)) {
        require $root . '/deploy/admin/index.php';
    } else {
        http_response_code(404);
    }
    return true;
}
foreach (ProzharkaPages() as $page => $url) {
    if ($path === $url) {
        $_GET['cms_page'] = $page;
        require $root . '/deploy/beget-site-index.php';
        return true;
    }
}
if ($path === '/robots.txt') {
    require $root . '/deploy/beget-robots.php';
    return true;
}
$file = realpath($root . '/dist' . $path);
if ($file !== false && str_starts_with($file, $root . '/dist/') && is_file($file)) {
    return false;
}
http_response_code(404);
return true;

function ProzharkaPages(): array
{
    return ['main' => '/', 'payment' => '/payment.html', 'privacy' => '/privacy/', 'consent' => '/consent/', 'offer' => '/offer/'];
}
