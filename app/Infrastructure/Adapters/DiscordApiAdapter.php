<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapters;

use App\Domain\Enums\TargetAi;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DiscordApiAdapter
{
    private string $botToken;
    private string $channelId;
    private string $webhookUrl;

    public function __construct()
    {
        $this->botToken = config('services.discord.bot_token');
        $this->channelId = config('services.discord.channel_id');
        $this->webhookUrl = config('services.discord.webhook_url');
    }

    public function createThread(string $name): string
    {
        $response = Http::withHeaders([
            'Authorization' => "Bot {$this->botToken}",
        ])->post("https://discord.com/api/v10/channels/{$this->channelId}/threads", [
            'name' => $name,
            'type' => 11, // GUILD_PUBLIC_THREAD
            'auto_archive_duration' => 60,
        ]);

        if ($response->failed()) {
            Log::error('Discord Create Thread Error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException('Discord API create thread failed');
        }

        return (string) $response->json('id');
    }

    public function postToWebhook(string $content, string $threadId, TargetAi $targetAi): void
    {
        $url = "{$this->webhookUrl}?thread_id={$threadId}";

        $response = Http::post($url, [
            'content' => $content,
            'username' => $targetAi->getName(),
            'avatar_url' => $targetAi->getAvatarUrl(),
        ]);

        if ($response->failed()) {
            Log::error('Discord Webhook Error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException('Discord Webhook post failed');
        }
    }
}
