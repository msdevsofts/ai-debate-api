<?php

declare(strict_types=1);

namespace Tests\Unit\Application\UseCases;

use App\Application\UseCases\StartDebateUseCase;
use App\Domain\Entities\DebateSession;
use App\Domain\Repositories\DebateSessionRepositoryInterface;
use App\Infrastructure\Adapters\DiscordApiAdapter;
use Illuminate\Support\Facades\Queue;
use App\Presentation\Jobs\ProcessDebateTurn;
use App\Domain\Enums\TargetAi;
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
        $discordAdapter->shouldReceive('postMessage')->once();

        $repository->shouldReceive('save')->twice()->andReturnUsing(function (DebateSession $session) {
            if ($session->id === null) {
                $session->id = 1;
            }
            return $session;
        });

        $useCase = new StartDebateUseCase($repository, $discordAdapter);

        // Execute
        $result = $useCase->execute($topic);

        // Assert
        $this->assertEquals($channelId, $result);
        Queue::assertPushed(ProcessDebateTurn::class, function ($job) {
            return $job->turnId !== null;
        });
    }

    public function test_execute_always_starts_debate_with_gemini(): void
    {
        $repository = Mockery::mock(DebateSessionRepositoryInterface::class);
        $discordAdapter = Mockery::mock(DiscordApiAdapter::class);
        Queue::fake();

        $topic = 'テストの議題';
        $channelId = 'channel_123';
        $initialAi = 'llama'; // 指定しても無視されてGeminiになるべき

        $discordAdapter->shouldReceive('createChannel')->once()->with($topic, 1)->andReturn($channelId);
        $discordAdapter->shouldReceive('postMessage')->once();

        $repository->shouldReceive('save')->twice()->andReturnUsing(function (DebateSession $session) {
            if ($session->id === null) {
                $session->id = 1;
            }
            // initialAiがGeminiであることを確認
            $this->assertEquals(TargetAi::GEMINI, $session->initialAi);
            return $session;
        });

        $useCase = new StartDebateUseCase($repository, $discordAdapter);

        // Execute
        $useCase->execute($topic, $initialAi);

        // Assert
        Queue::assertPushed(ProcessDebateTurn::class, function ($job) {
            return $job->turnId !== null;
        });
    }
}
