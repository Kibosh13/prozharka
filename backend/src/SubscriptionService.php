<?php

declare(strict_types=1);

namespace Prozharka;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

final class SubscriptionService
{
    public function __construct(
        private readonly Database $database,
        private readonly Config $config,
        private readonly PaymentLinkFactory $paymentLinks,
    ) {
    }

    public function createCheckout(array $input): array
    {
        $customer = $this->validatedCustomer($input);
        if (!$this->paymentLinks->isConfigured()) {
            throw new RuntimeException('Оплата пока не подключена');
        }

        $publicToken = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        $orderId = bin2hex(random_bytes(12));
        $providerOrderId = 'prozharka-' . $orderId;
        $now = $this->now();

        $created = $this->database->transaction(function (PDO $pdo) use (
            $customer,
            $publicToken,
            $orderId,
            $providerOrderId,
            $now,
        ): array {
            $upsert = $pdo->prepare(
                'INSERT INTO customers (full_name, phone, email, telegram_username, created_at, updated_at)
                 VALUES (:full_name, :phone, :email, :telegram_username, :created_at, :updated_at)
                 ON CONFLICT(phone, email) DO UPDATE SET
                    full_name = excluded.full_name,
                    telegram_username = excluded.telegram_username,
                    updated_at = excluded.updated_at'
            );
            $upsert->execute([
                ':full_name' => $customer['full_name'],
                ':phone' => $customer['phone'],
                ':email' => $customer['email'],
                ':telegram_username' => $customer['telegram_username'],
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);

            $lookup = $pdo->prepare('SELECT * FROM customers WHERE phone = :phone AND email = :email LIMIT 1');
            $lookup->execute([':phone' => $customer['phone'], ':email' => $customer['email']]);
            $storedCustomer = $lookup->fetch();
            if (!is_array($storedCustomer)) {
                throw new RuntimeException('Unable to create customer');
            }

            $order = [
                'id' => $orderId,
                'provider_order_id' => $providerOrderId,
                'amount' => (string) $this->config->int('SUBSCRIPTION_PRICE', 4990),
            ];
            $insert = $pdo->prepare(
                'INSERT INTO orders
                 (id, public_token_hash, customer_id, provider_order_id, status, amount, currency, created_at, updated_at)
                 VALUES (:id, :token_hash, :customer_id, :provider_order_id, \'pending\', :amount, \'RUB\', :created_at, :updated_at)'
            );
            $insert->execute([
                ':id' => $orderId,
                ':token_hash' => hash('sha256', $publicToken),
                ':customer_id' => (int) $storedCustomer['id'],
                ':provider_order_id' => $providerOrderId,
                ':amount' => $order['amount'],
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);

            return ['order' => $order, 'customer' => $storedCustomer];
        });

        return [
            'payment_url' => $this->paymentLinks->forOrder($created['order'], $created['customer'], $publicToken),
            'order_token' => $publicToken,
            'status_url' => rtrim($this->config->require('APP_BASE_URL'), '/') . '/orders/' . rawurlencode($publicToken),
        ];
    }

    public function orderStatus(string $publicToken): array
    {
        if ($publicToken === '' || strlen($publicToken) > 200) {
            throw new RuntimeException('Invalid order token');
        }

        $statement = $this->database->pdo()->prepare(
            'SELECT status, invite_link, invite_expires_at, paid_at
             FROM orders WHERE public_token_hash = :token_hash LIMIT 1'
        );
        $statement->execute([':token_hash' => hash('sha256', $publicToken)]);
        $order = $statement->fetch();
        if (!is_array($order)) {
            throw new RuntimeException('Order not found');
        }

        $state = match (true) {
            $order['status'] !== 'paid' => (string) $order['status'],
            !empty($order['invite_link']) => 'ready',
            default => 'preparing_access',
        };

        return [
            'status' => $state,
            'invite_link' => $state === 'ready' ? (string) $order['invite_link'] : null,
            'invite_expires_at' => $state === 'ready' ? $order['invite_expires_at'] : null,
        ];
    }

    public function handleProdamusWebhook(array $payload): string
    {
        $fingerprint = hash('sha256', json_encode($this->sortRecursively($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
        $subscriptionPayload = is_array($payload['subscription'] ?? null) ? $payload['subscription'] : [];
        $providerSubscriptionId = trim((string) ($subscriptionPayload['id'] ?? ''));
        $providerOrderId = trim((string) ($payload['order_id'] ?? $payload['order_num'] ?? ''));
        $paymentStatus = strtolower(trim((string) ($payload['payment_status'] ?? '')));
        $actionCode = strtolower(trim((string) ($subscriptionPayload['action_code'] ?? '')));
        $lastAttempt = strtolower(trim((string) ($subscriptionPayload['last_attempt'] ?? '')));
        $error = trim((string) ($subscriptionPayload['error'] ?? ''));

        return $this->database->transaction(function (PDO $pdo) use (
            $fingerprint,
            $providerSubscriptionId,
            $providerOrderId,
            $paymentStatus,
            $actionCode,
            $lastAttempt,
            $error,
            $subscriptionPayload,
            $payload,
        ): string {
            $order = $this->findOrderForWebhook($pdo, $providerOrderId, $providerSubscriptionId);

            $insertEvent = $pdo->prepare(
                'INSERT OR IGNORE INTO payment_events
                 (fingerprint, provider_order_id, provider_subscription_id, order_id, payment_status, amount, paid_at, received_at)
                 VALUES (:fingerprint, :provider_order_id, :provider_subscription_id, :order_id, :status, :amount, :paid_at, :received_at)'
            );
            $insertEvent->execute([
                ':fingerprint' => $fingerprint,
                ':provider_order_id' => $providerOrderId !== '' ? $providerOrderId : null,
                ':provider_subscription_id' => $providerSubscriptionId !== '' ? $providerSubscriptionId : null,
                ':order_id' => is_array($order) ? $order['id'] : null,
                ':status' => $paymentStatus !== '' ? $paymentStatus : ($actionCode !== '' ? $actionCode : 'unknown'),
                ':amount' => isset($payload['sum']) ? (string) $payload['sum'] : null,
                ':paid_at' => $this->normalizeDate((string) ($payload['date'] ?? $subscriptionPayload['payment_date'] ?? '')),
                ':received_at' => $this->now(),
            ]);
            if ($insertEvent->rowCount() === 0) {
                return 'duplicate';
            }

            if (!is_array($order)) {
                return 'ignored_unknown_order';
            }

            $isSuccess = $paymentStatus === 'success'
                || ($actionCode === 'auto_payment' && ($subscriptionPayload['active'] ?? '1') !== '0' && $error === '');
            if ($isSuccess) {
                $this->activateOrder($pdo, $order, $providerSubscriptionId, $payload, $subscriptionPayload);
                return 'activated_or_renewed';
            }

            $isTerminal = in_array($actionCode, ['deactivation', 'finish'], true)
                || ($lastAttempt === 'yes' && $error !== '');
            if ($isTerminal) {
                $this->markPastDue($pdo, (int) $order['customer_id'], $actionCode === 'finish' ? 'cancelled' : 'past_due');
                return 'marked_for_expiry';
            }

            return 'stored_no_access_change';
        });
    }

    public function rememberTelegramUpdate(int $updateId): bool
    {
        $statement = $this->database->pdo()->prepare(
            'INSERT OR IGNORE INTO telegram_updates (update_id, received_at) VALUES (:update_id, :received_at)'
        );
        $statement->execute([':update_id' => $updateId, ':received_at' => $this->now()]);
        return $statement->rowCount() === 1;
    }

    public function bindJoinedMember(string $inviteLink, array $telegramUser): bool
    {
        $telegramUserId = (int) ($telegramUser['id'] ?? 0);
        if ($telegramUserId <= 0 || $inviteLink === '') {
            return false;
        }

        return $this->database->transaction(function (PDO $pdo) use ($inviteLink, $telegramUser, $telegramUserId): bool {
            $query = $pdo->prepare(
                'SELECT o.id AS order_id, o.customer_id
                 FROM orders o
                 WHERE o.invite_link = :invite_link AND o.status = \'paid\' LIMIT 1'
            );
            $query->execute([':invite_link' => $inviteLink]);
            $order = $query->fetch();
            if (!is_array($order)) {
                return false;
            }

            $now = $this->now();
            $customer = $pdo->prepare(
                'UPDATE customers SET telegram_user_id = :telegram_user_id,
                 private_chat_id = COALESCE(private_chat_id, :private_chat_id),
                 telegram_username = COALESCE(NULLIF(:username, \'\'), telegram_username), updated_at = :updated_at
                 WHERE id = :customer_id'
            );
            $customer->execute([
                ':telegram_user_id' => $telegramUserId,
                ':private_chat_id' => $telegramUserId,
                ':username' => isset($telegramUser['username']) ? '@' . ltrim((string) $telegramUser['username'], '@') : '',
                ':updated_at' => $now,
                ':customer_id' => (int) $order['customer_id'],
            ]);

            $subscription = $pdo->prepare(
                'UPDATE subscriptions SET managed_by_bot = 1, protected_existing = 0,
                 joined_at = COALESCE(joined_at, :joined_at), removed_at = NULL, updated_at = :updated_at
                 WHERE customer_id = :customer_id'
            );
            $subscription->execute([
                ':joined_at' => $now,
                ':updated_at' => $now,
                ':customer_id' => (int) $order['customer_id'],
            ]);
            return true;
        });
    }

    public function scheduleExpiredRemovals(): int
    {
        $cutoff = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->sub(new DateInterval('PT' . max(0, $this->config->int('ACCESS_GRACE_HOURS', 24)) . 'H'))
            ->format(DATE_ATOM);
        $query = $this->database->pdo()->prepare(
            "SELECT s.customer_id
             FROM subscriptions s
             JOIN customers c ON c.id = s.customer_id
             WHERE s.managed_by_bot = 1
               AND s.protected_existing = 0
               AND s.removed_at IS NULL
               AND c.telegram_user_id IS NOT NULL
               AND s.expires_at IS NOT NULL
               AND s.expires_at < :cutoff
               AND s.status IN ('active', 'past_due', 'cancelled', 'expired')"
        );
        $query->execute([':cutoff' => $cutoff]);

        $count = 0;
        foreach ($query->fetchAll() as $row) {
            $this->enqueueUniqueJob($this->database->pdo(), 'remove_member', (int) $row['customer_id'], null);
            $count++;
        }
        return $count;
    }

    private function activateOrder(PDO $pdo, array $order, string $providerSubscriptionId, array $payload, array $subscriptionPayload): void
    {
        $paidAt = $this->normalizeDate((string) ($payload['date'] ?? $subscriptionPayload['date_last_payment'] ?? '')) ?? $this->now();
        $existing = $pdo->prepare('SELECT * FROM subscriptions WHERE customer_id = :customer_id LIMIT 1');
        $existing->execute([':customer_id' => (int) $order['customer_id']]);
        $current = $existing->fetch();
        $expiresAt = $this->futureExpiry($subscriptionPayload, $paidAt, is_array($current) ? ($current['expires_at'] ?? null) : null);
        $now = $this->now();

        $updateOrder = $pdo->prepare(
            'UPDATE orders SET status = \'paid\', provider_subscription_id = COALESCE(NULLIF(:subscription_id, \'\'), provider_subscription_id),
             paid_at = :paid_at, updated_at = :updated_at WHERE id = :id'
        );
        $updateOrder->execute([
            ':subscription_id' => $providerSubscriptionId,
            ':paid_at' => $paidAt,
            ':updated_at' => $now,
            ':id' => $order['id'],
        ]);

        $upsert = $pdo->prepare(
            'INSERT INTO subscriptions
             (customer_id, provider_subscription_id, status, expires_at, last_paid_at, created_at, updated_at)
             VALUES (:customer_id, :subscription_id, \'active\', :expires_at, :last_paid_at, :created_at, :updated_at)
             ON CONFLICT(customer_id) DO UPDATE SET
                provider_subscription_id = COALESCE(NULLIF(excluded.provider_subscription_id, \'\'), subscriptions.provider_subscription_id),
                status = \'active\', expires_at = excluded.expires_at, last_paid_at = excluded.last_paid_at,
                removed_at = NULL, updated_at = excluded.updated_at'
        );
        $upsert->execute([
            ':customer_id' => (int) $order['customer_id'],
            ':subscription_id' => $providerSubscriptionId,
            ':expires_at' => $expiresAt,
            ':last_paid_at' => $paidAt,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        $alreadyManaged = is_array($current) && (int) ($current['managed_by_bot'] ?? 0) === 1 && empty($current['removed_at']);
        if (!$alreadyManaged) {
            $this->enqueueUniqueJob($pdo, 'issue_invite', (int) $order['customer_id'], (string) $order['id']);
        }
    }

    private function findOrderForWebhook(PDO $pdo, string $providerOrderId, string $providerSubscriptionId): ?array
    {
        if ($providerOrderId !== '') {
            $query = $pdo->prepare('SELECT * FROM orders WHERE provider_order_id = :provider_order_id LIMIT 1');
            $query->execute([':provider_order_id' => $providerOrderId]);
            $order = $query->fetch();
            if (is_array($order)) {
                return $order;
            }
        }

        if ($providerSubscriptionId !== '') {
            $query = $pdo->prepare(
                'SELECT o.* FROM orders o
                 JOIN subscriptions s ON s.customer_id = o.customer_id
                 WHERE s.provider_subscription_id = :subscription_id
                 ORDER BY o.created_at DESC LIMIT 1'
            );
            $query->execute([':subscription_id' => $providerSubscriptionId]);
            $order = $query->fetch();
            return is_array($order) ? $order : null;
        }
        return null;
    }

    private function markPastDue(PDO $pdo, int $customerId, string $status): void
    {
        $statement = $pdo->prepare(
            'UPDATE subscriptions SET status = :status, updated_at = :updated_at WHERE customer_id = :customer_id'
        );
        $statement->execute([':status' => $status, ':updated_at' => $this->now(), ':customer_id' => $customerId]);
    }

    private function enqueueUniqueJob(PDO $pdo, string $jobType, int $customerId, ?string $orderId): void
    {
        $exists = $pdo->prepare(
            "SELECT 1 FROM access_jobs
             WHERE job_type = :job_type AND customer_id = :customer_id
               AND ((order_id IS NULL AND :order_id IS NULL) OR order_id = :order_id)
               AND status IN ('pending', 'running')"
        );
        $exists->execute([':job_type' => $jobType, ':customer_id' => $customerId, ':order_id' => $orderId]);
        if ($exists->fetchColumn()) {
            return;
        }

        $now = $this->now();
        $insert = $pdo->prepare(
            'INSERT INTO access_jobs (job_type, customer_id, order_id, status, run_after, created_at, updated_at)
             VALUES (:job_type, :customer_id, :order_id, \'pending\', :run_after, :created_at, :updated_at)'
        );
        $insert->execute([
            ':job_type' => $jobType,
            ':customer_id' => $customerId,
            ':order_id' => $orderId,
            ':run_after' => $now,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }

    private function validatedCustomer(array $input): array
    {
        $fullName = trim((string) ($input['full_name'] ?? ''));
        $phone = preg_replace('/[^0-9+]/', '', (string) ($input['phone'] ?? '')) ?? '';
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $telegram = '@' . ltrim(strtolower(trim((string) ($input['telegram_username'] ?? ''))), '@');

        if (mb_strlen($fullName) < 3 || mb_strlen($fullName) > 150) {
            throw new RuntimeException('Укажите имя и фамилию');
        }
        if (!preg_match('/^\+?[0-9]{10,15}$/', $phone)) {
            throw new RuntimeException('Проверьте номер телефона');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            throw new RuntimeException('Проверьте электронную почту');
        }
        if (!preg_match('/^@[a-z0-9_]{5,32}$/', $telegram)) {
            throw new RuntimeException('Укажите корректный @username в Telegram');
        }

        return [
            'full_name' => $fullName,
            'phone' => $phone,
            'email' => $email,
            'telegram_username' => $telegram,
        ];
    }

    private function futureExpiry(array $subscriptionPayload, string $paidAt, ?string $currentExpiry): string
    {
        $candidate = $this->normalizeDate((string) ($subscriptionPayload['date_next_payment'] ?? ''));
        if ($candidate !== null && new DateTimeImmutable($candidate) > new DateTimeImmutable($paidAt)) {
            return $candidate;
        }

        $base = new DateTimeImmutable($paidAt);
        if ($currentExpiry !== null && new DateTimeImmutable($currentExpiry) > $base) {
            $base = new DateTimeImmutable($currentExpiry);
        }
        return $base->add(new DateInterval('P' . max(1, $this->config->int('SUBSCRIPTION_DAYS', 31)) . 'D'))->format(DATE_ATOM);
    }

    private function normalizeDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($value, new DateTimeZone($this->config->get('APP_TIMEZONE', 'Europe/Moscow') ?? 'Europe/Moscow')))
                ->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
        } catch (\Throwable) {
            return null;
        }
    }

    private function sortRecursively(array $value): array
    {
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortRecursively($item);
            }
        }
        return $value;
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM);
    }
}

