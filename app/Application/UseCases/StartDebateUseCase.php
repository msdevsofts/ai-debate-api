<?php

declare(strict_types=1);

namespace App\Application\UseCases;

use App\Domain\Entities\DebateSession;
use App\Domain\Repositories\DebateSessionRepositoryInterface;
use App\Infrastructure\Adapters\DiscordApiAdapter;
use App\Presentation\Jobs\ProcessDebateTurn;

class StartDebateUseCase
{
    public function __construct(
        private readonly DebateSessionRepositoryInterface $repository,
        private readonly DiscordApiAdapter $discordAdapter
    ) {}

    public function execute(string $topic, ?string $initialAi = null): string
    {
        // 1. 先にセッションを作成してIDを取得する (チャンネルIDなどは後で更新)
        $session = new DebateSession(
            id: null,
            topic: $topic,
            initialAi: $initialAi ? \App\Domain\Enums\TargetAi::tryFrom($initialAi) : null,
            discordChannelId: null,
            discordWebhookUrl: null,
            currentTurn: 0,
            maxTurns: 10,
            difyConversationId: null,
            status: 'running'
        );

        $session = $this->repository->save($session);

        // 2. Discord チャンネル作成 (IDを含める)
        $channelId = $this->discordAdapter->createChannel($topic, $session->id);

        // 3. Discord Webhook 作成
        $webhookUrl = $this->discordAdapter->createWebhook($channelId);

        // 4. セッション情報を更新
        $session->discordChannelId = $channelId;
        $session->discordWebhookUrl = $webhookUrl;
        $this->repository->save($session);

        // 5. 非同期Jobディスパッチ
        ProcessDebateTurn::dispatch($session->id);

        return $channelId;
    }
}
