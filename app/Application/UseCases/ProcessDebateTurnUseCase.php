<?php

declare(strict_types=1);

namespace App\Application\UseCases;

use App\Domain\Enums\TargetAi;
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

    public function execute(int $sessionId, ?TargetAi $targetAi = null, ?string $query = null, ?string $replyToMessageId = null): void
    {
        $session = $this->repository->findById($sessionId);
        if (!$session || $session->isCompleted()) {
            return;
        }

        // 次の発言AIを決定 (引数で指定されていればそれを使用、そうでなければローテーション)
        $targetAi = $targetAi ?? $session->getNextAi();
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

            // Discordメッセージ投稿
            $content = $response['answer'] ?? '';
            $this->discordAdapter->postMessage($content, $session->discordChannelId, $targetAi, $replyToMessageId);

            // ターンをインクリメント
            $session->incrementTurn();

            // 終了判定
            if ($targetAi->value === 'gemini_conclusion') {
                $session->complete();
            }

            $this->repository->save($session);

            // メンション検知による自律的ディベートの継続
            $mentionedAi = $this->detectMentionedAi($content);

            if ($mentionedAi && !$session->isCompleted()) {
                // メンションされたAIがいれば、そのAIをターゲットにして10秒後に実行
                ProcessDebateTurn::dispatch($session->id, $mentionedAi)->delay(now()->addSeconds(10));
                return;
            }

            // 次のターンをディスパッチ（3秒ディレイ） - メンション方式の場合は自動続行しない
            if (!$session->isCompleted() && $replyToMessageId === null && $mentionedAi === null) {
                ProcessDebateTurn::dispatch($session->id)->delay(now()->addSeconds(3));
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

    /**
     * メッセージ内容からメンションされているAIを特定する
     */
    private function detectMentionedAi(string $content): ?TargetAi
    {
        // 1. <@ID> 形式を正規表現でスキャン (configのマッピングを使用)
        $botIds = config('services.discord.bot_ids', []);
        if (preg_match_all('/<@([0-9]+)>/', $content, $matches)) {
            foreach ($matches[1] as $mentionedId) {
                if (isset($botIds[$mentionedId])) {
                    $name = strtolower($botIds[$mentionedId]);
                    return match ($name) {
                        'gemma' => TargetAi::GEMMA,
                        'phi' => TargetAi::PHI,
                        'llama' => TargetAi::LLAMA,
                        'gemini' => TargetAi::GEMINI,
                        'gpt_oss_q2' => TargetAi::GPT_OSS_Q2,
                        default => null,
                    };
                }
            }
        }

        // 2. フォールバック: <@ID> (Name) 形式を正規表現でスキャン
        if (preg_match('/<@([0-9]+)>\s*\((Gemma|Phi|Llama|Gemini|GPT-OSS-Q2)\)/i', $content, $matches)) {
            $name = strtolower($matches[2]);
            return match ($name) {
                'gemma' => TargetAi::GEMMA,
                'phi' => TargetAi::PHI,
                'llama' => TargetAi::LLAMA,
                'gemini' => TargetAi::GEMINI,
                'gpt-oss-q2' => TargetAi::GPT_OSS_Q2,
                default => null,
            };
        }

        return null;
    }
}
