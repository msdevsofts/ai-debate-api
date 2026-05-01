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
        // 1. Discord チャンネル作成
        $channelId = $this->discordAdapter->createChannel($topic);

        // 2. セッション作成
        $session = new DebateSession(
            id: null,
            topic: $topic,
            initialAi: $initialAi ? \App\Domain\Enums\TargetAi::tryFrom($initialAi) : null,
            discordChannelId: $channelId,
            currentTurn: 0,
            maxTurns: 10, // デフォルト10
            difyConversationId: null,
            status: 'running'
        );

        $savedSession = $this->repository->save($session);

        // 3. 非同期Jobディスパッチ
        ProcessDebateTurn::dispatch($savedSession->id);

        return $channelId;
    }
}
