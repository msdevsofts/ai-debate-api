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

    public function execute(
        int $sessionId,
        ?TargetAi $targetAi = null,
        ?string $query = null,
        ?string $replyToMessageId = null,
        bool $isHumanIntervention = false
    ): void
    {
        \Illuminate\Support\Facades\Log::info('ProcessDebateTurnUseCase Execution Started', [
            'session_id' => $sessionId,
            'target_ai' => $targetAi?->value,
            'is_human_intervention' => $isHumanIntervention,
            'query' => $query
        ]);

        $session = $this->repository->findById($sessionId);
        if (!$session || ($session->isCompleted() && !$isHumanIntervention)) {
            \Illuminate\Support\Facades\Log::warning('ProcessDebateTurnUseCase: Session not found or already completed', [
                'session_id' => $sessionId,
                'exists' => (bool)$session,
                'is_completed' => $session?->isCompleted(),
                'is_human_intervention' => $isHumanIntervention
            ]);
            return;
        }

        // 人間からの介入で、かつセッションが完了している場合は再開させる
        if ($isHumanIntervention && $session->isCompleted()) {
            \Illuminate\Support\Facades\Log::info('ProcessDebateTurnUseCase: Resuming completed session due to human intervention', [
                'session_id' => $sessionId
            ]);
            $session->resume();
            // この時点では保存せず、処理の最後で一括保存する
        }

        // 次の発言AIを決定 (引数で指定されていればそれを使用、そうでなければローテーション)
        $targetAi = $targetAi ?? $session->getNextAi();
        $originalTargetAi = $targetAi; // 現在の発言者を保持

        // 人間からの介入（isHumanIntervention = true）の場合は、query が空であっても
        // $session->topic で上書きせず、渡された値をそのまま（または空のまま）使用する。
        // 通常のターンの場合は、query が空なら topic をデフォルトとして使用する。
        if (!$isHumanIntervention) {
            $query = $query ?? $session->topic;
        }

        try {
            \Illuminate\Support\Facades\Log::info('ProcessDebateTurnUseCase: Calling Dify API', [
                'target_ai' => $targetAi->value,
                'query_length' => strlen($query ?? ''),
                'is_human_intervention' => $isHumanIntervention
            ]);

            // Dify API呼び出し
            $response = $this->difyAdapter->chat(
                $query,
                $session->difyConversationId,
                $targetAi,
                $session->topic,
                $isHumanIntervention
            );

            // 履歴IDを更新
            if (isset($response['conversation_id'])) {
                $session->difyConversationId = $response['conversation_id'];
            }

            $content = $response['answer'] ?? '';

            // メンション検知と次発言者の決定
            $targetAi = $this->messageFormatter->extractNextAi($content, $targetAi, $session->currentTurn);

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
            // 1. 司会(Gemini)の結論発言（gemini_conclusion）
            // 2. ターゲットAIがいない（メンションなし＆フォールバック失敗）
            // 司会(Gemini)が自分自身をメンションした（＝結論または議論終了の意図）
            // 人間が介在している場合、AIは次に回すべき相手を明示しないことがあるため、
            // メンションがない場合は一旦停止する（AIの判断によるループ制御）。
            $isConclusion = ($targetAi === TargetAi::GEMINI_CONCLUSION);

            if ($targetAi === null || $isConclusion) {
                $session->complete();
            }

            $this->repository->save($session);

            if ($targetAi && !$session->isCompleted()) {
                // 決定されたAIをターゲットにして10秒後に実行
                dispatch(new ProcessDebateTurn($session->id, $targetAi))->delay(now()->addSeconds(10));
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
