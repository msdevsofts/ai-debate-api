<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controllers;

use App\Domain\Enums\TargetAi;
use App\Domain\Repositories\DebateSessionRepositoryInterface;
use App\Presentation\Jobs\ProcessDebateTurn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Log;

class DiscordMessageController extends Controller
{
    public function __construct(
        private readonly DebateSessionRepositoryInterface $repository
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $data = $this->extractMessageData($request);

        // 1. 送信者がBotである場合は無視
        if (($data['author']['bot'] ?? false) === true) {
            return response()->json(['status' => 'ignored']);
        }

        $content = $data['content'] ?? '';
        $channelId = $data['channel_id'] ?? '';
        $messageId = $data['id'] ?? '';

        if (empty($content) || empty($channelId)) {
            return response()->json(['message' => 'Invalid data'], 400);
        }

        // 2. チャンネルIDからセッションを特定
        $session = $this->repository->findByDiscordChannelId($channelId);
        if (!$session || $session->isCompleted()) {
            return response()->json(['message' => 'Session not found or completed'], 200);
        }

        // 3. メッセージ内にAIへのメンションが含まれているかチェック
        $targetAi = TargetAi::fromMention($content);

        // 4. メンションが含まれていない場合は「人間の割込み（ツッコミ）」と判定
        if ($targetAi === null) {
            // デフォルトで Gemini に返答させる
            $targetAi = TargetAi::GEMINI;
            $query = $content; // 人間の発言内容をそのままコンテキストに含める
        } else {
            // メンションがある場合は、そのメンション部分を除去してクエリとする
            $query = trim(preg_replace('/<@!?([0-9]+)>/', '', $content));
            if (empty($query)) {
                $query = $session->topic;
            }
        }

        // 5. Jobをディスパッチ
        ProcessDebateTurn::dispatch(
            $session->id,
            $targetAi,
            $query,
            $messageId // 返信用に元のメッセージIDを渡す
        );

        return response()->json(['status' => 'ok']);
    }

    private function extractMessageData(Request $request): array
    {
        // Discord Webhook からの MESSAGE_CREATE イベントを想定
        // データ構造: { "event": "MESSAGE_CREATE", "data": { ... } } または直接データ
        $event = $request->input('event');
        $data = $request->input('data');

        if (!$data && $event) {
            $data = $request->input('event.data');
        }

        return $data ?? $request->all();
    }
}
