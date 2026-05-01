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
        $bot = $request->query('bot');
        \Log::info('Interaction received for bot: ' . $bot, $request->all());

        // --- 1. Discordリクエストの署名検証 (必須) ---
        $botType = $bot;
        $signature = $request->header('X-Signature-Ed25519');
        $timestamp = $request->header('X-Signature-Timestamp');
        $body = $request->getContent();

        // botパラメータに基づいて公開鍵を動的に切り替え
        // 文字列変換（ハイフンをアンダースコアに、大文字に）を適用してconfig/envから取得
        $configKey = strtoupper(str_replace('-', '_', (string)$botType));
        $publicKey = env("DISCORD_PUBLIC_KEY_{$configKey}") ?? config("services.discord.public_keys.{$botType}") ?? config('services.discord.public_key');

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
                $topic = collect($options)->firstWhere('name', 'topic')['value'] ?? null;
                $initialAi = collect($options)->firstWhere('name', 'model')['value'] ?? null;

                // 非同期Jobをディスパッチして即座にDEFERREDを返す
                StartDebateJob::dispatch($topic, $initialAi, $bot);

                return response()->json([
                    'type' => 5,
                ]);
            }
        }

        return response()->json(['message' => 'Invalid interaction'], 400);
    }
}
