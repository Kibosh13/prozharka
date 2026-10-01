<?php

declare(strict_types=1);

use Prozharka\AccessWorker;
use Prozharka\Config;
use Prozharka\Database;
use Prozharka\PaymentLinkFactory;
use Prozharka\ProdamusHmac;
use Prozharka\SubscriptionService;
use Prozharka\TelegramClient;

require_once dirname(__DIR__) . '/src/Config.php';
require_once dirname(__DIR__) . '/src/Database.php';
require_once dirname(__DIR__) . '/src/ProdamusHmac.php';
require_once dirname(__DIR__) . '/src/TelegramClient.php';
require_once dirname(__DIR__) . '/src/PaymentLinkFactory.php';
require_once dirname(__DIR__) . '/src/SubscriptionService.php';
require_once dirname(__DIR__) . '/src/AccessWorker.php';

final class FakeTelegramClient extends TelegramClient
{
    public array $removed = [];

    public function __construct()
    {
        parent::__construct('test-token', false);
    }

    public function createSingleUseInvite(string $channelId, int $userId, int $expiresAt): string
    {
        return 'https://t.me/+test-' . $userId . '-' . $expiresAt;
    }

    public function removeMember(string $channelId, int $userId): void
    {
        $this->removed[] = [$channelId, $userId];
    }
}

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$databasePath = sys_get_temp_dir() . '/prozharka-tests-' . bin2hex(random_bytes(5)) . '.sqlite';
$configValues = [
    'APP_ENV' => 'test',
    'APP_BASE_URL' => 'https://example.test/api',
    'SITE_BASE_URL' => 'https://example.test',
    'APP_TIMEZONE' => 'Europe/Moscow',
    'DATABASE_PATH' => $databasePath,
    'PRODAMUS_FORM_URL' => 'https://demo.payform.test',
    'PRODAMUS_SECRET_KEY' => 'test-secret',
    'PRODAMUS_SUBSCRIPTION_ID' => '',
    'PRODAMUS_SYSTEM_CODE' => '',
    'SUBSCRIPTION_PRICE' => '4990',
    'SUBSCRIPTION_DAYS' => '30',
    'ACCESS_GRACE_HOURS' => '24',
    'INVITE_LINK_TTL_HOURS' => '24',
    'TELEGRAM_CHANNEL_ID' => '-1001234567890',
    'DRY_RUN' => '0',
    'ACCESS_REMOVAL_ENABLED' => '0',
    'PRIVACY_VERSION' => '30.09.2026',
    'PERSONAL_DATA_CONSENT_VERSION' => '30.09.2026',
    'OFFER_VERSION' => '26.06.2026',
];
$config = new Config($configValues);

