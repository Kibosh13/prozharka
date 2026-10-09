<?php

declare(strict_types=1);

namespace Prozharka;

use DOMDocument;
use DOMElement;
use DOMText;
use DOMXPath;
use RuntimeException;

final class SiteContent
{
    public const PAGES = [
        'main' => ['file' => 'index.html', 'label' => 'Главная', 'url' => '/'],
        'payment' => ['file' => 'payment.html', 'label' => 'Подтверждение оплаты', 'url' => '/payment.html'],
        'privacy' => ['file' => 'privacy/index.html', 'label' => 'Политика обработки данных', 'url' => '/privacy/'],
        'consent' => ['file' => 'consent/index.html', 'label' => 'Согласие на обработку данных', 'url' => '/consent/'],
        'offer' => ['file' => 'offer/index.html', 'label' => 'Оферта', 'url' => '/offer/'],
    ];

    public const COPY = [
        'cookie.eyebrow' => ['Cookies', 'Конфиденциальность'],
        'cookie.title' => ['Cookies', 'Настройки cookies'],
        'cookie.description' => ['Cookies', 'Сайт использует необходимые cookies для сохранения выбранных настроек. Аналитические cookies могут использоваться только после вашего согласия. Сейчас аналитические сервисы не подключены.'],
        'cookie.privacy' => ['Cookies', 'Политика обработки данных'],
        'cookie.consent' => ['Cookies', 'Согласие на обработку данных'],
        'cookie.offer' => ['Cookies', 'Публичная оферта'],
        'cookie.necessary' => ['Cookies', 'Только необходимые'],
        'cookie.accept' => ['Cookies', 'Принять'],
        'checkout.loading' => ['Оплата · сообщения', 'Готовим оплату…'],
        'checkout.error' => ['Оплата · сообщения', 'Не удалось открыть оплату'],
        'checkout.unavailable' => ['Оплата · сообщения', 'Временная ошибка. Попробуйте ещё раз.'],
        'payment.error_title' => ['Оплата · сообщения', "Нужна\nпроверка."],
        'payment.missing_order' => ['Оплата · сообщения', 'Не найден номер заказа. Вернитесь на сайт и повторите оформление.'],
        'payment.ready_title' => ['Оплата · сообщения', "Добро\nпожаловать."],
        'payment.ready_message' => ['Оплата · сообщения', 'Оплата подтверждена. Персональная ссылка готова — она рассчитана на одного участника.'],
        'payment.preparing_title' => ['Оплата · сообщения', "Оплата\nполучена."],
        'payment.preparing_message' => ['Оплата · сообщения', 'Создаём персональную ссылку в Telegram-канал.'],
        'payment.expired' => ['Оплата · сообщения', 'Срок оплаченного доступа завершён. Для продления оформите участие ещё раз.'],
        'payment.delayed' => ['Оплата · сообщения', 'Оплата получена, но ссылка задерживается. Напишите в поддержку и укажите email из заказа.'],
        'payment.error' => ['Оплата · сообщения', 'Не удалось проверить оплату.'],
    ];

    public function __construct(private readonly string $publicRoot, private readonly string $storageRoot, private readonly ?string $templateRoot = null)
    {
        if (!is_dir($storageRoot) && !mkdir($storageRoot, 0700, true) && !is_dir($storageRoot)) {
            throw new RuntimeException('Не удалось открыть хранилище сайта');
        }
    }

