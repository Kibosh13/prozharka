<?php

declare(strict_types=1);

// Beget exposes this small public entry point while the application and its
// secrets remain outside public_html.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', '/home/t/tatyanmb/prozharka.tg.com/public_html/_backend/var/php-error.log');

$backendRoot = '/home/t/tatyanmb/prozharka.tg.com/public_html/_backend';
putenv('PROZHARKA_BACKEND_ROOT=' . $backendRoot);
$_ENV['PROZHARKA_BACKEND_ROOT'] = $backendRoot;
$_SERVER['PROZHARKA_BACKEND_ROOT'] = $backendRoot;

require $backendRoot . '/public/index.php';
