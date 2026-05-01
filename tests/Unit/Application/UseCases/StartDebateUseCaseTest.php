<?php

declare(strict_types=1);

namespace Tests\Unit\Application\UseCases;

use App\Application\UseCases\StartDebateUseCase;
use App\Domain\Entities\DebateSession;
use App\Domain\Repositories\DebateSessionRepositoryInterface;
use App\Infrastructure\Adapters\DiscordApiAdapter;
use Illuminate\Support\Facades\Queue;
use App\Presentation\Jobs\ProcessDebateTurn;
use Tests\TestCase;
use Mockery;

class StartDebateUseCaseTest extends TestCase
{
    public function test_execute_starts_debate_successfully(): void
    {
        $repository = Mockery::mock(DebateSessionRepositoryInterface::class);
        $discordAdapter = Mockery::mock(DiscordApiAdapter::class);
        Queue::fake();

        $topic = 'テストの議題';
        $channelId = 'channel_123';
        $webhookUrl = 'https://discord.com/api/webhooks/123/abc';

        $discordAdapter->shouldReceive('createChannel')->once()->with($topic, 1)->andReturn($channelId);
        $discordAdapter->shouldReceive('createWebhook')->once()->with($channelId)->andReturn($webhookUrl);

        $repository->shouldReceive('save')->twice()->andReturnUsing(function (DebateSession $session) use ($webhookUrl) {
            if ($session->id === null) {
                $session->id = 1;
            } else {
                $this->assertEquals($webhookUrl, $session->discordWebhookUrl);
            }
            return $session;
        });

        $useCase = new StartDebateUseCase($repository, $discordAdapter);

        // Execute
        $result = $useCase->execute($topic);

        // Assert
        $this->assertEquals($channelId, $result);
        Queue::assertPushed(ProcessDebateTurn::class);
    }

    public function test_execute_starts_debate_with_specified_initial_ai(): void
    {
        $repository = Mockery::mock(DebateSessionRepositoryInterface::class);
        $discordAdapter = Mockery::mock(DiscordApiAdapter::class);
        Queue::fake();

        $topic = 'テストの議題';
        $channelId = 'channel_123';
        $webhookUrl = 'https://discord.com/api/webhooks/123/abc';
        $initialAi = 'gemini';

        $discordAdapter->shouldReceive('createChannel')->once()->with($topic, 1)->andReturn($channelId);
        $discordAdapter->shouldReceive('createWebhook')->once()->with($channelId)->andReturn($webhookUrl);

        $repository->shouldReceive('save')->twice()->andReturnUsing(function (DebateSession $session) use ($initialAi, $webhookUrl) {
            if ($session->id === null) {
                $session->id = 1;
            } else {
                $this->assertEquals($initialAi, $session->initialAi->value);
                $this->assertEquals($webhookUrl, $session->discordWebhookUrl);
            }
            return $session;
        });

        $useCase = new StartDebateUseCase($repository, $discordAdapter);

        // Execute
        $useCase->execute($topic, $initialAi);

        // Assert
        Queue::assertPushed(ProcessDebateTurn::class);
    }
}