    public function document(string $page): DOMDocument
    {
        if (!isset(self::PAGES[$page])) {
            throw new RuntimeException('Страница не найдена');
        }
        $html = file_get_contents(($this->templateRoot ?? $this->publicRoot) . '/' . self::PAGES[$page]['file']);
        if ($html === false) {
            throw new RuntimeException('Шаблон страницы недоступен');
        }
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET);
        foreach ($document->childNodes as $node) {
            if ($node->nodeType === XML_PI_NODE) {
                $document->removeChild($node);
            }
        }
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return $document;
    }

    public function bindings(DOMDocument $document, string $page): array
    {
        $xpath = new DOMXPath($document);
        $bindings = [];
        $nodes = $xpath->query('//body//text()[normalize-space(.) != "" and not(ancestor::script) and not(ancestor::style)]');
        foreach ($nodes ?: [] as $node) {
            $parent = $node->parentNode;
            if (!$node instanceof DOMText || !$parent instanceof DOMElement
                || $parent->hasAttribute('data-year') || $parent->hasAttribute('data-cms-price')) {
                continue;
            }
            $parentKey = $parent->getAttribute('data-cms-id') ?: substr(hash('sha256', $parent->getNodePath()), 0, 16);
            $index = 0;
            foreach ($parent->childNodes as $sibling) {
                if ($sibling instanceof DOMText && trim($sibling->textContent) !== '') {
                    $index++;
                }
                if ($sibling === $node) {
                    break;
                }
            }
            $key = $page . '.text.' . $parentKey . '.' . $index;
            $default = preg_replace('/\s+/u', ' ', trim($node->textContent)) ?? trim($node->textContent);
            $bindings[$key] = ['type' => 'text', 'default' => $default, 'node' => $node,
                'group' => $this->group($parent, $page), 'label' => $this->label($parent, $default)];
        }

        foreach ($xpath->query('//body//img') ?: [] as $image) {
            $key = $page . '.image.' . ($image->getAttribute('data-cms-id') ?: substr(hash('sha256', $image->getNodePath()), 0, 16));
            $classes = ($image->parentNode instanceof DOMElement) ? $image->parentNode->getAttribute('class') : '';
            $defaultPosition = str_contains($classes, '--gym') ? '50% 38%' : ((str_contains($classes, '--stretch') || str_contains($classes, 'about-portrait')) ? '50% 48%' : (str_contains($classes, 'buy-photo') ? '50% 45%' : '50% 50%'));
            $bindings[$key] = ['type' => 'image', 'default' => $image->getAttribute('src'), 'node' => $image,
                'group' => $this->group($image, $page), 'label' => $image->getAttribute('alt') ?: 'Фотография'];
            $bindings[$key . '.alt'] = ['type' => 'text', 'default' => $image->getAttribute('alt'), 'node' => $image, 'attribute' => 'alt',
                'group' => $this->group($image, $page), 'label' => 'Описание фотографии для поиска'];
            $bindings[$key . '.position'] = ['type' => 'position', 'default' => $defaultPosition, 'node' => $image,
                'group' => $this->group($image, $page), 'label' => 'Положение фотографии'];
            $bindings[$key . '.fit'] = ['type' => 'fit', 'default' => 'cover', 'node' => $image,
                'group' => $this->group($image, $page), 'label' => 'Кадрирование'];
        }

        foreach ($xpath->query('//body//input[@placeholder]') ?: [] as $input) {
            $key = $page . '.placeholder.' . $input->getAttribute('name');
            $bindings[$key] = ['type' => 'text', 'default' => $input->getAttribute('placeholder'), 'node' => $input, 'attribute' => 'placeholder',
                'group' => $this->group($input, $page), 'label' => 'Пример в поле «' . $input->getAttribute('name') . '»'];
        }

        foreach ($xpath->query('//body//a[@href]') ?: [] as $link) {
            $href = $link->getAttribute('href');
            if (!preg_match('~^(https?://|mailto:|tel:)~i', $href)) {
                continue;
            }
            $key = $page . '.link.' . ($link->getAttribute('data-cms-id') ?: substr(hash('sha256', $link->getNodePath()), 0, 16));
            $bindings[$key] = ['type' => 'url', 'default' => $href, 'node' => $link, 'attribute' => 'href',
                'group' => $this->group($link, $page), 'label' => 'Ссылка: ' . trim($link->textContent)];
        }
        return $bindings;
    }

    public function schema(): array
    {
        $fields = [];
        foreach (self::PAGES as $page => $definition) {
            $document = $this->document($page);
            foreach ($this->bindings($document, $page) as $key => $binding) {
                unset($binding['node'], $binding['attribute']);
                $fields[$key] = $binding;
            }
            $title = $document->getElementsByTagName('title')->item(0)?->textContent ?? '';
            $description = (new DOMXPath($document))->query('//meta[@name="description"]')->item(0)?->getAttribute('content') ?? '';
            $fields[$page . '.seo.title'] = ['type' => 'text', 'default' => $title, 'group' => 'SEO', 'label' => $definition['label'] . ' · заголовок в поиске'];
            $fields[$page . '.seo.description'] = ['type' => 'text', 'default' => $description, 'group' => 'SEO', 'label' => $definition['label'] . ' · описание в поиске'];
        }
        $settings = [
            'contact.email' => ['email', 'Контакты', 'Электронная почта', 'Tatyana_galtseva@list.ru'],
            'contact.phone' => ['phone', 'Контакты', 'Телефон', ''],
            'contact.telegram' => ['url', 'Контакты', 'Ссылка на Telegram для связи', ''],
            'contact.address' => ['text', 'Контакты', 'Адрес или город', ''],
            'settings.price' => ['price', 'Участие', 'Стоимость участия, ₽ (меняется также сумма оплаты)', '4990'],
            'settings.indexing' => ['checkbox', 'SEO', 'Разрешить индексацию боевого сайта', '0'],
            'settings.canonical' => ['url', 'SEO', 'Основной адрес сайта', 'https://prozharka-tg.com'],
            'settings.og_image' => ['image', 'SEO', 'Обложка при отправке ссылки в мессенджер', './assets/hero-group.jpg'],
        ];
        foreach ($settings as $key => [$type, $group, $label, $default]) {
            $fields[$key] = compact('type', 'group', 'label', 'default');
        }
        foreach (self::COPY as $key => [$group, $default]) {
            $fields['copy.' . $key] = ['type' => 'text', 'group' => $group, 'label' => mb_substr(str_replace("\n", ' ', $default), 0, 80), 'default' => $default];
        }
        return $fields;
    }

    public function read(): array
    {
        $file = $this->storageRoot . '/site-content.json';
        if (!is_file($file)) {
            return ['revision' => 'initial', 'updated_at' => null, 'values' => []];
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data) || !is_array($data['values'] ?? null)) {
            throw new RuntimeException('Не удалось прочитать сохранённую версию сайта');
        }
        return $data;
    }

    public function validate(array $values): array
    {
        $schema = $this->schema();
        $validated = [];
        foreach ($values as $key => $value) {
            if (!isset($schema[$key]) || !is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > 30000) {
                throw new RuntimeException('Недопустимое поле или слишком длинный текст');
            }
            $type = $schema[$key]['type'];
            $value = str_replace("\r\n", "\n", $value);
            if ($type === 'image' && !preg_match('~^(?:\./|/)?assets/[a-zA-Z0-9_./-]+\.(?:jpg|jpeg|png|webp)(?:\?v=\d+)?$~', $value)) {
                throw new RuntimeException('Для фотографии используйте загрузку файла');
            }
            if ($type === 'image' && str_contains($value, '..')) {
                throw new RuntimeException('Недопустимый путь фотографии');
            }
            if ($type === 'position' && !preg_match('/^(?:100|[0-9]{1,2})% (?:100|[0-9]{1,2})%$/', $value)) {
                throw new RuntimeException('Недопустимое положение фотографии');
            }
            if ($type === 'fit' && !in_array($value, ['cover', 'contain'], true)) {
                throw new RuntimeException('Недопустимый режим кадрирования');
            }
            if ($type === 'url' && $value !== '' && (!preg_match('~^(https?://|mailto:|tel:)~i', $value) || preg_match('/[\x00-\x20<>"\x7f]/', $value))) {
                throw new RuntimeException('Укажите полную ссылку с https://');
            }
            if ($key === 'settings.canonical' && (!filter_var($value, FILTER_VALIDATE_URL) || !str_starts_with($value, 'https://'))) {
                throw new RuntimeException('Основной адрес должен начинаться с https://');
            }
            if ($type === 'email' && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Проверьте электронную почту');
            }
            if ($type === 'phone' && $value !== '' && !preg_match('/^\+?[0-9 ()-]{7,30}$/', $value)) {
                throw new RuntimeException('Проверьте номер телефона');
            }
            if ($type === 'price' && (!ctype_digit($value) || (int) $value < 1 || (int) $value > 1000000)) {
                throw new RuntimeException('Укажите стоимость от 1 до 1 000 000 рублей');
            }
            if ($type === 'checkbox' && !in_array($value, ['0', '1'], true)) {
                throw new RuntimeException('Недопустимое значение переключателя');
            }
            $validated[$key] = $value;
        }
        return $validated;
    }

    public function save(array $values, string $expectedRevision): array
    {
        $values = $this->validate($values);
        $lock = fopen($this->storageRoot . '/site-content.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new RuntimeException('Сохранение временно недоступно');
        }
        try {
            $current = $this->read();
            if (!hash_equals((string) $current['revision'], $expectedRevision)) {
                throw new RuntimeException('Сайт уже изменён в другом окне. Обновите редактор, чтобы не потерять изменения.');
            }
            $historyRoot = $this->storageRoot . '/cms-history';
            if (!is_dir($historyRoot)) {
                mkdir($historyRoot, 0700, true);
            }
            self::writeJson($historyRoot . '/' . $current['revision'] . '.json', $current);
            $next = ['revision' => gmdate('YmdHis') . '-' . bin2hex(random_bytes(4)), 'updated_at' => gmdate(DATE_ATOM), 'values' => $values];
            self::writeJson($this->storageRoot . '/site-content.json', $next);
            $history = glob($historyRoot . '/*.json') ?: [];
            usort($history, fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
            foreach (array_slice($history, 20) as $old) {
                unlink($old);
            }
            return $next;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function history(): array
    {
        $history = [];
        foreach (glob($this->storageRoot . '/cms-history/*.json') ?: [] as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            if (is_array($data)) {
                $history[] = ['revision' => $data['revision'], 'updated_at' => $data['updated_at']];
            }
        }
        usort($history, fn(array $a, array $b): int => strcmp($b['revision'], $a['revision']));
        return $history;
    }

    public function restore(string $revision, string $expectedRevision): array
    {
        if (!preg_match('/^(initial|[0-9]{14}-[a-f0-9]{8})$/', $revision)) {
            throw new RuntimeException('Версия не найдена');
        }
        $file = $this->storageRoot . '/cms-history/' . $revision . '.json';
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!is_array($data)) {
            throw new RuntimeException('Версия не найдена');
        }
        return $this->save($data['values'], $expectedRevision);
    }

    public function upload(array $file): string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ($file['size'] ?? 0) > 10 * 1024 * 1024 || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            throw new RuntimeException('Выберите JPG, PNG или WebP размером до 10 МБ');
        }
        $temporary = (string) $file['tmp_name'];
        $info = @getimagesize($temporary);
        if (!$info || !in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true) || $info[0] * $info[1] > 25000000) {
            throw new RuntimeException('Фотография должна быть JPG, PNG или WebP, до 25 мегапикселей');
        }
        $image = @imagecreatefromstring((string) file_get_contents($temporary));
        if ($image === false) {
            throw new RuntimeException('Не удалось прочитать фотографию');
        }
        if ($info['mime'] === 'image/jpeg' && function_exists('exif_read_data')) {
            $exif = @exif_read_data($temporary);
            $orientation = (int) ($exif['Orientation'] ?? 1);
            if (in_array($orientation, [2, 4, 5, 7], true)) {
                imageflip($image, IMG_FLIP_HORIZONTAL);
            }
            $rotation = match ($orientation) { 3, 4 => 180, 5, 6 => -90, 7, 8 => 90, default => 0 };
            if ($rotation !== 0) {
                $rotated = imagerotate($image, $rotation, 0);
                imagedestroy($image);
                $image = $rotated;
            }
        }
        $scale = min(1, 2560 / max(imagesx($image), imagesy($image)));
        $width = (int) round(imagesx($image) * $scale);
        $height = (int) round(imagesy($image) * $scale);
        $resized = imagecreatetruecolor($width, $height);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image));
        $directory = $this->publicRoot . '/assets/uploads';
        if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
            throw new RuntimeException('Загрузка фотографий временно недоступна');
        }
        $filename = bin2hex(random_bytes(16)) . '.webp';
        $saved = imagewebp($resized, $directory . '/' . $filename, 88);
        imagedestroy($image);
        imagedestroy($resized);
        if (!$saved) {
            throw new RuntimeException('Не удалось сохранить фотографию');
        }
        chmod($directory . '/' . $filename, 0644);
        return './assets/uploads/' . $filename;
    }

    public function render(string $page, ?array $previewValues = null): string
    {
        $values = $previewValues ?? $this->read()['values'];
        $document = $this->document($page);
        foreach ($this->bindings($document, $page) as $key => $binding) {
            if (!array_key_exists($key, $values)) {
                continue;
            }
            $node = $binding['node'];
            $value = $values[$key];
            if ($binding['type'] === 'position') {
                $node->setAttribute('style', $node->getAttribute('style') . 'object-position:' . $value . ';');
            } elseif ($binding['type'] === 'fit') {
                $node->setAttribute('style', $node->getAttribute('style') . 'object-fit:' . $value . ';');
            } elseif ($binding['type'] === 'image') {
                $node->setAttribute('src', $value);
            } elseif (isset($binding['attribute'])) {
                $node->setAttribute($binding['attribute'], $value);
            } else {
                $fragment = $document->createDocumentFragment();
                $lines = explode("\n", $value);
                foreach ($lines as $index => $line) {
                    if ($index > 0) {
                        $fragment->appendChild($document->createElement('br'));
                    }
                    $fragment->appendChild($document->createTextNode(($index === 0 ? ' ' : '') . $line . ($index === count($lines) - 1 ? ' ' : '')));
                }
                $node->parentNode->replaceChild($fragment, $node);
            }
        }
        $xpath = new DOMXPath($document);
        $price = number_format((int) ($values['settings.price'] ?? '4990'), 0, '.', ' ') . ' ₽';
        foreach ($xpath->query('//*[@data-cms-price]') ?: [] as $node) {
            $node->textContent = $price;
        }
        $head = $document->getElementsByTagName('head')->item(0);
        $title = $values[$page . '.seo.title'] ?? $document->getElementsByTagName('title')->item(0)?->textContent ?? '';
        $document->getElementsByTagName('title')->item(0)->textContent = $title;
        $description = $values[$page . '.seo.description'] ?? ($xpath->query('//meta[@name="description"]')->item(0)?->getAttribute('content') ?? '');
        $canonicalBase = rtrim($values['settings.canonical'] ?? 'https://prozharka-tg.com', '/');
        $canonical = $canonicalBase . self::PAGES[$page]['url'];
        $indexing = ($values['settings.indexing'] ?? '0') === '1' && $page !== 'payment' && $previewValues === null;
        foreach (['robots', 'googlebot'] as $name) {
            $this->meta($document, $head, $name, $indexing ? 'index, follow' : 'noindex, nofollow, noarchive');
        }
        $this->meta($document, $head, 'description', $description);
        $this->meta($document, $head, 'og:title', $title, true);
        $this->meta($document, $head, 'og:description', $description, true);
        $this->meta($document, $head, 'og:url', $canonical, true);
        $this->meta($document, $head, 'og:type', 'website', true);
        $this->meta($document, $head, 'og:image', $canonicalBase . '/' . ltrim($values['settings.og_image'] ?? './assets/hero-group.jpg', './'), true);
        $canonicalNode = $document->createElement('link');
        $canonicalNode->setAttribute('rel', 'canonical');
        $canonicalNode->setAttribute('href', $canonical);
        $head->appendChild($canonicalNode);

        $body = $document->getElementsByTagName('body')->item(0);
        $copy = [];
        foreach (self::COPY as $key => [, $default]) {
            $copy[$key] = $values['copy.' . $key] ?? $default;
        }
        $configNode = $document->createElement('script');
        $configNode->setAttribute('type', 'application/json');
        $configNode->setAttribute('id', 'site-copy');
        $copyJson = (string) json_encode($copy, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
        $configNode->appendChild($document->createTextNode('__PROZHARKA_SITE_COPY_JSON__'));
        $body->insertBefore($configNode, $body->firstChild);
        if ($page === 'main') {
            $contacts = $xpath->query('//*[@data-cms-contacts]')->item(0);
            foreach (['email', 'phone', 'telegram', 'address'] as $type) {
                $value = trim($values['contact.' . $type] ?? ($type === 'email' ? 'Tatyana_galtseva@list.ru' : ''));
                if ($value === '' || $contacts === null) {
                    continue;
                }
                $element = $document->createElement($type === 'address' ? 'span' : 'a');
                $element->textContent = $type === 'telegram' ? 'Telegram' : $value;
                if ($type !== 'address') {
                    $element->setAttribute('href', match ($type) { 'email' => 'mailto:' . $value, 'phone' => 'tel:' . preg_replace('/[^+0-9]/', '', $value), default => $value });
                }
                $contacts->appendChild($element);
            }
        }
        $html = mb_decode_numericentity($document->saveHTML() ?: '', [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8');
        return str_replace('<script type="application/json" id="site-copy">__PROZHARKA_SITE_COPY_JSON__</script>',
            '<script type="application/json" id="site-copy">' . $copyJson . '</script>', $html);
    }

    private function group(DOMElement $node, string $page): string
    {
        if ($page !== 'main') {
            return self::PAGES[$page]['label'];
        }
        $groups = ['hero' => 'Обложка', 'statement' => 'Подход', 'inside' => 'Что внутри', 'library' => 'Библиотека', 'results' => 'Результаты', 'about' => 'О Татьяне', 'faq' => 'Вопросы', 'buy' => 'Участие', 'site-header' => 'Меню', 'footer' => 'Подвал'];
        for ($parent = $node; $parent instanceof DOMElement; $parent = $parent->parentNode) {
            foreach (explode(' ', $parent->getAttribute('class')) as $class) {
                if (isset($groups[$class])) {
                    return $groups[$class];
                }
            }
        }
        return 'Общие тексты';
    }

    private function label(DOMElement $node, string $text): string
    {
        $type = match ($node->tagName) { 'h1', 'h2', 'h3' => 'Заголовок', 'a', 'button' => 'Кнопка / ссылка', 'summary' => 'Вопрос', 'p' => 'Текст', default => 'Подпись' };
        return $type . ' · ' . mb_substr($text, 0, 75);
    }

    private function meta(DOMDocument $document, DOMElement $head, string $name, string $value, bool $property = false): void
    {
        $attribute = $property ? 'property' : 'name';
        $node = (new DOMXPath($document))->query('//meta[@' . $attribute . '="' . $name . '"]')->item(0);
        if (!$node instanceof DOMElement) {
            $node = $document->createElement('meta');
            $node->setAttribute($attribute, $name);
            $head->appendChild($node);
        }
        $node->setAttribute('content', $value);
    }

    public static function writeJson(string $path, array $data): void
    {
        $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        if (file_put_contents($temporary, $encoded, LOCK_EX) === false) {
            throw new RuntimeException('Не удалось сохранить файл');
        }
        chmod($temporary, 0600);
        if (!rename($temporary, $path)) {
            unlink($temporary);
            throw new RuntimeException('Не удалось опубликовать изменения');
        }
    }
}
