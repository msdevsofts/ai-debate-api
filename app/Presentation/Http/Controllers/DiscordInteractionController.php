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
        if (($data['name'] ?? '') !== 'discuss') {
            return response()->json(['message' => 'Unknown command'], 400);
        }

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
}
