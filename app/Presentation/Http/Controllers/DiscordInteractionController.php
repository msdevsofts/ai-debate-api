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
        // 署名検証 (簡易実装)
        if (!$this->verifySignature($request)) {
            return response()->json(['message' => 'Invalid signature'], 401);
        }

        $type = $request->json('type');

        // PING (Discord Verification)
        if ($type === 1) {
            return response()->json(['type' => 1]);
        }

        // APPLICATION_COMMAND (Slash Command)
        if ($type === 2) {
            $data = $request->json('data');
            if (($data['name'] ?? '') === 'discuss') {
                $options = $data['options'] ?? [];
                $topicOption = collect($options)->firstWhere('name', 'topic');
                $topic = $topicOption['value'] ?? null;

                if (empty($topic)) {
                    return response()->json([
                        'type' => 4,
                        'data' => [
                            'content' => '議題を入力してください。',
                            'flags' => 64, // Ephemeral
                        ],
                    ]);
                }

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

    private function verifySignature(Request $request): bool
    {
        $signature = $request->header('X-Signature-Ed25519');
        $timestamp = $request->header('X-Signature-Timestamp');
        $publicKey = config('services.discord.public_key');

        if (empty($signature) || empty($timestamp) || empty($publicKey)) {
            return false;
        }

        // 本来はEd25519の検証を行う必要があるが、指示により「セキュリティは考慮しない」
        // かつ実行環境の制約を考慮し、ヘッダーの存在確認のみに留める。
        // ※ 実際の運用で検証が必要な場合は、libsodium等の導入が必要。
        return true;
    }
}
