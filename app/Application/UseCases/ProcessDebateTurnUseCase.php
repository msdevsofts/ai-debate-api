<?php

declare(strict_types=1);

namespace App\Application\UseCases;

use App\Domain\Repositories\DebateSessionRepositoryInterface;
use App\Infrastructure\Adapters\DifyApiAdapter;
use App\Infrastructure\Adapters\DiscordApiAdapter;
use App\Presentation\Jobs\ProcessDebateTurn;

class ProcessDebateTurnUseCase
{
    public function __construct(
        private readonly DebateSessionRepositoryInterface $repository,
        private readonly DifyApiAdapter $difyAdapter,
        private readonly DiscordApiAdapter $discordAdapter
    ) {}

    public function execute(int $sessionId): void
    {
        $session = $this->repository->findById($sessionId);
        if (!$session || $session->isCompleted()) {
            return;
        }

        // 次の発言AIを決定
        $targetAi = $session->getNextAi();

        try {
            // Dify API呼び出し
            $response = $this->difyAdapter->chat(
                $session->topic,
                $session->difyConversationId,
                $targetAi,
                $session->topic
            );

            // 履歴IDを更新
            if (isset($response['conversation_id'])) {
                $session->difyConversationId = $response['conversation_id'];
            }

            // Discordメッセージ投稿
            $content = $response['answer'] ?? '';
            if ($session->discordWebhookUrl) {
                $this->discordAdapter->postMessage($content, $session->discordWebhookUrl, $targetAi);
            }

            // ターンをインクリメント
            $session->incrementTurn();

            // 終了判定
            if ($targetAi->value === 'gemini_conclusion') {
                $session->complete();
            }

            $this->repository->save($session);

            // 次のターンをディスパッチ（3秒ディレイ）
            if (!$session->isCompleted()) {
                ProcessDebateTurn::dispatch($session->id)->delay(now()->addSeconds(3));
            }
        } catch (\Exception $e) {
            $session->fail();
            $this->repository->save($session);

            // Discordチャンネルにエラーを通知
            if ($session->discordWebhookUrl) {
                try {
                    $this->discordAdapter->postMessage(
                        "⚠️ システムエラーが発生したため、議論を中断します。\nエラー内容: " . $e->getMessage(),
                        $session->discordWebhookUrl,
                        \App\Domain\Enums\TargetAi::GEMINI_CONCLUSION // エラー通知はシステム側（Gemini）として送信
                    );
                } catch (\Exception $discordEx) {
                    // 通知自体の失敗はログに留める
                    \Illuminate\Support\Facades\Log::error('Failed to notify error to Discord', [
                        'error' => $discordEx->getMessage()
                    ]);
                }
            }

            throw $e;
        }
    }
}
