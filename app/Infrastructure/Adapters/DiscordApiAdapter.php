<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapters;

use App\Domain\Enums\TargetAi;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DiscordApiAdapter
{
    private string $botToken;
    private array $botTokens;
    private string $guildId;
    private string $channelId;
    private string $webhookUrl;
    private string $apiBaseUrl;

    public function __construct()
    {
        $this->botToken = config('services.discord.bot_token');
        $this->botTokens = config('services.discord.bot_tokens', []);
        $this->guildId = config('services.discord.guild_id');
        $this->channelId = config('services.discord.channel_id');
        $this->webhookUrl = config('services.discord.webhook_url');
        $this->apiBaseUrl = config('services.discord.api_base_url', 'https://discord.com/api/v10');
    }

    public function createChannel(string $topic, int $sessionId): string
    {
        // チャンネル名のサニタイズ (Str::slug を使用し、Discordの命名規則に合わせる)
        $slug = \Illuminate\Support\Str::slug($topic);
        $prefix = 'debate-';
        $suffix = '-' . $sessionId;

        // 全体で100文字以内にする。プレフィックスとサフィックスを除いた長さを計算
        $maxSlugLength = 100 - strlen($prefix) - strlen($suffix);
        $shortSlug = mb_substr($slug, 0, $maxSlugLength);

        $name = $prefix . $shortSlug . $suffix;

        $response = Http::withHeaders([
            'Authorization' => "Bot {$this->botToken}",
        ])->post("{$this->apiBaseUrl}/guilds/{$this->guildId}/channels", [
            'name' => $name,
            'type' => 0, // GUILD_TEXT
            'topic' => $topic, // チャンネル説明欄に元のトピックをセット
        ]);

        if ($response->failed()) {
            $this->logError('Discord Create Channel Error', $response, ['guild_id' => $this->guildId]);
            throw new \RuntimeException('Discord API create channel failed');
        }

        return (string) $response->json('id');
    }

    public function createWebhook(string $channelId): string
    {
        $response = Http::withHeaders([
            'Authorization' => "Bot {$this->botToken}",
        ])->post("{$this->apiBaseUrl}/channels/{$channelId}/webhooks", [
            'name' => 'Debate Webhook',
        ]);

        if ($response->failed()) {
            $this->logError('Discord Create Webhook Error', $response, ['channel_id' => $channelId]);
            throw new \RuntimeException('Discord API create webhook failed');
        }

        return (string) $response->json('url');
    }

    public function postMessage(string $content, string $channelId, TargetAi $targetAi, ?string $replyToMessageId = null, ?string $forceAiKey = null): void
    {
        // ターゲットAIに応じたBotトークンを選択 (gemini_conclusionはgeminiトークンを使用)
        $aiKey = $forceAiKey ?? match ($targetAi) {
            TargetAi::GEMINI, TargetAi::GEMINI_CONCLUSION => 'gemini',
            TargetAi::LLAMA => 'llama',
            TargetAi::GEMMA => 'gemma',
            TargetAi::PHI => 'phi',
            TargetAi::GPT_OSS_Q2 => 'gpt_oss_q2',
        };

        $token = $this->botTokens[$aiKey] ?? $this->botToken;

        $payload = [
            'content' => $content,
        ];

        if ($replyToMessageId) {
            $payload['message_reference'] = [
                'message_id' => $replyToMessageId,
                'fail_if_not_exists' => false,
            ];
        }

        $response = Http::withHeaders([
            'Authorization' => "Bot {$token}",
        ])->post("{$this->apiBaseUrl}/channels/{$channelId}/messages", $payload);

        if ($response->failed()) {
            $this->logError('Discord Post Message Error', $response, [
                'channel_id' => $channelId,
                'ai' => $targetAi->value,
            ]);
            throw new \RuntimeException('Discord API post message failed');
        }
    }

    /**
     * Interactionのレスポンス（Edit Original Interaction Response）を更新する
     * PATCH /webhooks/{application_id}/{interaction_token}/messages/@original
     */
    public function editOriginalInteractionResponse(string $applicationId, string $token, string $content): void
    {
        $response = Http::patch("{$this->apiBaseUrl}/webhooks/{$applicationId}/{$token}/messages/@original", [
            'content' => $content,
        ]);

        if ($response->failed()) {
            $this->logError('Discord Edit Interaction Error', $response, [
                'application_id' => $applicationId,
            ]);
        }
    }

    /**
     * エラーをログに出力する
     */
    private function logError(string $message, \Illuminate\Http\Client\Response $response, array $context = []): void
    {
        Log::error($message, array_merge([
            'status' => $response->status(),
            'body' => $response->body(),
        ], $context));
    }
}
