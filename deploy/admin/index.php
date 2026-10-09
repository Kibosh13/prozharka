<?php

declare(strict_types=1);

use Prozharka\AdminAuth;
use Prozharka\SiteContent;

ini_set('display_errors', '0');
$publicRoot = getenv('PROZHARKA_PUBLIC_ROOT') ?: dirname(__DIR__);
$backendRoot = getenv('PROZHARKA_BACKEND_ROOT') ?: $publicRoot . '/_backend';
require_once $backendRoot . '/src/SiteContent.php';
require_once $backendRoot . '/src/AdminAuth.php';
require_once $backendRoot . '/src/Http.php';

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; style-src 'self'; script-src 'self'; frame-src 'self'; connect-src 'self'; form-action 'self'; base-uri 'self'; frame-ancestors 'self'");

$content = new SiteContent($publicRoot, $backendRoot . '/var', is_dir($backendRoot . '/templates') ? $backendRoot . '/templates' : null);
$auth = new AdminAuth($backendRoot . '/var');
$auth->start();
$action = (string) ($_GET['action'] ?? '');
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');

try {
    if ($action === 'session' && $method === 'GET') {
        $authenticated = $auth->authenticated();
        if ($authenticated) {
            $auth->touch();
        }
        Prozharka\Http::json(['ok' => true, 'authenticated' => $authenticated, 'csrf' => $auth->csrf()]);
    }

    if ($method === 'POST') {
        $auth->checkCsrf();
        if ($action !== 'upload' && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 1500000) {
            Prozharka\Http::json(['ok' => false, 'error' => 'Слишком большой запрос'], 413);
        }
        $input = Prozharka\Http::jsonBody();
        if ($action === 'login') {
            if (!$auth->login((string) ($input['username'] ?? ''), (string) ($input['password'] ?? ''))) {
                Prozharka\Http::json(['ok' => false, 'error' => 'Неверный логин или пароль'], 401);
            }
            Prozharka\Http::json(['ok' => true, 'csrf' => $auth->csrf()]);
        }
    }

    if ($action !== '' && !$auth->authenticated()) {
        Prozharka\Http::json(['ok' => false, 'error' => 'Войдите в редактор сайта'], 401);
    }
    if ($auth->authenticated()) {
        $auth->touch();
    }

    if ($action === 'content' && $method === 'GET') {
        Prozharka\Http::json(['ok' => true, 'schema' => $content->schema(), 'content' => $content->read(),
            'history' => $content->history(), 'pages' => SiteContent::PAGES]);
    }
    if ($action === 'save' && $method === 'POST') {
        $next = $content->save((array) ($input['values'] ?? []), (string) ($input['revision'] ?? ''));
        unset($_SESSION['cms_preview']);
        Prozharka\Http::json(['ok' => true, 'content' => $next, 'history' => $content->history()]);
    }
    if ($action === 'upload' && $method === 'POST') {
        Prozharka\Http::json(['ok' => true, 'path' => $content->upload($_FILES['image'] ?? [])]);
    }
    if ($action === 'prepare-preview' && $method === 'POST') {
        $_SESSION['cms_preview'] = $content->validate((array) ($input['values'] ?? []));
        Prozharka\Http::json(['ok' => true, 'url' => './?action=preview&page=main']);
    }
    if ($action === 'preview' && $method === 'GET') {
        $page = (string) ($_GET['page'] ?? 'main');
        if (!isset(SiteContent::PAGES[$page])) {
            throw new RuntimeException('Страница не найдена');
        }
        $values = $_SESSION['cms_preview'] ?? $content->read()['values'];
        session_write_close();
        $html = $content->render($page, $values);
        $base = $page === 'main' || $page === 'payment' ? '/' : '/' . $page . '/';
        $html = str_replace('<head>', '<head><base href="' . $base . '">', $html);
        // A preview must not create a real order while editing the landing page.
        $html = str_replace('data-checkout-form', 'data-checkout-preview', $html);
        $html = str_replace('<form class="checkout-form"', '<form onsubmit="return false" class="checkout-form"', $html);
        header('Content-Type: text/html; charset=utf-8');
        header_remove('Content-Security-Policy');
        echo $html;
        exit;
    }
    if ($action === 'restore' && $method === 'POST') {
        $next = $content->restore((string) ($input['target'] ?? ''), (string) ($input['revision'] ?? ''));
        Prozharka\Http::json(['ok' => true, 'content' => $next, 'history' => $content->history()]);
    }
    if ($action === 'password' && $method === 'POST') {
        $auth->changePassword((string) ($input['current'] ?? ''), (string) ($input['password'] ?? ''));
        Prozharka\Http::json(['ok' => true]);
    }
    if ($action === 'logout' && $method === 'POST') {
        $auth->logout();
        Prozharka\Http::json(['ok' => true]);
    }
    if ($action !== '') {
        Prozharka\Http::json(['ok' => false, 'error' => 'Действие не найдено'], 404);
    }
} catch (RuntimeException $error) {
    Prozharka\Http::json(['ok' => false, 'error' => $error->getMessage()], 422);
} catch (Throwable $error) {
    error_log('[prozharka-admin] ' . $error->getMessage());
    Prozharka\Http::json(['ok' => false, 'error' => 'Не удалось выполнить действие. Пожалуйста, повторите.'], 500);
}
session_write_close();
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="ru">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="robots" content="noindex, nofollow" />
    <title>Редактор сайта — Про жарка</title>
    <link rel="stylesheet" href="./admin.css?v=1" />
    <script src="./admin.js?v=1" defer></script>
  </head>
  <body>
    <main class="login-shell" id="login-view" hidden>
      <form class="login-card" id="login-form">
        <a href="/" class="wordmark">Про жарка</a>
        <p class="eyebrow">Редактор сайта</p>
        <h1>Всё под рукой.</h1>
        <p class="muted">Тексты, фотографии и настройки вашего сайта.</p>
        <label>Логин<input name="username" autocomplete="username" required /></label>
        <label>Пароль<input name="password" type="password" autocomplete="current-password" required /></label>
        <button class="button primary" type="submit">Войти</button>
        <p class="form-message" id="login-message" role="status"></p>
      </form>
    </main>

    <div id="editor-view" hidden>
      <header class="editor-header">
        <a href="/" class="wordmark" target="_blank" rel="noopener">Про жарка <span>редактор</span></a>
        <div class="header-actions">
          <a href="/" target="_blank" rel="noopener" class="button subtle">Открыть сайт ↗</a>
          <button type="button" class="button subtle" id="logout-button">Выйти</button>
        </div>
      </header>
      <div class="editor-layout">
        <aside class="sidebar">
          <p class="eyebrow">Разделы сайта</p>
          <nav id="section-nav" aria-label="Разделы редактора"></nav>
          <div class="sidebar-note">Выберите раздел, внесите изменения и нажмите «Сохранить на сайте».</div>
        </aside>
        <main class="editor-main">
          <div class="section-intro">
            <div><p class="eyebrow">Содержание сайта</p><h1 id="section-title">Обложка</h1></div>
            <span id="save-state" class="save-state">Без изменений</span>
          </div>
          <p class="muted section-description" id="section-description"></p>
          <form id="content-form"><div id="fields"></div></form>
          <section id="history-panel" hidden>
            <p class="muted">Здесь хранятся 20 предыдущих версий. Восстановление сразу обновляет сайт.</p>
            <div id="history-list"></div>
          </section>
          <form class="panel password-form" id="password-form" hidden>
            <h2>Сменить пароль</h2>
            <label>Текущий пароль<input name="current" type="password" autocomplete="current-password" required /></label>
            <label>Новый пароль<input name="password" type="password" autocomplete="new-password" minlength="12" maxlength="128" required /></label>
            <label>Повторите новый пароль<input name="confirmation" type="password" autocomplete="new-password" minlength="12" required /></label>
            <button class="button primary" type="submit">Сменить пароль</button>
            <p class="muted">От 12 символов. После смены пароля остальные сессии редактора завершатся.</p>
          </form>
        </main>
      </div>
      <footer class="save-bar" id="save-bar">
        <p id="editor-message" role="status">Изменения появятся после сохранения.</p>
        <div><button type="button" class="button secondary" id="preview-button">Предпросмотр</button><button type="button" class="button primary" id="save-button">Сохранить на сайте</button></div>
      </footer>
    </div>

    <dialog id="preview-dialog" class="preview-dialog">
      <header><h2>Предпросмотр</h2><select id="preview-page" aria-label="Страница предпросмотра"></select><button type="button" class="button subtle" id="preview-width">Телефон</button><button type="button" class="button subtle" id="preview-close">Закрыть</button></header>
      <div class="preview-stage"><iframe id="preview-frame" title="Предпросмотр сайта" sandbox="allow-same-origin allow-scripts allow-popups"></iframe></div>
    </dialog>
    <div class="boot-message" id="boot-message" role="status">Открываем редактор…</div>
  </body>
</html>
