<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controllers;

use App\Application\UseCases\StartDebateUseCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class DiscordInteractionController extends Controller
{
    public function __construct(
        private readonly StartDebateUseCase $startDebateUseCase
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $type = $request->json('type');

        // PING (Discord Verification)
        if ($type === 1) {
            return response()->json(['type' => 1]);
        }

        // APPLICATION_COMMAND (Slash Command)
        if ($type === 2) {
            $data = $request->json('data');
            if ($data['name'] === 'discuss') {
                $topic = $data['options'][0]['value'] ?? '議題なし';

                // スレッド作成とディベート開始
                $threadId = $this->startDebateUseCase->execute($topic);

                return response()->json([
                    'type' => 4,
                    'data' => [
                        'content' => "専用スレッドを作成しました: <#{$threadId}>",
                    ],
                ]);
            }
        }

        return response()->json(['message' => 'Invalid interaction'], 400);
    }
}
