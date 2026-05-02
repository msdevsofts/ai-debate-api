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

    public function __construct()
    {
        $this->botToken = config('services.discord.bot_token');
        $this->botTokens = config('services.discord.bot_tokens', []);
        $this->guildId = config('services.discord.guild_id');
        $this->channelId = config('services.discord.channel_id');
        $this->webhookUrl = config('services.discord.webhook_url');
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
        ])->post("https://discord.com/api/v10/guilds/{$this->guildId}/channels", [
            'name' => $name,
            'type' => 0, // GUILD_TEXT
            'topic' => $topic, // チャンネル説明欄に元のトピックをセット
        ]);

        if ($response->failed()) {
            Log::error('Discord Create Channel Error', [
                'status' => $response->status(),
                'body' => $response->body(),
                'guild_id' => $this->guildId,
            ]);
            throw new \RuntimeException('Discord API create channel failed');
        }

        return (string) $response->json('id');
    }

    public function createWebhook(string $channelId): string
    {
        $response = Http::withHeaders([
            'Authorization' => "Bot {$this->botToken}",
        ])->post("https://discord.com/api/v10/channels/{$channelId}/webhooks", [
            'name' => 'Debate Webhook',
        ]);

        if ($response->failed()) {
            Log::error('Discord Create Webhook Error', [
                'status' => $response->status(),
                'body' => $response->body(),
                'channel_id' => $channelId,
            ]);
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
        ])->post("https://discord.com/api/v10/channels/{$channelId}/messages", $payload);

        if ($response->failed()) {
            Log::error('Discord Post Message Error', [
                'status' => $response->status(),
                'body' => $response->body(),
                'channel_id' => $channelId,
                'ai' => $targetAi->value,
            ]);
            throw new \RuntimeException('Discord API post message failed');
        }
    }
}
