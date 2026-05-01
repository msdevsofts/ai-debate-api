<?php

declare(strict_types=1);

namespace Tests\Unit\Application\UseCases;

use App\Application\UseCases\ProcessDebateTurnUseCase;
use App\Domain\Entities\DebateSession;
use App\Domain\Repositories\DebateSessionRepositoryInterface;
use App\Infrastructure\Adapters\DifyApiAdapter;
use App\Infrastructure\Adapters\DiscordApiAdapter;
use App\Domain\Enums\TargetAi;
use Illuminate\Support\Facades\Queue;
use App\Presentation\Jobs\ProcessDebateTurn;
use Tests\TestCase;
use Mockery;

class ProcessDebateTurnUseCaseTest extends TestCase
{
    public function test_execute_processes_turn_successfully(): void
    {
        // Mocking
        $repository = Mockery::mock(DebateSessionRepositoryInterface::class);
        $difyAdapter = Mockery::mock(DifyApiAdapter::class);
        $discordAdapter = Mockery::mock(DiscordApiAdapter::class);
        Queue::fake();

        $sessionId = 1;
        $session = new DebateSession(
            id: $sessionId,
            topic: 'AIの未来について',
            initialAi: null,
            discordChannelId: '123456',
            discordWebhookUrl: 'https://discord.com/api/webhooks/123/abc',
            currentTurn: 0,
            maxTurns: 10,
            difyConversationId: null,
            status: 'running'
        );

        $repository->shouldReceive('findById')->with($sessionId)->andReturn($session);

        $difyAdapter->shouldReceive('chat')->with(
            $session->topic,
            $session->difyConversationId,
            TargetAi::GEMINI,
            $session->topic
        )->once()->andReturn([
            'answer' => 'AIの未来は明るいです。',
            'conversation_id' => 'conv_123'
        ]);

        $discordAdapter->shouldReceive('postMessage')->with('AIの未来は明るいです。', 'https://discord.com/api/webhooks/123/abc', TargetAi::GEMINI, null)->once();
        $repository->shouldReceive('save')->once();

        $useCase = new ProcessDebateTurnUseCase($repository, $difyAdapter, $discordAdapter);

        // Execute
        $useCase->execute($sessionId);

        // Assert
        $this->assertEquals('conv_123', $session->difyConversationId);
        $this->assertEquals(1, $session->currentTurn);
        Queue::assertPushed(ProcessDebateTurn::class);
    }

    public function test_execute_completes_when_gemini_finishes(): void
    {
        $repository = Mockery::mock(DebateSessionRepositoryInterface::class);
        $difyAdapter = Mockery::mock(DifyApiAdapter::class);
        $discordAdapter = Mockery::mock(DiscordApiAdapter::class);
        Queue::fake();

        $sessionId = 1;
        $session = new DebateSession(
            id: $sessionId,
            topic: 'AIの未来について',
            initialAi: null,
            discordChannelId: '123456',
            discordWebhookUrl: 'https://discord.com/api/webhooks/123/abc',
            currentTurn: 10, // Max turns reached
            maxTurns: 10,
            difyConversationId: 'conv_123',
            status: 'running'
        );

        $repository->shouldReceive('findById')->with($sessionId)->andReturn($session);

        $difyAdapter->shouldReceive('chat')->with(
            $session->topic,
            $session->difyConversationId,
            TargetAi::GEMINI_CONCLUSION,
            $session->topic
        )->once()->andReturn([
            'answer' => '結論として...',
            'conversation_id' => 'conv_123'
        ]);

        $discordAdapter->shouldReceive('postMessage')->with('結論として...', 'https://discord.com/api/webhooks/123/abc', TargetAi::GEMINI_CONCLUSION, null)->once();
        $repository->shouldReceive('save')->once();

        $useCase = new ProcessDebateTurnUseCase($repository, $difyAdapter, $discordAdapter);

        // Execute
        $useCase->execute($sessionId);

        // Assert
        $this->assertTrue($session->isCompleted());
        Queue::assertNotPushed(ProcessDebateTurn::class);
    }

    public function test_execute_dispatches_next_turn_with_mentioned_ai(): void
    {
        // Mocking
        $repository = Mockery::mock(DebateSessionRepositoryInterface::class);
        $difyAdapter = Mockery::mock(DifyApiAdapter::class);
        $discordAdapter = Mockery::mock(DiscordApiAdapter::class);
        Queue::fake();

        $sessionId = 1;
        $session = new DebateSession(
            id: $sessionId,
            topic: 'AIの未来について',
            initialAi: null,
            discordChannelId: '123456',
            discordWebhookUrl: 'https://discord.com/api/webhooks/123/abc',
            currentTurn: 0,
            maxTurns: 10,
            difyConversationId: null,
            status: 'running'
        );

        $repository->shouldReceive('findById')->with($sessionId)->andReturn($session);

        $answerWithMention = "次の意見を聞きましょう。<@123456789> (Llama) どう思いますか？";
        $difyAdapter->shouldReceive('chat')->andReturn([
            'answer' => $answerWithMention,
            'conversation_id' => 'conv_123'
        ]);

        $discordAdapter->shouldReceive('postMessage')->once();
        $repository->shouldReceive('save')->once();

        $useCase = new ProcessDebateTurnUseCase($repository, $difyAdapter, $discordAdapter);

        // Execute
        $useCase->execute($sessionId);

        // Assert
        // メンションされたAI (Llama) をターゲットとしてジョブがディスパッチされていることを確認
        Queue::assertPushed(ProcessDebateTurn::class, function ($job) use ($sessionId) {
            return $job->debateSessionId === $sessionId && $job->targetAi === TargetAi::LLAMA;
        });
    }
}
