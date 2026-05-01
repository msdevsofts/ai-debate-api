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

        $discordAdapter->shouldReceive('postMessage')->with('AIの未来は明るいです。', '123456', TargetAi::GEMINI, null)->once();
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

        $discordAdapter->shouldReceive('postMessage')->with('結論として...', '123456', TargetAi::GEMINI_CONCLUSION, null)->once();
        $repository->shouldReceive('save')->once();

        $useCase = new ProcessDebateTurnUseCase($repository, $difyAdapter, $discordAdapter);

        // Execute
        $useCase->execute($sessionId);

        // Assert
        $this->assertTrue($session->isCompleted());
        Queue::assertNotPushed(ProcessDebateTurn::class);
    }

    public function test_execute_dispatches_next_turn_with_mentioned_ai_by_id(): void
    {
        // Mocking
        $repository = Mockery::mock(DebateSessionRepositoryInterface::class);
        $difyAdapter = Mockery::mock(DifyApiAdapter::class);
        $discordAdapter = Mockery::mock(DiscordApiAdapter::class);
        Queue::fake();

        // configのモック
        config(['services.discord.bot_ids' => ['999888777' => 'phi']]);

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

        $answerWithMention = "次は <@999888777> さん、お願いします。";
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
        // メンションされたAI (Phi) をターゲットとしてジョブがディスパッチされていることを確認
        Queue::assertPushed(ProcessDebateTurn::class, function ($job) use ($sessionId) {
            return $job->debateSessionId === $sessionId && $job->targetAi === TargetAi::PHI;
        });
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

    public function test_execute_dispatches_next_turn_with_gpt_oss_q2_mention(): void
    {
        // Mocking
        $repository = Mockery::mock(DebateSessionRepositoryInterface::class);
        $difyAdapter = Mockery::mock(DifyApiAdapter::class);
        $discordAdapter = Mockery::mock(DiscordApiAdapter::class);
        Queue::fake();

        $sessionId = 1;
        $session = new DebateSession(
            id: $sessionId,
            topic: '新しいAIモデルの導入',
            initialAi: null,
            discordChannelId: '123456',
            discordWebhookUrl: 'https://discord.com/api/webhooks/123/abc',
            currentTurn: 1,
            maxTurns: 10,
            difyConversationId: 'conv_123',
            status: 'running'
        );

        $repository->shouldReceive('findById')->with($sessionId)->andReturn($session);

        // GPT-OSS-Q2 へのメンションを含む回答
        $answerWithMention = "興味深いですね。<@1499379253689716736> (GPT-OSS-Q2) さんはどう考えますか？";
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
        Queue::assertPushed(ProcessDebateTurn::class, function ($job) use ($sessionId) {
            return $job->debateSessionId === $sessionId && $job->targetAi === TargetAi::GPT_OSS_Q2;
        });
    }
}
