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
            $nextAi = $this->extractNextAi($content, $targetAi);

            if ($nextAi && !$session->isCompleted()) {
                // メンションされたAIがいれば、そのAIをターゲットにして10秒後に実行
                ProcessDebateTurn::dispatch($session->id, $nextAi)->delay(now()->addSeconds(10));
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
     * メッセージ内容からメンションを抽出し、次に発言すべきAIを特定する
     */
    private function extractNextAi(string $content, TargetAi $currentAi): ?TargetAi
    {
        \Log::debug('Extracting mention from text', ['text' => $content]);

        // 1. <@ID> または <@!ID> 形式を正規表現ですべて抽出
        if (preg_match_all('/<@!?(\d+)>/', $content, $matches)) {
            \Log::debug('Matched IDs', ['matches' => $matches]);
            $mentionedIds = $matches[1];

            // 2. 抽出したIDリストの「最初の1つ」をターゲットとする
            $targetId = (string)$mentionedIds[0];

            // 3. マッピング配列を使用してAIを特定
            $targetAi = TargetAi::fromBotId($targetId);

            if ($targetAi !== null) {
                return $targetAi;
            }

            \Log::info("マッピングに存在しないID（{$targetId}）のため、フォールバック処理に移行します。");
        }

        // メンションが見つからなかった場合（フォールバック）
        $botIds = config('services.discord.bot_ids', []);
        $availableAis = [];

        foreach ($botIds as $id => $name) {
            $ai = TargetAi::fromBotId((string)$id);
            // 現在のAI（直前に発言したAI）を除外
            if ($ai && $ai !== $currentAi && $ai !== TargetAi::GEMINI_CONCLUSION) {
                $availableAis[] = $ai;
            }
        }

        if (empty($availableAis)) {
            \Log::warning('No available AI found for fallback.');
            return null;
        }

        // ランダムに1つを選択
        $targetAi = $availableAis[array_rand($availableAis)];

        \Log::info('No mention found. Randomly selected next AI: ' . $targetAi->value);

        return $targetAi;
    }
}
