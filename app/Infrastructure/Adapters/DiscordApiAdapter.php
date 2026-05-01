<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapters;

use App\Domain\Enums\TargetAi;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DiscordApiAdapter
{
    private string $botToken;
    private string $guildId;
    private string $channelId;
    private string $webhookUrl;

    public function __construct()
    {
        $this->botToken = config('services.discord.bot_token');
        $this->guildId = config('services.discord.guild_id');
        $this->channelId = config('services.discord.channel_id');
        $this->webhookUrl = config('services.discord.webhook_url');
    }

    public function createChannel(string $topic): string
    {
        // チャンネル名のサニタイズ (Str::slug を使用し、Discordの命名規則に合わせる)
        $name = \Illuminate\Support\Str::slug($topic);
        $name = mb_substr($name, 0, 100);

        if (empty($name)) {
            $name = 'debate-channel';
        }

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

    public function postMessage(string $content, string $channelId, TargetAi $targetAi): void
    {
        $response = Http::withHeaders([
            'Authorization' => "Bot {$this->botToken}",
        ])->post("https://discord.com/api/v10/channels/{$channelId}/messages", [
            'content' => "**[{$targetAi->getName()}]**\n\n{$content}",
        ]);

        if ($response->failed()) {
            Log::error('Discord Post Message Error', [
                'status' => $response->status(),
                'body' => $response->body(),
                'channel_id' => $channelId,
            ]);
            throw new \RuntimeException('Discord API post message failed');
        }
    }
}