try {
    $database = new Database($databasePath);
    foreach (glob(dirname(__DIR__) . '/migrations/*.sql') ?: [] as $migrationFile) {
        $database->migrate($migrationFile);
    }
    $links = new PaymentLinkFactory($config);
    $subscriptions = new SubscriptionService($database, $config, $links);
    $telegram = new FakeTelegramClient();
    $worker = new AccessWorker($database, $telegram, $subscriptions, $config);

    $signatureA = ProdamusHmac::sign(['b' => 2, 'a' => ['z' => 1, 'x' => 3]], 'secret');
    $signatureB = ProdamusHmac::sign(['a' => ['x' => 3, 'z' => 1], 'b' => 2], 'secret');
    expect($signatureA === $signatureB, 'HMAC normalization must be deterministic');

    $customer = [
        'full_name' => 'Анна Иванова',
        'phone' => '+79990001122',
        'email' => 'anna@example.test',
        'telegram_username' => '@anna_test',
        'personal_data_consent' => true,
        'offer_acceptance' => true,
        'cookie_choice' => 'necessary',
        '_consent_ip' => '127.0.0.1',
        '_consent_user_agent' => 'prozharka-tests',
    ];
    $checkout = $subscriptions->createCheckout($customer);
    expect(str_starts_with($checkout['payment_url'], 'https://demo.payform.test/'), 'Checkout must return a Prodamus URL');
    $storedConsent = $database->pdo()->query('SELECT * FROM consent_records LIMIT 1')->fetch();
    expect(is_array($storedConsent), 'Checkout must store consent evidence');
    expect(($storedConsent['privacy_version'] ?? '') === '30.09.2026', 'Consent must store the configured document versions');
    expect(($storedConsent['ip_address'] ?? '') === '127.0.0.1', 'Consent must store the server-supplied IP address');

    parse_str((string) parse_url($checkout['payment_url'], PHP_URL_QUERY), $paymentQuery);
    $providerOrderId = (string) ($paymentQuery['order_id'] ?? '');
    expect(str_starts_with($providerOrderId, 'prozharka-'), 'Provider order id must be generated');
    expect(($paymentQuery['products'][0]['price'] ?? null) === '4990', 'Manual-renewal checkout must contain the configured price');
    expect(($paymentQuery['products'][0]['quantity'] ?? null) === '1', 'Manual-renewal checkout must contain one product');
    expect(!isset($paymentQuery['subscription']), 'Manual-renewal checkout must not require a Prodamus subscription id');

    $webhook = [
        'order_id' => $providerOrderId,
        'payment_status' => 'success',
        'sum' => '4990.00',
        'date' => '2026-09-30 12:00:00',
        'subscription' => [
            'id' => 'sub-100',
            'action_code' => 'auto_payment',
            'active' => '1',
            'date_next_payment' => '2026-10-30 12:00:00',
        ],
    ];
    expect($subscriptions->handleProdamusWebhook($webhook) === 'activated_or_renewed', 'Payment must activate access');
    expect($subscriptions->handleProdamusWebhook($webhook) === 'duplicate', 'Webhook handling must be idempotent');

    $wrongAmountCheckout = $subscriptions->createCheckout([
        'full_name' => 'Мария Петрова',
        'phone' => '+79990002233',
        'email' => 'maria@example.test',
        'telegram_username' => '@maria_test',
        'personal_data_consent' => true,
        'offer_acceptance' => true,
        'cookie_choice' => 'all',
        '_consent_ip' => '127.0.0.2',
        '_consent_user_agent' => 'prozharka-tests',
    ]);
    parse_str((string) parse_url($wrongAmountCheckout['payment_url'], PHP_URL_QUERY), $wrongAmountQuery);
    $wrongAmountWebhook = [
        'order_id' => (string) $wrongAmountQuery['order_id'],
        'payment_status' => 'success',
        'sum' => '49.90',
        'date' => '2026-09-30 12:00:00',
    ];
    expect($subscriptions->handleProdamusWebhook($wrongAmountWebhook) === 'ignored_amount_mismatch', 'Wrong payment amount must not activate access');

    $beforeJoin = $database->pdo()->query('SELECT managed_by_bot FROM subscriptions')->fetchColumn();
    expect((int) $beforeJoin === 0, 'A paid customer is not managed until the unique invite is used');

    $workerResult = $worker->run();
    expect($workerResult['done'] === 1, 'Worker must create one invite');
    $status = $subscriptions->orderStatus($checkout['order_token']);
    expect($status['status'] === 'ready' && str_starts_with((string) $status['invite_link'], 'https://t.me/+test-'), 'Paid order must expose a unique invite');

    expect($subscriptions->bindJoinedMember((string) $status['invite_link'], [
        'id' => 777001,
        'username' => 'anna_test',
    ]), 'Join event must bind the real Telegram user id');
    $managed = $database->pdo()->query('SELECT managed_by_bot FROM subscriptions')->fetchColumn();
    expect((int) $managed === 1, 'Only a member who used the bot invite becomes managed');

    $renewal = $subscriptions->createCheckout($customer);
    parse_str((string) parse_url($renewal['payment_url'], PHP_URL_QUERY), $renewalQuery);
    $renewalWebhook = $webhook;
    $renewalWebhook['order_id'] = (string) $renewalQuery['order_id'];
    $renewalWebhook['date'] = '2026-10-30 12:00:00';
    $renewalWebhook['subscription']['date_next_payment'] = '2026-11-30 12:00:00';
    expect($subscriptions->handleProdamusWebhook($renewalWebhook) === 'activated_or_renewed', 'Renewal must refresh access');
    $pendingInvites = (int) $database->pdo()->query(
        "SELECT COUNT(*) FROM access_jobs WHERE job_type = 'issue_invite' AND status = 'pending'"
    )->fetchColumn();
    expect($pendingInvites === 0, 'Renewal must not touch an active channel member');

    $database->pdo()->exec(
        "UPDATE subscriptions SET protected_existing = 1, expires_at = '2020-01-01T00:00:00+00:00'"
    );
    expect($subscriptions->scheduleExpiredRemovals() === 0, 'Protected existing members must never be scheduled for removal');

    $database->pdo()->exec(
        "UPDATE subscriptions SET protected_existing = 0, expires_at = '2020-01-01T00:00:00+00:00'"
    );
    expect($subscriptions->scheduleExpiredRemovals() === 1, 'Expired managed member must be scheduled once');

    $lastMinuteRenewal = $subscriptions->createCheckout($customer);
    parse_str((string) parse_url($lastMinuteRenewal['payment_url'], PHP_URL_QUERY), $lastMinuteQuery);
    $lastMinuteWebhook = $webhook;
    $lastMinuteWebhook['order_id'] = (string) $lastMinuteQuery['order_id'];
    $lastMinuteWebhook['date'] = '2026-11-30 12:00:00';
    $lastMinuteWebhook['subscription']['date_next_payment'] = '2030-12-30 12:00:00';
    $subscriptions->handleProdamusWebhook($lastMinuteWebhook);

    $enabledConfig = new Config(array_replace($configValues, ['ACCESS_REMOVAL_ENABLED' => '1']));
    $enabledSubscriptions = new SubscriptionService($database, $enabledConfig, new PaymentLinkFactory($enabledConfig));
    $enabledWorker = new AccessWorker($database, $telegram, $enabledSubscriptions, $enabledConfig);
    $enabledWorker->run();
    expect($telegram->removed === [], 'A renewal received before removal must keep the member in the channel');

    $database->pdo()->exec(
        "UPDATE subscriptions SET status = 'past_due', expires_at = '2020-01-01T00:00:00+00:00'"
    );
    expect($enabledSubscriptions->scheduleExpiredRemovals() === 1, 'Unpaid expired member must be scheduled');
    $enabledWorker->run();
    expect(count($telegram->removed) === 1 && $telegram->removed[0][1] === 777001, 'Only the managed unpaid member must be removed');

    echo "All backend tests passed\n";
} finally {
    foreach ([$databasePath, $databasePath . '-shm', $databasePath . '-wal'] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
}
