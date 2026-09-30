<?php

declare(strict_types=1);

namespace Prozharka;

final class BotService
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly TelegramClient $telegram,
        private readonly Config $config,
    ) {
    }

    public function handle(array $update): string
    {
        $updateId = (int) ($update['update_id'] ?? 0);
        if ($updateId <= 0 || !$this->subscriptions->rememberTelegramUpdate($updateId)) {
            return 'duplicate_or_invalid';
        }

        if (isset($update['chat_member']) && is_array($update['chat_member'])) {
            return $this->handleMembershipChange($update['chat_member']);
        }

        $message = $update['message'] ?? null;
        if (is_array($message) && str_starts_with(trim((string) ($message['text'] ?? '')), '/start')) {
            $chatId = (int) ($message['chat']['id'] ?? 0);
            if ($chatId > 0) {
                $this->telegram->sendMessage(
                    $chatId,
                    'Оплата подписки оформляется на сайте «Прожарка». После успешной оплаты персональная ссылка появится на странице подтверждения.'
                );
            }
            return 'start_answered';
        }

        return 'ignored';
    }

    private function handleMembershipChange(array $change): string
    {
        $configuredChannel = (string) $this->config->require('TELEGRAM_CHANNEL_ID');
        $actualChannel = (string) ($change['chat']['id'] ?? '');
        if ($actualChannel !== $configuredChannel) {
            return 'ignored_other_chat';
        }

        $newMember = $change['new_chat_member'] ?? null;
        $status = is_array($newMember) ? (string) ($newMember['status'] ?? '') : '';
        if (!in_array($status, ['member', 'administrator', 'creator'], true)) {
            return 'ignored_non_join';
        }

        $user = is_array($newMember['user'] ?? null) ? $newMember['user'] : [];
        $inviteLink = (string) ($change['invite_link']['invite_link'] ?? '');
        if ($inviteLink === '') {
            return 'ignored_without_managed_invite';
        }

        return $this->subscriptions->bindJoinedMember($inviteLink, $user)
            ? 'joined_and_bound'
            : 'ignored_unknown_invite';
    }
}

