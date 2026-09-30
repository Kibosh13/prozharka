<?php

declare(strict_types=1);

namespace Prozharka;

use RuntimeException;

class TelegramClient
{
    public function __construct(
        private readonly string $token,
        private readonly bool $dryRun = false,
    ) {
    }

    public function call(string $method, array $payload = []): mixed
    {
        if ($this->dryRun && in_array($method, [
            'banChatMember',
            'unbanChatMember',
            'approveChatJoinRequest',
            'declineChatJoinRequest',
        ], true)) {
            return true;
        }

        $handle = curl_init('https://api.telegram.org/bot' . $this->token . '/' . $method);
        if ($handle === false) {
            throw new RuntimeException('Unable to initialize Telegram request');
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($handle);
        curl_close($handle);

        if ($body === false || $curlError !== '') {
            throw new RuntimeException('Telegram request failed: ' . $curlError);
        }

        $response = json_decode($body, true);
        if ($status < 200 || $status >= 300 || !is_array($response) || ($response['ok'] ?? false) !== true) {
            $description = is_array($response) ? (string) ($response['description'] ?? 'unknown error') : 'invalid response';
            throw new RuntimeException("Telegram {$method} failed ({$status}): {$description}");
        }

        return $response['result'] ?? true;
    }

    public function sendMessage(int $chatId, string $text, ?array $replyMarkup = null): mixed
    {
        if ($this->dryRun) {
            return true;
        }
        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ];
        if ($replyMarkup !== null) {
            $payload['reply_markup'] = $replyMarkup;
        }
        return $this->call('sendMessage', $payload);
    }

    public function getChatMember(string $channelId, int $userId): array
    {
        $result = $this->call('getChatMember', [
            'chat_id' => $channelId,
            'user_id' => $userId,
        ]);
        return is_array($result) ? $result : [];
    }

    public function createSingleUseInvite(string $channelId, int $userId, int $expiresAt): string
    {
        if ($this->dryRun) {
            return 'https://t.me/+dry-run-' . $userId;
        }
        $result = $this->call('createChatInviteLink', [
            'chat_id' => $channelId,
            'name' => 'prozharka-' . $userId,
            'expire_date' => $expiresAt,
            'member_limit' => 1,
        ]);
        if (!is_array($result) || empty($result['invite_link'])) {
            throw new RuntimeException('Telegram did not return an invite link');
        }
        return (string) $result['invite_link'];
    }

    public function removeMember(string $channelId, int $userId): void
    {
        if ($this->dryRun) {
            return;
        }
        $this->call('banChatMember', [
            'chat_id' => $channelId,
            'user_id' => $userId,
            'revoke_messages' => false,
        ]);
        $this->call('unbanChatMember', [
            'chat_id' => $channelId,
            'user_id' => $userId,
            'only_if_banned' => true,
        ]);
    }
}

