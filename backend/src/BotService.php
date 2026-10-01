<?php

declare(strict_types=1);

namespace Prozharka;

use RuntimeException;

final class BotService
{
    private const GROUP_REQUEST_ID = 71001;
    private const CHANNEL_REQUEST_ID = 71002;

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

        $message = is_array($update['message'] ?? null)
            ? $update['message']
            : (is_array($update['channel_post'] ?? null) ? $update['channel_post'] : null);
        if (is_array($message) && is_array($message['chat_shared'] ?? null)) {
            return $this->handleSharedChat($message);
        }
        if (is_array($message) && preg_match('/^\/bind(?:@[A-Za-z0-9_]+)?(?:\s|$)/u', trim((string) ($message['text'] ?? '')))) {
            return $this->handleBind($message, isset($update['channel_post']));
        }

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

    private function handleBind(array $message, bool $isChannelPost): string
    {
        $chat = is_array($message['chat'] ?? null) ? $message['chat'] : [];
        $chatId = (int) ($chat['id'] ?? 0);
        $chatType = (string) ($chat['type'] ?? '');
        if ($chatId > 0 && $chatType === 'private') {
            $this->telegram->sendMessage(
                $chatId,
                'Выберите супергруппу или канал «Прожарка». Telegram передаст боту только выбранный чат — вступать в него заново не нужно.',
                [
                    'keyboard' => [
                        [[
                            'text' => 'Выбрать супергруппу',
                            'request_chat' => [
                                'request_id' => self::GROUP_REQUEST_ID,
                                'chat_is_channel' => false,
                                'bot_is_member' => true,
                                'request_title' => true,
                            ],
                        ]],
                        [[
                            'text' => 'Выбрать канал',
                            'request_chat' => [
                                'request_id' => self::CHANNEL_REQUEST_ID,
                                'chat_is_channel' => true,
                                'bot_is_member' => true,
                                'request_title' => true,
                            ],
                        ]],
                    ],
                    'resize_keyboard' => true,
                    'one_time_keyboard' => true,
                    'input_field_placeholder' => 'Выберите чат «Прожарка»',
                ],
            );
            return 'bind_picker_sent';
        }
        if ($chatId >= 0 || !in_array($chatType, ['group', 'supergroup', 'channel'], true)) {
            return 'bind_ignored_outside_group';
        }

        $configuredChannel = trim((string) $this->config->get('TELEGRAM_CHANNEL_ID', ''));
        if ($configuredChannel !== '' && $configuredChannel !== (string) $chatId) {
            $this->telegram->sendMessage($chatId, 'Бот уже привязан к другой группе.');
            return 'bind_rejected_already_configured';
        }

        if (!$isChannelPost) {
            $senderId = (int) ($message['from']['id'] ?? 0);
            if ($senderId <= 0) {
                return 'bind_rejected_without_sender';
            }
            $member = $this->telegram->getChatMember((string) $chatId, $senderId);
            if (!in_array((string) ($member['status'] ?? ''), ['administrator', 'creator'], true)) {
                $this->telegram->sendMessage($chatId, 'Привязать бота может только администратор группы.');
                return 'bind_rejected_not_admin';
            }
        }

        $this->persistBinding($chatId, $chatType, (string) ($chat['title'] ?? ''));

        $this->telegram->sendMessage(
            $chatId,
            'Группа привязана к «Прожарке». Бот готов выдавать персональные ссылки после оплаты.'
        );
        return 'chat_bound';
    }

    private function handleSharedChat(array $message): string
    {
        $privateChatId = (int) ($message['chat']['id'] ?? 0);
        $senderId = (int) ($message['from']['id'] ?? 0);
        $shared = $message['chat_shared'];
        $requestId = (int) ($shared['request_id'] ?? 0);
        $chatId = (int) ($shared['chat_id'] ?? 0);
        if ($privateChatId <= 0 || $senderId <= 0 || $chatId >= 0
            || !in_array($requestId, [self::GROUP_REQUEST_ID, self::CHANNEL_REQUEST_ID], true)) {
            return 'bind_shared_chat_invalid';
        }

        $senderMember = $this->telegram->getChatMember((string) $chatId, $senderId);
        if (!in_array((string) ($senderMember['status'] ?? ''), ['administrator', 'creator'], true)) {
            $this->telegram->sendMessage($privateChatId, 'Выбранный чат может привязать только его администратор.');
            return 'bind_rejected_not_admin';
        }

        $bot = $this->telegram->call('getMe');
        $botId = is_array($bot) ? (int) ($bot['id'] ?? 0) : 0;
        $botMember = $botId > 0 ? $this->telegram->getChatMember((string) $chatId, $botId) : [];
        if ((string) ($botMember['status'] ?? '') !== 'administrator'
            || ($botMember['can_invite_users'] ?? false) !== true
            || ($botMember['can_restrict_members'] ?? false) !== true) {
            $this->telegram->sendMessage(
                $privateChatId,
                'Боту нужны права администратора на приглашение и удаление участников. Выдайте их и повторите /bind.'
            );
            return 'bind_rejected_bot_rights';
        }

        $chat = $this->telegram->call('getChat', ['chat_id' => (string) $chatId]);
        $chatType = is_array($chat) ? (string) ($chat['type'] ?? '') : '';
        $title = is_array($chat) ? (string) ($chat['title'] ?? ($shared['title'] ?? '')) : (string) ($shared['title'] ?? '');
        if (!in_array($chatType, ['group', 'supergroup', 'channel'], true)) {
            return 'bind_shared_chat_wrong_type';
        }

        $configuredChannel = trim((string) $this->config->get('TELEGRAM_CHANNEL_ID', ''));
        if ($configuredChannel !== '' && $configuredChannel !== (string) $chatId) {
            $this->telegram->sendMessage($privateChatId, 'Бот уже привязан к другой группе.');
            return 'bind_rejected_already_configured';
        }

        $this->persistBinding($chatId, $chatType, $title);
        $this->telegram->sendMessage(
            $privateChatId,
            'Супергруппа «' . htmlspecialchars($title !== '' ? $title : 'Прожарка', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '» привязана. Бот готов выдавать персональные ссылки после оплаты.',
            ['remove_keyboard' => true],
        );
        return 'chat_bound_from_picker';
    }

    private function persistBinding(int $chatId, string $chatType, string $title): void
    {
        $discoveryFile = dirname($this->config->require('DATABASE_PATH')) . '/channel-discovery.json';
        $encoded = json_encode([
            'id' => (string) $chatId,
            'type' => $chatType,
            'title' => $title,
            'seen_at' => gmdate(DATE_ATOM),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false || file_put_contents($discoveryFile, $encoded, LOCK_EX) === false) {
            throw new RuntimeException('Не удалось сохранить привязку группы');
        }
        @chmod($discoveryFile, 0600);
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
