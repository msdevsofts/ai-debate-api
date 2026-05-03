<?php

declare(strict_types=1);

namespace Tests\Unit\Application\UseCases;

use App\Application\UseCases\ProcessDebateTurnUseCase;
use App\Domain\Entities\DebateSession;
use App\Domain\Repositories\DebateSessionRepositoryInterface;
use App\Domain\Services\DiscordMessageFormatter;
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
            Mockery::type(TargetAi::class),
            $session->topic,
            false // isHumanIntervention
        )->once()->andReturn([
            'answer' => 'AIの未来は明るいです。 <@111>',
            'conversation_id' => 'conv_123'
        ]);

        config(['services.discord.bot_ids' => ['111' => 'phi']]);

        $discordAdapter->shouldReceive('postMessage')->with('AIの未来は明るいです。 <@111>', '123456', TargetAi::GEMINI, null)->once();
        $repository->shouldReceive('save')->once();

        $formatter = Mockery::mock(DiscordMessageFormatter::class);
        $formatter->shouldReceive('extractNextAi')->andReturn(TargetAi::PHI);
        $formatter->shouldReceive('format')->andReturn('AIの未来は明るいです。 <@111>');

        $useCase = new ProcessDebateTurnUseCase($repository, $difyAdapter, $discordAdapter, $formatter);

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
            topic: 'AI의 미래에 대해',
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
            $session->topic,
            false // isHumanIntervention
        )->once()->andReturn([
            'answer' => '結論として...',
            'conversation_id' => 'conv_123'
        ]);

        $discordAdapter->shouldReceive('postMessage')->with('結論として...', '123456', TargetAi::GEMINI_CONCLUSION, null)->once();
        $repository->shouldReceive('save')->once();

        $formatter = Mockery::mock(DiscordMessageFormatter::class);
        $formatter->shouldReceive('extractNextAi')->andReturn(null);

        $useCase = new ProcessDebateTurnUseCase($repository, $difyAdapter, $discordAdapter, $formatter);

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

        $formatter = Mockery::mock(DiscordMessageFormatter::class);
        $formatter->shouldReceive('extractNextAi')->andReturn(TargetAi::PHI);
        $formatter->shouldReceive('format')->andReturn($answerWithMention);

        $useCase = new ProcessDebateTurnUseCase($repository, $difyAdapter, $discordAdapter, $formatter);

        // Execute
        $useCase->execute($sessionId);

        // Assert
        // メンションされたAI (Phi) をターゲットとしてジョブがディスパッチされていることを確認
        Queue::assertPushed(ProcessDebateTurn::class, function ($job) use ($sessionId) {
            return $job->debateSessionId === $sessionId && $job->targetAi === TargetAi::PHI;
        });
    }

    public function test_execute_stops_when_no_mentions(): void
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

        $answerWithoutMention = "以上で私の意見を終わります。";
        // 現在の発言者がPhiであると仮定
        $difyAdapter->shouldReceive('chat')->andReturn([
            'answer' => $answerWithoutMention,
            'conversation_id' => 'conv_123'
        ]);

        $discordAdapter->shouldReceive('postMessage')->once();
        $repository->shouldReceive('save')->once();

        $formatter = Mockery::mock(DiscordMessageFormatter::class);
        $formatter->shouldReceive('extractNextAi')->andReturn(null);

        $useCase = new ProcessDebateTurnUseCase($repository, $difyAdapter, $discordAdapter, $formatter);

        // Execute
        // targetAiにPHIを指定して実行
        $useCase->execute($sessionId, TargetAi::PHI);

        // Assert
        // メンションがない場合、ループが停止しセッションが完了することを確認
        $this->assertTrue($session->isCompleted());
        Queue::assertNotPushed(ProcessDebateTurn::class);
    }

    public function test_execute_stops_when_ai_mentions_itself(): void
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

        // Phiが自分自身（Phi: 111）をメンションするケース
        $answerWithSelfMention = "自分自身 <@111> に問いかけます。";
        $difyAdapter->shouldReceive('chat')->andReturn([
            'answer' => $answerWithSelfMention,
            'conversation_id' => 'conv_123'
        ]);

        $discordAdapter->shouldReceive('postMessage')->once();
        $repository->shouldReceive('save')->once();

        $formatter = Mockery::mock(DiscordMessageFormatter::class);
        $formatter->shouldReceive('extractNextAi')->andReturn(null); // 自己メンション時は null が返る

        $useCase = new ProcessDebateTurnUseCase($repository, $difyAdapter, $discordAdapter, $formatter);

        // Execute
        $useCase->execute($sessionId, TargetAi::PHI);

        // Assert
        // 自己メンションがブロックされ、ループが停止することを確認
        $this->assertTrue($session->isCompleted());
        Queue::assertNotPushed(ProcessDebateTurn::class);
    }
}
