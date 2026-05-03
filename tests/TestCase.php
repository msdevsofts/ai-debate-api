<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Discordインタラクション用のダミー署名ヘッダーを生成する
     */
    protected function getDiscordHeaders(): array
    {
        return [
            'X-Signature-Ed25519' => 'dummy-signature',
            'X-Signature-Timestamp' => (string)time(),
        ];
    }

    /**
     * テスト用の DebateSession エンティティを作成する
     */
    protected function createTestSession(array $attributes = []): \App\Domain\Entities\DebateSession
    {
        return new \App\Domain\Entities\DebateSession(
            id: $attributes['id'] ?? 1,
            topic: $attributes['topic'] ?? 'Default Topic',
            initialAi: $attributes['initialAi'] ?? null,
            discordChannelId: $attributes['discordChannelId'] ?? '1234567890',
            discordWebhookUrl: $attributes['discordWebhookUrl'] ?? 'https://discord.com/api/webhooks/123/abc',
            currentTurn: $attributes['currentTurn'] ?? 0,
            maxTurns: $attributes['maxTurns'] ?? 10,
            difyConversationId: $attributes['difyConversationId'] ?? null,
            status: $attributes['status'] ?? 'running'
        );
    }
}
