<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controllers;

use App\Presentation\Jobs\StartDebateJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class DiscordInteractionController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        // --- 1. Discordリクエストの署名検証 (必須) ---
        $signature = $request->header('X-Signature-Ed25519');
        $timestamp = $request->header('X-Signature-Timestamp');
        $body = $request->getContent();
        $publicKey = config('services.discord.public_key');

        if (!$signature || !$timestamp || !$publicKey) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        try {
            // sodium_crypto_sign_verify_detached が利用可能か確認（libsodium拡張が必要）
            if (function_exists('sodium_crypto_sign_verify_detached')) {
                $isVerified = sodium_crypto_sign_verify_detached(
                    hex2bin($signature),
                    $timestamp . $body,
                    hex2bin($publicKey)
                );

                if (!$isVerified) {
                    return response()->json(['message' => 'Invalid request signature'], 401);
                }
            } else {
                // libsodiumがインストールされていない場合はログに警告を出し、以前の簡易検証(ヘッダー存在確認のみ)を継続
                \Illuminate\Support\Facades\Log::warning('libsodium extension is not installed. Skipping strict signature verification.');
            }
        } catch (\Exception $e) {
            return response()->json(['message' => 'Invalid signature format'], 401);
        }

        // --- 2. PING イベントへの応答 (必須) ---
        if ($request->input('type') === 1) {
            return response()->json(['type' => 1]);
        }

        $type = $request->json('type');

        // APPLICATION_COMMAND (Slash Command)
        if ($type === 2) {
            $data = $request->json('data');
            if (($data['name'] ?? '') === 'discuss') {
                $options = $data['options'] ?? [];
                $topicOption = collect($options)->firstWhere('name', 'topic');
                $topic = $topicOption['value'] ?? null;

                $modelOption = collect($options)->firstWhere('name', 'model');
                $initialAi = $modelOption['value'] ?? null;

                if (empty($topic)) {
                    return response()->json([
                        'type' => 4,
                        'data' => [
                            'content' => '議題を入力してください。',
                            'flags' => 64, // Ephemeral
                        ],
                    ]);
                }

                // 非同期Jobをディスパッチ
                StartDebateJob::dispatch($topic, $initialAi);

                return response()->json([
                    'type' => 4,
                    'data' => [
                        'content' => "🤖 議題『{$topic}』を受け付けました！新規チャンネルを作成してAIたちを呼び出します...",
                    ],
                ]);
            }
        }

        return response()->json(['message' => 'Invalid interaction'], 400);
    }
}
