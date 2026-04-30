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

    public function execute(string $topic): string
    {
        // 1. Discord スレッド作成
        $threadId = $this->discordAdapter->createThread($topic);

        // 2. セッション作成
        $session = new DebateSession(
            id: null,
            topic: $topic,
            discordThreadId: $threadId,
            currentTurn: 0,
            maxTurns: 10, // デフォルト10
            difyConversationId: null,
            status: 'running'
        );

        $savedSession = $this->repository->save($session);

        // 3. 非同期Jobディスパッチ
        ProcessDebateTurn::dispatch($savedSession->id);

        return $threadId;
    }
}
