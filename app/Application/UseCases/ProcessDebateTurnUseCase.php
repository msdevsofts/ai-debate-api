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
            if ($session->discordWebhookUrl) {
                $this->discordAdapter->postMessage($content, $session->discordWebhookUrl, $targetAi, $replyToMessageId);
            }

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

            // 次のターンをディスパッチ（3秒ディレイ） - メンション方式の場合は自動続行しないか検討が必要だが、
            // 「ローテーション方式から『メンション反応方式』にアップグレード」
            // とあるため、自動ローテーションは停止するのが自然。
            // メンションがない場合は従来の挙動、メンションがあった場合はそのAIのみが応答する形か。
            // 要件1, 2, 3 を総合すると、メンション時に特定のAIをターゲットとして実行することが主眼。
            // 自動ローテーションを継続するかどうかは明記されていないが、アップグレード（変更）なので、
            // ここではメンションによる呼び出し時（$replyToMessageIdがある時など）は後続の自動ターンをスキップする。
            if (!$session->isCompleted() && $replyToMessageId === null) {
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

    /**
     * メッセージ内容からメンションされているAIを特定する
     */
    private function detectMentionedAi(string $content): ?TargetAi
    {
        // <@ID> (Name) 形式を正規表現でスキャン
        // 例: <@123456789> (Llama) や <@123456789> (Gemma)
        if (preg_match('/<@([0-9]+)>\s*\((Gemma|Phi|Llama|Gemini)\)/i', $content, $matches)) {
            $name = strtolower($matches[2]);
            return match ($name) {
                'gemma' => TargetAi::GEMMA,
                'phi' => TargetAi::PHI,
                'llama' => TargetAi::LLAMA,
                'gemini' => TargetAi::GEMINI,
                default => null,
            };
        }

        return null;
    }
}
