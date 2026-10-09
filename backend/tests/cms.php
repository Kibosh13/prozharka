<?php

declare(strict_types=1);

use Prozharka\AdminAuth;
use Prozharka\Config;
use Prozharka\SiteContent;

require_once dirname(__DIR__) . '/src/SiteContent.php';
require_once dirname(__DIR__) . '/src/AdminAuth.php';
require_once dirname(__DIR__) . '/src/Config.php';

function cmsExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function cmsReject(callable $action, string $message): void
{
    try {
        $action();
    } catch (RuntimeException) {
        return;
    }
    throw new RuntimeException($message);
}

$temporaryRoot = sys_get_temp_dir() . '/prozharka-cms-tests-' . bin2hex(random_bytes(6));
mkdir($temporaryRoot, 0700);
mkdir($temporaryRoot . '/var', 0700);
$websiteRoot = getenv('PROZHARKA_PUBLIC_ROOT') ?: dirname(__DIR__, 2) . '/dist';
$templates = is_dir(dirname(__DIR__) . '/templates') ? dirname(__DIR__) . '/templates' : $websiteRoot;
$content = new SiteContent($websiteRoot, $temporaryRoot . '/var', $templates);

try {
    $schema = $content->schema();
    $textKey = array_key_first(array_filter($schema, fn(array $field): bool => $field['group'] === 'Обложка' && $field['type'] === 'text'));
    $imageKey = array_key_first(array_filter($schema, fn(array $field): bool => $field['group'] === 'Обложка' && $field['type'] === 'image'));
    cmsExpect($textKey !== null && $imageKey !== null, 'Main page must expose editable text and images');
    cmsExpect(count(array_filter($schema, fn(array $field): bool => $field['type'] === 'image')) === 15, 'All 14 site photographs and the social preview must be editable');
    cmsExpect(count($schema) > 300, 'Landing, payment, cookie and legal copy must be editable');

    $values = [$textKey => "Новый текст\n<script>alert(1)</script>", $imageKey => './assets/stretch.jpg',
        $imageKey . '.position' => '42% 20%', $imageKey . '.fit' => 'contain',
        'main.seo.title' => 'Новый заголовок', 'main.seo.description' => 'Описание проекта',
        'contact.email' => 'editor@example.test', 'contact.phone' => '+7 999 000-00-00',
        'settings.indexing' => '1', 'settings.price' => '5500', 'copy.cookie.title' => '<b>Cookies</b>'];
    $saved = $content->save($values, 'initial');
    cmsExpect($content->read()['revision'] === $saved['revision'], 'Saved version must survive a new read');
    $document = new DOMDocument();
    @$document->loadHTML($content->render('main'));
    $xpath = new DOMXPath($document);
    cmsExpect($document->getElementsByTagName('title')->item(0)->textContent === 'Новый заголовок', 'SEO must be rendered on the server');
    cmsExpect($xpath->query('//meta[@name="robots"]')->item(0)->getAttribute('content') === 'index, follow', 'Production indexing must be configurable');
    cmsExpect($xpath->query('//a[@href="mailto:editor@example.test"]')->length === 1, 'Contact email must become a working link');
    cmsExpect($xpath->query('//*[@data-cms-price]')->item(0)->textContent === '5 500 ₽', 'Visible prices must stay synchronized');
    cmsExpect(!str_contains($content->render('main'), '<script>alert(1)</script>'), 'Edited text must not execute scripts');
    cmsExpect(str_contains($content->render('main'), 'object-position:42% 20%;object-fit:contain;'), 'Both crop controls must be applied together');
    cmsExpect(str_contains($content->render('main'), '\\u003Cb\\u003E'), 'JavaScript copy must be safely encoded');
    cmsExpect(Config::load($temporaryRoot)->int('SUBSCRIPTION_PRICE', 4990) === 5500, 'Checkout amount must use the published price');

    $payment = new DOMDocument();
    @$payment->loadHTML($content->render('payment'));
    cmsExpect(str_contains((new DOMXPath($payment))->query('//meta[@name="robots"]')->item(0)->getAttribute('content'), 'noindex'), 'Payment pages must never become indexable');
    $preview = new DOMDocument();
    @$preview->loadHTML($content->render('main', $values));
    cmsExpect(str_contains((new DOMXPath($preview))->query('//meta[@name="robots"]')->item(0)->getAttribute('content'), 'noindex'), 'Previews must never become indexable');
    cmsExpect($content->read()['revision'] === $saved['revision'], 'Preview must not publish or modify saved content');

    cmsReject(fn() => $content->save([], 'initial'), 'Stale editor must not overwrite a newer revision');
    cmsReject(fn() => $content->validate(['unknown' => 'x']), 'Unknown fields must be rejected');
    cmsReject(fn() => $content->validate(['contact.telegram' => 'javascript:alert(1)']), 'Script URLs must be rejected');
    cmsReject(fn() => $content->validate([$imageKey => '../private/secrets.jpg']), 'Image path traversal must be rejected');
    cmsReject(fn() => $content->validate([$imageKey . '.position' => '50%;background:url(x)']), 'CSS injection must be rejected');
    cmsReject(fn() => $content->validate(['settings.price' => '-1']), 'Invalid checkout amounts must be rejected');
    cmsReject(fn() => $content->restore('../cms-admin', $saved['revision']), 'History path traversal must be rejected');
    $restored = $content->restore('initial', $saved['revision']);
    cmsExpect($restored['values'] === [], 'History must restore the original site');
    cmsExpect(count($content->history()) === 2, 'Previous revision must remain available after restore');

    SiteContent::writeJson($temporaryRoot . '/var/cms-admin.json', ['username' => 'tatyana', 'password_hash' => password_hash('cms-test-password-123', PASSWORD_DEFAULT)]);
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $auth = new AdminAuth($temporaryRoot . '/var');
    $auth->start();
    cmsExpect(!$auth->authenticated(), 'Anonymous visitor must not be an administrator');
    cmsReject(fn() => $auth->checkCsrf(), 'Missing CSRF token must be rejected');
    $_SERVER['HTTP_X_CSRF_TOKEN'] = $auth->csrf();
    $auth->checkCsrf();
    cmsExpect(!$auth->login('tatyana', 'wrong'), 'Wrong password must not authenticate');
    cmsExpect($auth->login('tatyana', 'cms-test-password-123'), 'Correct password must authenticate');
    cmsExpect($auth->authenticated(), 'Admin session must authenticate');
    $oldCsrf = $_SERVER['HTTP_X_CSRF_TOKEN'];
    cmsExpect($oldCsrf !== $auth->csrf(), 'Login must rotate the CSRF token');
    cmsReject(fn() => $auth->changePassword('wrong', 'new-test-password-123'), 'Password changes must verify the current password');
    $auth->changePassword('cms-test-password-123', 'new-test-password-123');
    cmsExpect($auth->authenticated(), 'Current session must survive its own password change');
    $auth->logout();
    cmsExpect(!$auth->authenticated(), 'Logout must invalidate authentication');
    echo "All CMS tests passed\n";
} finally {
    foreach (glob($temporaryRoot . '/var/cms-history/*.json') ?: [] as $file) {
        unlink($file);
    }
    if (is_dir($temporaryRoot . '/var/cms-history')) rmdir($temporaryRoot . '/var/cms-history');
    foreach (glob($temporaryRoot . '/var/*') ?: [] as $file) {
        if (is_file($file)) unlink($file);
    }
    rmdir($temporaryRoot . '/var');
    rmdir($temporaryRoot);
}
