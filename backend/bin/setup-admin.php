<?php

declare(strict_types=1);

use Prozharka\SiteContent;

require_once dirname(__DIR__) . '/src/SiteContent.php';
$username = (string) ($argv[1] ?? 'tatyana');
$file = dirname(__DIR__) . '/var/cms-admin.json';
if (is_file($file)) {
    fwrite(STDERR, "Administrator already exists; use the password settings in the editor.\n");
    exit(1);
}
$password = rtrim((string) fgets(STDIN), "\r\n");
if (!preg_match('/^[a-zA-Z0-9_-]{3,40}$/', $username) || strlen($password) < 12 || strlen($password) > 128) {
    fwrite(STDERR, "Invalid username or password length.\n");
    exit(1);
}
SiteContent::writeJson($file, ['username' => $username, 'password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
echo "Administrator created.\n";
