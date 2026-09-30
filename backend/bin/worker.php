<?php

declare(strict_types=1);

$container = require dirname(__DIR__) . '/src/bootstrap.php';
$result = $container['worker']->run(50);
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

