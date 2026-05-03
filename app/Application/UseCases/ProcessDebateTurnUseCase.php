<?php

declare(strict_types=1);

namespace App\Application\UseCases;

use App\Domain\Enums\TargetAi;
use App\Domain\Repositories\DebateSessionRepositoryInterface;
use App\Domain\Services\DiscordMessageFormatter;
use App\Infrastructure\Adapters\DifyApiAdapter;
use App\Infrastructure\Adapters\DiscordApiAdapter;
use App\Presentation\Jobs\ProcessDebateTurn;

class ProcessDebateTurnUseCase
{
    public function __construct(
        private readonly DebateSessionRepositoryInterface $repository,
        private readonly DifyApiAdapter $difyAdapter,
        private readonly DiscordApiAdapter $discordAdapter,
        private readonly DiscordMessageFormatter $messageFormatter
    ) {}

    public function execute(int $sessionId, ?TargetAi $targetAi = null, ?string $query = null, ?string $replyToMessageId = null): void
    {
        $session = $this->repository->findById($sessionId);
        if (!$session || $session->isCompleted()) {
            return;
        }

        // 次の発言AIを決定 (引数で指定されていればそれを使用、そうでなければローテーション)
        $targetAi = $targetAi ?? $session->getNextAi();
        $originalTargetAi = $targetAi; // 現在の発言者を保持
        $query = $query ?? $session->topic;

        try {
            // Dify API呼び出し
            $response = $this->difyAdapter->chat(
                $query,
                $session->difyConversationId,
                $targetAi,
                $session->topic
            );

            // 履歴IDを更新
            if (isset($response['conversation_id'])) {
                $session->difyConversationId = $response['conversation_id'];
            }

            $content = $response['answer'] ?? '';

            // メンション検知と次発言者の決定
            $targetAi = $this->messageFormatter->extractNextAi($content, $targetAi);

            // Discordメッセージのテキスト書き換え（メンションの同期）
            if ($targetAi) {
                $content = $this->messageFormatter->format($content, $targetAi);
            }

            // Discordメッセージ投稿
            // 送信主は $originalTargetAi、本文 $content 内のメンションは $targetAi (次の発言者) に同期済み
            $this->discordAdapter->postMessage($content, $session->discordChannelId, $originalTargetAi, $replyToMessageId);

            // ターンをインクリメント
            $session->incrementTurn();

            // 終了判定
            if ($targetAi === null || $targetAi->value === 'gemini_conclusion' || ($targetAi->value === 'gemini' && $originalTargetAi->value === 'gemini')) {
                $session->complete();
            }

            $this->repository->save($session);

            if ($targetAi && !$session->isCompleted()) {
                // 決定されたAIをターゲットにして10秒後に実行
                ProcessDebateTurn::dispatch($session->id, $targetAi)->delay(now()->addSeconds(10));
            }
        } catch (\Exception $e) {
            $session->fail();
            $this->repository->save($session);

            // Discordチャンネルにエラーを通知
            try {
                $this->discordAdapter->postMessage(
                    "⚠️ システムエラーが発生したため、議論を中断します。\nエラー内容: " . $e->getMessage(),
                    $session->discordChannelId,
                    \App\Domain\Enums\TargetAi::GEMINI_CONCLUSION // エラー通知はシステム側（Gemini）として送信
                );
            } catch (\Exception $discordEx) {
                // 通知自体の失敗はログに留める
                \Illuminate\Support\Facades\Log::error('Failed to notify error to Discord', [
                    'error' => $discordEx->getMessage()
                ]);
            }

            throw $e;
        }
    }
}
