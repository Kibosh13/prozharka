<?php

declare(strict_types=1);

namespace Prozharka;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

final class AccessWorker
{
    public function __construct(
        private readonly Database $database,
        private readonly TelegramClient $telegram,
        private readonly SubscriptionService $subscriptions,
        private readonly Config $config,
    ) {
    }

    public function run(int $limit = 20): array
    {
        $scheduled = $this->subscriptions->scheduleExpiredRemovals();
        $done = 0;
        $failed = 0;
        $deferred = 0;

        for ($index = 0; $index < max(1, $limit); $index++) {
            $job = $this->claimJob();
            if ($job === null) {
                break;
            }

            try {
                $result = match ($job['job_type']) {
                    'issue_invite' => $this->issueInvite($job),
                    'remove_member' => $this->removeMember($job),
                    default => throw new RuntimeException('Unknown job type'),
                };
                if ($result === 'deferred') {
                    $this->defer((int) $job['id'], 'Removal safety switch is disabled');
                    $deferred++;
                } else {
                    $this->complete((int) $job['id']);
                    $done++;
                }
            } catch (\Throwable $error) {
                $this->fail($job, $error->getMessage());
                $failed++;
            }
        }

        return compact('scheduled', 'done', 'failed', 'deferred');
    }

    private function claimJob(): ?array
    {
        return $this->database->transaction(function (PDO $pdo): ?array {
            $query = $pdo->prepare(
                "SELECT * FROM access_jobs
                 WHERE status = 'pending' AND run_after <= :now
                 ORDER BY id ASC LIMIT 1"
            );
            $query->execute([':now' => $this->now()]);
            $job = $query->fetch();
            if (!is_array($job)) {
                return null;
            }

            $update = $pdo->prepare(
                "UPDATE access_jobs SET status = 'running', attempts = attempts + 1, updated_at = :updated_at
                 WHERE id = :id AND status = 'pending'"
            );
            $update->execute([':updated_at' => $this->now(), ':id' => $job['id']]);
            if ($update->rowCount() !== 1) {
                return null;
            }
            $job['attempts'] = (int) $job['attempts'] + 1;
            return $job;
        });
    }

    private function issueInvite(array $job): string
    {
        if (empty($job['order_id'])) {
            throw new RuntimeException('Invite job has no order');
        }

        $query = $this->database->pdo()->prepare(
            'SELECT o.*, c.telegram_user_id
             FROM orders o JOIN customers c ON c.id = o.customer_id
             WHERE o.id = :order_id AND o.customer_id = :customer_id LIMIT 1'
        );
        $query->execute([':order_id' => $job['order_id'], ':customer_id' => $job['customer_id']]);
        $order = $query->fetch();
        if (!is_array($order) || $order['status'] !== 'paid') {
            throw new RuntimeException('Paid order not found for invite');
        }
        if (!empty($order['invite_link'])) {
            return 'done';
        }

        $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->add(new DateInterval('PT' . max(1, $this->config->int('INVITE_LINK_TTL_HOURS', 24)) . 'H'));
        $invite = $this->telegram->createSingleUseInvite(
            $this->config->require('TELEGRAM_CHANNEL_ID'),
            (int) $job['customer_id'],
            $expiresAt->getTimestamp(),
        );

        $update = $this->database->pdo()->prepare(
            'UPDATE orders SET invite_link = :invite_link, invite_created_at = :created_at,
             invite_expires_at = :expires_at, updated_at = :updated_at WHERE id = :id'
        );
        $update->execute([
            ':invite_link' => $invite,
            ':created_at' => $this->now(),
            ':expires_at' => $expiresAt->format(DATE_ATOM),
            ':updated_at' => $this->now(),
            ':id' => $order['id'],
        ]);
        return 'done';
    }

    private function removeMember(array $job): string
    {
        if (!$this->config->bool('ACCESS_REMOVAL_ENABLED') || $this->config->bool('DRY_RUN', true)) {
            return 'deferred';
        }

        $query = $this->database->pdo()->prepare(
            'SELECT s.*, c.telegram_user_id
             FROM subscriptions s JOIN customers c ON c.id = s.customer_id
             WHERE s.customer_id = :customer_id
               AND s.managed_by_bot = 1
               AND s.protected_existing = 0
               AND s.removed_at IS NULL
               AND c.telegram_user_id IS NOT NULL
             LIMIT 1'
        );
        $query->execute([':customer_id' => $job['customer_id']]);
        $subscription = $query->fetch();
        if (!is_array($subscription)) {
            return 'done';
        }

        $expiresAt = new DateTimeImmutable((string) $subscription['expires_at']);
        $cutoff = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->sub(new DateInterval('PT' . max(0, $this->config->int('ACCESS_GRACE_HOURS', 24)) . 'H'));
        if ($expiresAt >= $cutoff || $subscription['status'] === 'active' && $expiresAt > new DateTimeImmutable('now')) {
            return 'done';
        }

        $this->telegram->removeMember(
            $this->config->require('TELEGRAM_CHANNEL_ID'),
            (int) $subscription['telegram_user_id'],
        );
        $update = $this->database->pdo()->prepare(
            "UPDATE subscriptions SET status = 'expired', removed_at = :removed_at, updated_at = :updated_at
             WHERE customer_id = :customer_id AND managed_by_bot = 1 AND protected_existing = 0"
        );
        $update->execute([
            ':removed_at' => $this->now(),
            ':updated_at' => $this->now(),
            ':customer_id' => $job['customer_id'],
        ]);
        return 'done';
    }

    private function complete(int $jobId): void
    {
        $statement = $this->database->pdo()->prepare(
            "UPDATE access_jobs SET status = 'done', last_error = NULL, updated_at = :updated_at WHERE id = :id"
        );
        $statement->execute([':updated_at' => $this->now(), ':id' => $jobId]);
    }

    private function defer(int $jobId, string $reason): void
    {
        $next = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->add(new DateInterval('PT1H'))->format(DATE_ATOM);
        $statement = $this->database->pdo()->prepare(
            "UPDATE access_jobs SET status = 'pending', run_after = :run_after, last_error = :last_error,
             updated_at = :updated_at WHERE id = :id"
        );
        $statement->execute([
            ':run_after' => $next,
            ':last_error' => mb_substr($reason, 0, 500),
            ':updated_at' => $this->now(),
            ':id' => $jobId,
        ]);
    }

    private function fail(array $job, string $message): void
    {
        $attempts = (int) ($job['attempts'] ?? 1);
        $status = $attempts >= 8 ? 'dead' : 'pending';
        $delayMinutes = min(360, 2 ** min($attempts, 8));
        $next = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->add(new DateInterval('PT' . $delayMinutes . 'M'))->format(DATE_ATOM);
        $statement = $this->database->pdo()->prepare(
            'UPDATE access_jobs SET status = :status, run_after = :run_after, last_error = :last_error,
             updated_at = :updated_at WHERE id = :id'
        );
        $statement->execute([
            ':status' => $status,
            ':run_after' => $next,
            ':last_error' => mb_substr($message, 0, 500),
            ':updated_at' => $this->now(),
            ':id' => $job['id'],
        ]);
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM);
    }
}

