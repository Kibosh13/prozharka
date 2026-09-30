<?php

declare(strict_types=1);

use Prozharka\AccessWorker;
use Prozharka\BotService;
use Prozharka\Config;
use Prozharka\Database;
use Prozharka\PaymentLinkFactory;
use Prozharka\SubscriptionService;
use Prozharka\TelegramClient;

$projectRoot = dirname(__DIR__);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/ProdamusHmac.php';
require_once __DIR__ . '/TelegramClient.php';
require_once __DIR__ . '/PaymentLinkFactory.php';
require_once __DIR__ . '/SubscriptionService.php';
require_once __DIR__ . '/BotService.php';
require_once __DIR__ . '/AccessWorker.php';
require_once __DIR__ . '/Http.php';

$config = Config::load($projectRoot);
date_default_timezone_set($config->get('APP_TIMEZONE', 'Europe/Moscow') ?? 'Europe/Moscow');

$database = new Database($config->require('DATABASE_PATH'));
$database->migrate($projectRoot . '/migrations/001_init.sql');

$paymentLinks = new PaymentLinkFactory($config);
$subscriptions = new SubscriptionService($database, $config, $paymentLinks);
$telegram = new TelegramClient(
    $config->get('TELEGRAM_BOT_TOKEN', '') ?? '',
    $config->bool('DRY_RUN', true),
);
$bot = new BotService($subscriptions, $telegram, $config);
$worker = new AccessWorker($database, $telegram, $subscriptions, $config);

return compact('config', 'database', 'paymentLinks', 'subscriptions', 'telegram', 'bot', 'worker');

