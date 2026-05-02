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

    public function execute(string $topic, ?string $initialAi = null, ?string $triggerBot = null): string
    {
        // 1. 先にセッションを作成してIDを取得する (チャンネルIDなどは後で更新)
        $session = new DebateSession(
            id: null,
            topic: $topic,
            initialAi: $initialAi ? \App\Domain\Enums\TargetAi::tryFrom($initialAi) : null,
            discordChannelId: null,
            discordWebhookUrl: null, // Webhookは廃止するが、互換性のためにnullのまま保持
            currentTurn: 0,
            maxTurns: 10,
            difyConversationId: null,
            status: 'running'
        );

        $session = $this->repository->save($session);

        // 2. Discord チャンネル作成 (IDを含める)
        $channelId = $this->discordAdapter->createChannel($topic, $session->id);

        // 3. セッション情報を更新
        $session->discordChannelId = $channelId;
        $this->repository->save($session);

        // --- 追記: DEFERRED レスポンスへの後追いメッセージ送信 ---
        // 最初に応答したBotが誰かを特定（InteractionControllerから渡される botType を使用）
        $this->discordAdapter->postMessage(
            "🤖 議題『{$topic}』を受け付けました！専用チャンネル <#{$channelId}> を作成しました。議論を開始します...",
            $channelId, // チャンネルIDに送るか、Interaction Token が必要だが今はシンプルにチャンネルへ
            \App\Domain\Enums\TargetAi::GEMINI,
            null,
            $triggerBot
        );

        // 4. 非同期Jobディスパッチ
        ProcessDebateTurn::dispatch($session->id);

        return $channelId;
    }
}
