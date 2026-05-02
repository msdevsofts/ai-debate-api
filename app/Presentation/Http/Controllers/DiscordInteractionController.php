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

        \Log::info('Interaction received for bot: ' . $bot . ' (Type: ' . $type . ')', $request->all());

        // --- 1. PING イベントへの応答 (DiscordのURL認証に必須) ---
        if ($type === 1) {
            return response()->json(['type' => 1]);
        }

        // --- 2. APPLICATION_COMMAND (Slash Command: type 2) ---
        if ($type === 2) {
            $data = $request->json('data');
            if (($data['name'] ?? '') === 'discuss') {
                $options = $data['options'] ?? [];
                $topic = collect($options)->firstWhere('name', 'topic')['value'] ?? null;
                $initialAi = collect($options)->firstWhere('name', 'model')['value'] ?? null;

                // 非同期Jobをディスパッチして即座にDEFERREDを返す
                StartDebateJob::dispatch($topic, $initialAi, $bot);

                return response()->json([
                    'type' => 5, // DEFERRED_CHANNEL_MESSAGE_WITH_SOURCE
                ]);
            }
        }

        // --- 4. それ以外のInteractionタイプ ---
        return response()->json(['message' => 'Unknown interaction type'], 400);
    }
}
