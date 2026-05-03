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
    public function test_handle_ignores_bot_messages(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/discord/messages', [
            'event' => 'MESSAGE_CREATE',
            'data' => [
                'author' => ['bot' => true],
                'content' => 'Bot says something',
                'channel_id' => '123456',
                'id' => '789'
            ]
        ]);

        $response->assertStatus(200)
            ->assertJson(['status' => 'ignored']);

        Queue::assertNotPushed(ProcessDebateTurn::class);
    }

    public function test_handle_processes_human_interruption_without_mention(): void
    {
        Queue::fake();

        $repository = Mockery::mock(DebateSessionRepositoryInterface::class);
        $this->app->instance(DebateSessionRepositoryInterface::class, $repository);

        $sessionId = 1;
        $channelId = '123456';
        $session = $this->createTestSession([
            'id' => $sessionId,
            'topic' => 'AIの未来',
            'discordChannelId' => $channelId,
            'currentTurn' => 1,
            'difyConversationId' => 'conv_123',
        ]);

        $repository->shouldReceive('findByDiscordChannelId')
            ->with($channelId)
            ->andReturn($session);

        $content = '人間が割り込みます。どう思いますか？';

        $response = $this->postJson('/api/discord/messages', [
            'data' => [
                'author' => ['bot' => false],
                'content' => $content,
                'channel_id' => $channelId,
                'id' => '789'
            ]
        ]);

        $response->assertStatus(200)
            ->assertJson(['status' => 'ok']);

        Queue::assertPushed(ProcessDebateTurn::class, function ($job) use ($sessionId, $content) {
            return $job->debateSessionId === $sessionId
                && $job->targetAi === \App\Domain\Enums\TargetAi::GEMINI
                && $job->query === $content;
        });
    }

    public function test_handle_processes_human_message_with_mention(): void
    {
        Queue::fake();

        $repository = Mockery::mock(DebateSessionRepositoryInterface::class);
        $this->app->instance(DebateSessionRepositoryInterface::class, $repository);

        $sessionId = 1;
        $channelId = '123456';
        $session = $this->createTestSession([
            'id' => $sessionId,
            'topic' => 'AIの未来',
            'discordChannelId' => $channelId,
            'currentTurn' => 1,
            'difyConversationId' => 'conv_123',
        ]);

        $repository->shouldReceive('findByDiscordChannelId')
            ->with($channelId)
            ->andReturn($session);

        // IDから判定するパスを試す
        $phiId = '1499775795067879436'; // Phi の ID
        $content = "次は <@{$phiId}> さんに聞きたいです。";

        $response = $this->postJson('/api/discord/messages', [
            'data' => [
                'author' => ['bot' => false],
                'content' => $content,
                'channel_id' => $channelId,
                'id' => '789'
            ]
        ]);

        $response->assertStatus(200)
            ->assertJson(['status' => 'ok']);

        Queue::assertPushed(ProcessDebateTurn::class, function ($job) use ($sessionId) {
            return $job->debateSessionId === $sessionId
                && $job->targetAi === \App\Domain\Enums\TargetAi::PHI;
        });
    }
}
