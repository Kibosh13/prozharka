<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/backend/src/SiteContent.php';
$root = dirname(__DIR__);
$content = new Prozharka\SiteContent($root . '/dist', $root . '/backend/var');
foreach (Prozharka\SiteContent::PAGES as $page => $definition) {
    $document = $content->document($page);
    foreach ($content->bindings($document, $page) as $binding) {
        $node = $binding['node'];
        $element = $node instanceof DOMText ? $node->parentNode : $node;
        if ($element instanceof DOMElement && !$element->hasAttribute('data-cms-id')) {
            $element->setAttribute('data-cms-id', substr(hash('sha256', $element->getNodePath()), 0, 16));
        }
    }
    file_put_contents($root . '/dist/' . $definition['file'], mb_decode_numericentity($document->saveHTML(), [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8'));
}
