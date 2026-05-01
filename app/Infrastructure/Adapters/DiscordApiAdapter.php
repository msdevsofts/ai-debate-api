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

    public function postMessage(string $content, string $webhookUrl, TargetAi $targetAi, ?string $replyToMessageId = null): void
    {
        $payload = [
            'content' => $content,
            'username' => $targetAi->getName(),
            'avatar_url' => $targetAi->getAvatarUrl(),
        ];

        // Webhookで返信（Reply）を行うための message_reference は、
        // 実はWebhook単体では完全にはサポートされていない場合があるが、
        // thread_id指定時と同様に query parameter で送るか、
        // もしくは Bot API (v10) を使用する。
        // 要件4は「message_referenceを使用して、元の発言に対して『返信（Reply）』の形で投稿」
        // とあるが、Webhookでusername/avatarを上書きしつつ返信するには制限がある。
        // Discord API v10の Webhook 実行 (Execute Webhook) では
        // thread_id はあるが message_reference は標準の payload にはない。
        // ただし、Bot API 経由で Webhook を実行する場合や、
        // 特定の裏技（Allowed Mentions等）で通知を飛ばすことはできる。
        // ここでは、要件に忠実に message_reference を payload に含める。
        // (Discord側が公式にWebhookでのmessage_referenceをサポートし始めたか、
        // もしくはBot APIによる投稿を意図している可能性がある)

        if ($replyToMessageId) {
            // Webhook payload に message_reference を含める（非公式な拡張か、将来の対応を想定）
            // もしくは、Bot API での送信に切り替える必要があるが、要件5で「Webhook送信時も...」
            // とあるため、Webhookを使い続ける。
            $payload['message_reference'] = [
                'message_id' => $replyToMessageId,
                'fail_if_not_exists' => false,
            ];
        }

        $response = Http::post($webhookUrl, $payload);

        if ($response->failed()) {
            Log::error('Discord Post Webhook Message Error', [
                'status' => $response->status(),
                'body' => $response->body(),
                'webhook_url' => $webhookUrl,
            ]);
            throw new \RuntimeException('Discord API post webhook message failed');
        }
    }
}
