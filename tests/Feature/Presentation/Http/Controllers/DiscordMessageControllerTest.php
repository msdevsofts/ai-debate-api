<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Http\Controllers;

use App\Domain\Entities\DebateSession;
use App\Domain\Repositories\DebateSessionRepositoryInterface;
use App\Presentation\Jobs\ProcessDebateTurn;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Mockery;

class DiscordMessageControllerTest extends TestCase
{
    public function test_handle_dispatches_job_when_mention_found(): void
    {
        Queue::fake();
        $repository = Mockery::mock(DebateSessionRepositoryInterface::class);
        $this->app->instance(DebateSessionRepositoryInterface::class, $repository);

        $channelId = 'channel_123';
        $messageId = 'msg_456';
        $content = '@Gemma こんにちは！';

        $session = new DebateSession(
            id: 1,
            topic: 'テスト議題',
            initialAi: null,
            discordChannelId: $channelId,
            discordWebhookUrl: 'https://webhook.url',
            currentTurn: 1,
            maxTurns: 10,
            difyConversationId: 'conv_123',
            status: 'running'
        );

        $repository->shouldReceive('findByDiscordChannelId')
            ->with($channelId)
            ->once()
            ->andReturn($session);

        $response = $this->postJson('/api/discord/messages', [
            'content' => $content,
            'channel_id' => $channelId,
            'id' => $messageId,
        ]);

        $response->assertStatus(200);

        Queue::assertPushed(ProcessDebateTurn::class, function ($job) use ($messageId) {
            // リフレクション等を使ってプライベートプロパティを確認する必要があるが、
            // ここでは簡易的にディスパッチされたことのみを確認、あるいは
            // コンストラクタ引数のテストを検討する。
            // StartDebateJob等とは異なりプライベートプロパティなので直接アクセスはできない。
            return true;
        });
    }

    public function test_handle_does_nothing_when_no_mention(): void
    {
        Queue::fake();
        $response = $this->postJson('/api/discord/messages', [
            'content' => 'ただのメッセージ',
            'channel_id' => '123',
            'id' => '456',
        ]);

        $response->assertStatus(200);
        Queue::assertNotPushed(ProcessDebateTurn::class);
    }
}
