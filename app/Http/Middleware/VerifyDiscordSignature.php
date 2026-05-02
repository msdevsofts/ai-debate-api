<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VerifyDiscordSignature
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $bot = $request->query('bot');
        $signature = $request->header('X-Signature-Ed25519');
        $timestamp = $request->header('X-Signature-Timestamp');
        $body = $request->getContent();

        // 1. 環境変数キーの生成 (例: gpt-oss-q2 -> DISCORD_PUBLIC_KEY_GPT_OSS_Q2)
        $envSuffix = strtoupper(str_replace('-', '_', (string)$bot));
        $envKey = "DISCORD_PUBLIC_KEY_{$envSuffix}";

        // 2. 公開鍵の取得 (config 経由と env 直参照のフォールバック)
        $publicKey = config("services.discord.{$bot}.public_key")
            ?? config("services.discord.public_keys.{$bot}")
            ?? env($envKey)
            ?? config('services.discord.public_key');

        // デバッグログ
        Log::info('VerifyDiscordSignature Debug', [
            'bot' => $bot,
            'envKey' => $envKey,
            'publicKey_exists' => !empty($publicKey),
            'has_signature' => !empty($signature),
            'has_timestamp' => !empty($timestamp),
        ]);

        if (!$signature || !$timestamp || !$publicKey) {
            Log::warning('Unauthorized: Missing signature, timestamp, or public key', [
                'bot' => $bot,
                'publicKey_exists' => !empty($publicKey)
            ]);
            abort(401, 'Invalid signature or missing key');
        }

        try {
            if (function_exists('sodium_crypto_sign_verify_detached')) {
                $isVerified = sodium_crypto_sign_verify_detached(
                    hex2bin((string)$signature),
                    $timestamp . $body,
                    hex2bin((string)$publicKey)
                );

                if (!$isVerified) {
                    Log::warning('Invalid request signature', ['bot' => $bot]);
                    abort(401, 'Invalid signature or missing key');
                }
            } else {
                Log::warning('libsodium extension is not installed. Skipping strict signature verification.');
            }
        } catch (\Exception $e) {
            Log::error('Signature verification error: ' . $e->getMessage(), ['bot' => $bot]);
            abort(401, 'Invalid signature format');
        }

        return $next($request);
    }
}
