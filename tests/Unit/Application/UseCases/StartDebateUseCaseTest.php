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
        $threadId = 'thread_123';

        $discordAdapter->shouldReceive('createThread')->once()->with($topic)->andReturn($threadId);

        $repository->shouldReceive('save')->once()->andReturnUsing(function (DebateSession $session) {
            $session->id = 1;
            return $session;
        });

        $useCase = new StartDebateUseCase($repository, $discordAdapter);

        // Execute
        $result = $useCase->execute($topic);

        // Assert
        $this->assertEquals($threadId, $result);
        Queue::assertPushed(ProcessDebateTurn::class);
    }
}
