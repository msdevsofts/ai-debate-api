<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controllers;

use App\Domain\Enums\TargetAi;
use App\Domain\Repositories\DebateSessionRepositoryInterface;
use App\Presentation\Jobs\ProcessDebateTurn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

class DiscordMessageController extends Controller
{
    public function __construct(
        private readonly DebateSessionRepositoryInterface $repository
    ) {}

    public function handle(Request $request): JsonResponse
    {
        // Discord Webhook (Gateway Event) からのメッセージ受信を想定
        // 署名検証は要件に含まれていない（ InteractionController 側にはあったが、こちらは新規実装）
        // セキュリティは考慮しなくて良いとのことなので、検証は最小限にする

        $type = $request->json('type');

        // PING 応答 (もしエンドポイントが共通化される場合を考慮)
        if ($type === 1) {
            return response()->json(['type' => 1]);
        }

        // Message Create イベントのデータを取得
        // Discord API の構造: { "event": "MESSAGE_CREATE", "data": { ... } } などを想定
        // もしくは Discord Interactions Endpoint にメッセージイベントが届く場合の構造
        $eventData = $request->all();

        // メッセージ本体を取得
        $message = $eventData['data'] ?? $eventData; // 柔軟に対応
        $content = $message['content'] ?? '';
        $channelId = $message['channel_id'] ?? '';
        $messageId = $message['id'] ?? '';

        if (empty($content) || empty($channelId)) {
            return response()->json(['message' => 'Invalid data'], 400);
        }

        // 1. メッセージ内にBotへのメンションが含まれているかチェック
        // @Llama, @Gemma, @Phi, @Gemini などのパターン
        $targetAi = null;
        if (stripos($content, '@Gemma') !== false) {
            $targetAi = TargetAi::GEMMA;
        } elseif (stripos($content, '@Phi') !== false) {
            $targetAi = TargetAi::PHI;
        } elseif (stripos($content, '@Llama') !== false) {
            $targetAi = TargetAi::LLAMA;
        } elseif (stripos($content, '@Gemini') !== false) {
            $targetAi = TargetAi::GEMINI;
        }

        if ($targetAi === null) {
            return response()->json(['message' => 'No mention found'], 200);
        }

        // 2. チャンネルIDからセッションを特定
        $session = $this->repository->findByDiscordChannelId($channelId);
        if (!$session) {
            Log::info('Debate session not found for channel', ['channel_id' => $channelId]);
            return response()->json(['message' => 'Session not found'], 200);
        }

        // 3. メンション元のメッセージ内容を「問いかけ（Query）」として抽出
        // メンション部分を削除して純粋な本文を取得
        $query = preg_replace('/<@!?[0-9]+>|@[A-Za-z]+/', '', $content);
        $query = trim($query);
        if (empty($query)) {
            $query = $session->topic; // 空なら議題をデフォルトにする
        }

        // 4. Jobをディスパッチ
        ProcessDebateTurn::dispatch(
            $session->id,
            $targetAi,
            $query,
            $messageId // 返信用に元のメッセージIDを渡す
        );

        return response()->json(['status' => 'ok']);
    }
}
