<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controllers;

use App\Presentation\Jobs\StartDebateJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class DiscordInteractionController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $bot = $request->query('bot');
        $type = $request->json('type');

        \Log::info("Interaction received for bot: {$bot} (Type: {$type})", $request->all());

        return match ($type) {
            1 => response()->json(['type' => 1]), // PING
            2 => $this->handleApplicationCommand($request, (string)$bot), // APPLICATION_COMMAND
            default => response()->json(['message' => 'Unknown interaction type'], 400),
        };
    }

    private function handleApplicationCommand(Request $request, string $bot): JsonResponse
    {
        $data = $request->json('data');
        $commandName = $data['name'] ?? '';

        if ($commandName === 'discuss') {
            return $this->handleDiscussCommand($request, $bot);
        }

        if ($commandName === 'intervene') {
            return $this->handleInterveneCommand($request, $bot);
        }

        return response()->json(['message' => 'Unknown command'], 400);
    }

    private function handleDiscussCommand(Request $request, string $bot): JsonResponse
    {
        $data = $request->json('data');
        $options = $data['options'] ?? [];
        $topic = collect($options)->firstWhere('name', 'topic')['value'] ?? null;
        $initialAi = collect($options)->firstWhere('name', 'model')['value'] ?? null;
        $applicationId = $request->json('application_id');
        $token = $request->json('token');

        // 非同期Jobをディスパッチして即座にDEFERREDを返す
        StartDebateJob::dispatch($topic, $initialAi, $bot, $applicationId, $token);

        return response()->json([
            'type' => 5, // DEFERRED_CHANNEL_MESSAGE_WITH_SOURCE
        ]);
    }

    /**
     * 人間による介入コマンド (/intervene) の処理
     *
     * Discord Developer Portal への登録用 JSON ペイロード例:
     * {
     *   "name": "intervene",
     *   "description": "進行中の議論に介入し、AIに指示を出します",
     *   "options": [
     *     {
     *       "name": "target",
     *       "description": "指示を出す対象のAIを選択またはIDを入力",
     *       "type": 3,
     *       "required": true
     *     },
     *     {
     *       "name": "message",
     *       "description": "AIへの指示内容",
     *       "type": 3,
     *       "required": true
     *     }
     *   ]
     * }
     */
    private function handleInterveneCommand(Request $request, string $bot): JsonResponse
    {
        $data = $request->json('data');
        $options = $data['options'] ?? [];
        $targetStr = collect($options)->firstWhere('name', 'target')['value'] ?? '';
        $message = collect($options)->firstWhere('name', 'message')['value'] ?? '';
        $channelId = $request->json('channel_id');

        // チャンネルIDからセッションを特定
        $repository = app(\App\Domain\Repositories\DebateSessionRepositoryInterface::class);
        $session = $repository->findByDiscordChannelId((string)$channelId);

        if (!$session) {
            return response()->json([
                'type' => 4,
                'data' => ['content' => 'このチャンネルでは有効なディベートセッションが見つかりませんでした。']
            ]);
        }

        // ターゲットAIの特定（IDまたは識別子から）
        $targetAi = \App\Domain\Enums\TargetAi::fromBotId($targetStr)
            ?? \App\Domain\Enums\TargetAi::tryFrom($targetStr);

        if (!$targetAi) {
            // 見つからない場合は ID 文字列そのものがメンションとして機能することを期待するか、エラーにする。
            // ここでは安全のため、Enumとして特定できない場合はエラーを返す。
            return response()->json([
                'type' => 4,
                'data' => ['content' => "ターゲットAI「{$targetStr}」を特定できませんでした。"]
            ]);
        }

        // 非同期Jobをディスパッチ
        \App\Presentation\Jobs\ProcessDebateTurn::dispatch(
            $session->id,
            $targetAi,
            $message,
            null, // replyToMessageId
            true  // isHumanIntervention
        );

        return response()->json([
            'type' => 5, // DEFERRED_CHANNEL_MESSAGE_WITH_SOURCE
        ]);
    }
}
