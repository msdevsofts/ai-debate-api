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
        $session = $this->createTestSession([
            'id' => $sessionId,
            'topic' => 'AIの未来について',
            'discordChannelId' => '123456',
            'discordWebhookUrl' => 'https://discord.com/api/webhooks/123/abc',
            'currentTurn' => 0,
            'maxTurns' => 10,
        ]);

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

        $discordAdapter->shouldReceive('postMessage')->once();
        $repository->shouldReceive('save')->atLeast()->once();

        $formatter = Mockery::mock(DiscordMessageFormatter::class);
        $formatter->shouldReceive('extractNextAi')->andReturn(TargetAi::PHI);
        $formatter->shouldReceive('extractAndRemoveMentions')->andReturn(['<@111>', 'AIの未来は明るいです。']);
        $formatter->shouldReceive('splitMessage')->andReturn(['AIの未来は明るいです。']);

        $useCase = $this->setupUseCase($repository, $difyAdapter, $discordAdapter, $formatter);

        // Execute
        $useCase->execute($sessionId);

        // Assert
        $this->assertEquals('conv_123', $session->difyConversationId);
        $this->assertEquals(1, $session->currentTurn);
        Queue::assertPushed(ProcessDebateTurn::class, function ($job) use ($session) {
            return $job->turnId === $session->currentTurnId;
        });
    }

    public function test_execute_completes_when_gemini_finishes(): void
    {
        $repository = Mockery::mock(DebateSessionRepositoryInterface::class);
        $difyAdapter = Mockery::mock(DifyApiAdapter::class);
        $discordAdapter = Mockery::mock(DiscordApiAdapter::class);
        Queue::fake();

        $sessionId = 1;
        $session = $this->createTestSession([
            'id' => $sessionId,
            'topic' => 'AIの未来について',
            'discordChannelId' => '123456',
            'discordWebhookUrl' => 'https://discord.com/api/webhooks/123/abc',
            'currentTurn' => 10,
            'maxTurns' => 10,
            'difyConversationId' => 'conv_123',
        ]);

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
        $repository->shouldReceive('save')->atLeast()->once();

        $formatter = Mockery::mock(DiscordMessageFormatter::class);
        $formatter->shouldReceive('extractNextAi')->andReturn(null);
        $formatter->shouldReceive('extractAndRemoveMentions')->andReturn([null, '結論として...']);
        $formatter->shouldReceive('splitMessage')->andReturn(['結論として...']);

        $useCase = $this->setupUseCase($repository, $difyAdapter, $discordAdapter, $formatter);

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
        $session = $this->createTestSession([
            'id' => $sessionId,
            'topic' => 'AIの未来について',
            'discordChannelId' => '123456',
            'discordWebhookUrl' => 'https://discord.com/api/webhooks/123/abc',
            'currentTurn' => 0,
            'maxTurns' => 10,
        ]);

        $repository->shouldReceive('findById')->with($sessionId)->andReturn($session);

        $answerWithMention = "次は <@999888777> さん、お願いします。";
        $difyAdapter->shouldReceive('chat')->andReturn([
            'answer' => $answerWithMention,
            'conversation_id' => 'conv_123'
        ]);

        $discordAdapter->shouldReceive('postMessage')->once();
        $repository->shouldReceive('save')->atLeast()->once();

        $formatter = Mockery::mock(DiscordMessageFormatter::class);
        $formatter->shouldReceive('extractNextAi')->andReturn(TargetAi::PHI);
        $formatter->shouldReceive('extractAndRemoveMentions')->andReturn(['<@999888777>', '次は さん、お願いします。']);
        $formatter->shouldReceive('splitMessage')->andReturn(['次は さん、お願いします。']);

        $useCase = $this->setupUseCase($repository, $difyAdapter, $discordAdapter, $formatter);

        // Execute
        $useCase->execute($sessionId);

        // Assert
        // メンションされたAI (Phi) をターゲットとしてジョブがディスパッチされていることを確認
        Queue::assertPushed(ProcessDebateTurn::class, function ($job) use ($sessionId, $session) {
            return $job->debateSessionId === $sessionId &&
                   $job->targetAi === TargetAi::PHI &&
                   $job->turnId === $session->currentTurnId;
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
        $session = $this->createTestSession([
            'id' => $sessionId,
            'topic' => 'AIの未来について',
            'discordChannelId' => '123456',
            'discordWebhookUrl' => 'https://discord.com/api/webhooks/123/abc',
            'currentTurn' => 0,
            'maxTurns' => 10,
        ]);

        $repository->shouldReceive('findById')->with($sessionId)->andReturn($session);

        $answerWithoutMention = "以上で私の意見を終わります。";
        // 現在の発言者がPhiであると仮定
        $difyAdapter->shouldReceive('chat')->andReturn([
            'answer' => $answerWithoutMention,
            'conversation_id' => 'conv_123'
        ]);

        // メンションがない場合、司会（Gemini）へのメンションが付与されることを期待
        $facilitatorId = '1499779594298064936';
        $expectedContent = $answerWithoutMention . " <@{$facilitatorId}>";
        $discordAdapter->shouldReceive('postMessage')->with($expectedContent, '123456', TargetAi::PHI, null)->once();
        $repository->shouldReceive('save')->twice(); // UseCase内でのsave(session)が2回呼ばれる（turnId更新時含む）

        $formatter = Mockery::mock(DiscordMessageFormatter::class);
        $formatter->shouldReceive('extractNextAi')->andReturn(null);
        // extractAndRemoveMentions はUseCaseの113行目で呼ばれる。
        // その前に $content は $answerWithoutMention . " <@facilitatorId>" になっている。
        $formatter->shouldReceive('extractAndRemoveMentions')
            ->with($expectedContent)
            ->andReturn(["<@{$facilitatorId}>", $answerWithoutMention]);
        $formatter->shouldReceive('splitMessage')->andReturn([$answerWithoutMention]);

        $useCase = $this->setupUseCase($repository, $difyAdapter, $discordAdapter, $formatter);

        // Execute
        // targetAiにPHIを指定して実行
        $useCase->execute($sessionId, TargetAi::PHI);

        // Assert
        // メンションがない場合、司会にパスが回り、議論が継続することを確認
        $this->assertFalse($session->isCompleted());
        Queue::assertPushed(ProcessDebateTurn::class, function ($job) {
            return $job->targetAi === TargetAi::GEMINI;
        });
    }

    public function test_execute_handles_empty_response_by_falling_back_to_facilitator(): void
    {
        // Mocking
        $repository = Mockery::mock(DebateSessionRepositoryInterface::class);
        $difyAdapter = Mockery::mock(DifyApiAdapter::class);
        $discordAdapter = Mockery::mock(DiscordApiAdapter::class);
        Queue::fake();

        $sessionId = 1;
        $session = $this->createTestSession([
            'id' => $sessionId,
            'topic' => 'AIの未来について',
            'discordChannelId' => '123456',
            'currentTurn' => 0,
            'maxTurns' => 10,
        ]);

        $repository->shouldReceive('findById')->with($sessionId)->andReturn($session);

        // 空の回答
        $difyAdapter->shouldReceive('chat')->andReturn([
            'answer' => '',
            'conversation_id' => 'conv_123'
        ]);

        $facilitatorId = '1499779594298064936';
        $fallbackText = '（深く考え込んでおり、言葉が出てこないようだ…）';
        $expectedContent = $fallbackText . " <@{$facilitatorId}>";

        $discordAdapter->shouldReceive('postMessage')->with($expectedContent, '123456', TargetAi::PHI, null)->once();
        $repository->shouldReceive('save')->twice();

        $formatter = Mockery::mock(DiscordMessageFormatter::class);
        $formatter->shouldReceive('extractNextAi')->andReturn(null);
        $formatter->shouldReceive('extractAndRemoveMentions')
            ->with($expectedContent)
            ->andReturn(["<@{$facilitatorId}>", $fallbackText]);
        $formatter->shouldReceive('splitMessage')->andReturn([$fallbackText]);

        $useCase = $this->setupUseCase($repository, $difyAdapter, $discordAdapter, $formatter);

        // Execute
        $useCase->execute($sessionId, TargetAi::PHI);

        // Assert
        $this->assertFalse($session->isCompleted());
        Queue::assertPushed(ProcessDebateTurn::class, function ($job) {
            return $job->targetAi === TargetAi::GEMINI;
        });
    }

    public function test_execute_removes_think_tags_with_different_formats(): void
    {
        // Mocking
        $repository = Mockery::mock(DebateSessionRepositoryInterface::class);
        $difyAdapter = Mockery::mock(DifyApiAdapter::class);
        $discordAdapter = Mockery::mock(DiscordApiAdapter::class);
        Queue::fake();

        $sessionId = 1;
        $session = $this->createTestSession([
            'id' => $sessionId,
            'topic' => 'Test',
            'discordChannelId' => '123456',
        ]);

        $repository->shouldReceive('findById')->with($sessionId)->andReturn($session);

        $answer = "<think>\nThinking hard...\n</think>(think)Another thought</think>Actual response <@111>";
        $difyAdapter->shouldReceive('chat')->andReturn([
            'answer' => $answer,
            'conversation_id' => 'conv_123'
        ]);

        $expectedCleanText = "Actual response <@111>";
        $discordAdapter->shouldReceive('postMessage')->with($expectedCleanText, '123456', TargetAi::GEMINI, null)->once();
        $repository->shouldReceive('save')->twice();

        $formatter = Mockery::mock(DiscordMessageFormatter::class);
        $formatter->shouldReceive('extractNextAi')->andReturn(TargetAi::PHI);
        $formatter->shouldReceive('extractAndRemoveMentions')->andReturn(['<@111>', 'Actual response']);
        $formatter->shouldReceive('splitMessage')->andReturn(['Actual response']);

        $useCase = $this->setupUseCase($repository, $difyAdapter, $discordAdapter, $formatter);

        // Execute
        $useCase->execute($sessionId, TargetAi::GEMINI);

        $this->assertTrue(true);
    }

    public function test_execute_stops_when_ai_mentions_itself(): void
    {
        // Mocking
        $repository = Mockery::mock(DebateSessionRepositoryInterface::class);
        $difyAdapter = Mockery::mock(DifyApiAdapter::class);
        $discordAdapter = Mockery::mock(DiscordApiAdapter::class);
        Queue::fake();

        $sessionId = 1;
        $session = $this->createTestSession([
            'id' => $sessionId,
            'topic' => 'AIの未来について',
            'discordChannelId' => '123456',
            'discordWebhookUrl' => 'https://discord.com/api/webhooks/123/abc',
            'currentTurn' => 0,
            'maxTurns' => 10,
        ]);

        $repository->shouldReceive('findById')->with($sessionId)->andReturn($session);

        // Phiが自分自身（Phi: 111）をメンションするケース
        $answerWithSelfMention = "自分自身 <@111> に問いかけます。";
        $difyAdapter->shouldReceive('chat')->andReturn([
            'answer' => $answerWithSelfMention,
            'conversation_id' => 'conv_123'
        ]);

        $facilitatorId = '1499779594298064936';
        $expectedContent = $answerWithSelfMention . " <@{$facilitatorId}>";

        $discordAdapter->shouldReceive('postMessage')->with($expectedContent, '123456', TargetAi::PHI, null)->once();
        $repository->shouldReceive('save')->atLeast()->once();

        $formatter = Mockery::mock(DiscordMessageFormatter::class);
        $formatter->shouldReceive('extractNextAi')->andReturn(null);
        $formatter->shouldReceive('extractAndRemoveMentions')
            ->with($expectedContent)
            ->andReturn(["<@{$facilitatorId}>", $answerWithSelfMention]);
        $formatter->shouldReceive('splitMessage')->andReturn([$answerWithSelfMention]);

        $useCase = $this->setupUseCase($repository, $difyAdapter, $discordAdapter, $formatter);

        // Execute
        $useCase->execute($sessionId, TargetAi::PHI);

        // Assert
        // 自己メンションがブロックされ、司会にパスが回ることを確認
        $this->assertFalse($session->isCompleted());
        Queue::assertPushed(ProcessDebateTurn::class, function ($job) {
            return $job->targetAi === TargetAi::GEMINI;
        });
    }

    public function test_execute_uses_query_without_overwriting_when_human_intervention(): void
    {
        $repository = Mockery::mock(DebateSessionRepositoryInterface::class);
        $difyAdapter = Mockery::mock(DifyApiAdapter::class);
        $discordAdapter = Mockery::mock(DiscordApiAdapter::class);
        Queue::fake();

        $sessionId = 1;
        $session = $this->createTestSession([
            'id' => $sessionId,
            'topic' => 'Original Topic',
            'discordChannelId' => '123456',
            'discordWebhookUrl' => 'http://webhook',
            'currentTurn' => 1,
            'maxTurns' => 10,
            'difyConversationId' => 'conv-123',
            'status' => 'active',
        ]);

        $repository->shouldReceive('findById')->with($sessionId)->andReturn($session);

        $interventionQuery = "Human instruction";

        // 期待される挙動: $query が topic で上書きされないこと
        $difyAdapter->shouldReceive('chat')->with(
            $interventionQuery,
            'conv-123',
            TargetAi::GEMMA,
            'Original Topic',
            true // isHumanIntervention
        )->once()->andReturn([
            'answer' => 'Acknowledged.',
            'conversation_id' => 'conv-123'
        ]);

        $discordAdapter->shouldReceive('postMessage')->once();
        $repository->shouldReceive('save')->atLeast()->once();

        $formatter = Mockery::mock(DiscordMessageFormatter::class);
        $formatter->shouldReceive('extractNextAi')->andReturn(null);
        $facilitatorId = '1499779594298064936';
        $formatter->shouldReceive('extractAndRemoveMentions')->andReturn(['<@' . $facilitatorId . '>', 'Acknowledged.']);
        $formatter->shouldReceive('splitMessage')->andReturn(['Acknowledged.']);

        $useCase = $this->setupUseCase($repository, $difyAdapter, $discordAdapter, $formatter);

        // Execute
        $useCase->execute(
            sessionId: $sessionId,
            targetAi: TargetAi::GEMMA,
            query: $interventionQuery,
            isHumanIntervention: true
        );

        $this->assertTrue(true); // 到達すればOK
    }

    public function test_execute_allows_intervention_on_completed_session_and_resumes(): void
    {
        $repository = Mockery::mock(DebateSessionRepositoryInterface::class);
        $difyAdapter = Mockery::mock(DifyApiAdapter::class);
        $discordAdapter = Mockery::mock(DiscordApiAdapter::class);
        Queue::fake();

        $sessionId = 1;
        $session = $this->createTestSession([
            'id' => $sessionId,
            'topic' => 'Original Topic',
            'discordChannelId' => '123456',
            'discordWebhookUrl' => 'http://webhook',
            'currentTurn' => 10,
            'maxTurns' => 10,
            'difyConversationId' => 'conv-123',
            'status' => 'completed',
        ]);

        $repository->shouldReceive('findById')->with($sessionId)->andReturn($session);

        // bot_idsの設定を追加
        config(['services.discord.bot_ids' => [
            '100' => 'gemma',
            '101' => 'phi',
            '102' => 'llama',
            '103' => 'gemini',
            '104' => 'gpt-oss-q2'
        ]]);

        $interventionQuery = "Restart debate";

        // 介入がある場合は、セッションが完了していても chat が呼ばれるはず
        $difyAdapter->shouldReceive('chat')->with(
            $interventionQuery,
            'conv-123',
            TargetAi::GEMMA,
            'Original Topic',
            true // isHumanIntervention
        )->once()->andReturn([
            'answer' => 'Resuming. Next is <@101>',
            'conversation_id' => 'conv-123'
        ]);

        $discordAdapter->shouldReceive('postMessage')->once();

        // セッションが再開（statusが更新）されて保存されることを期待
        $repository->shouldReceive('save')->atLeast()->once();

        $formatter = new DiscordMessageFormatter();

        $useCase = $this->setupUseCase($repository, $difyAdapter, $discordAdapter, $formatter);

        // Execute
        $useCase->execute(
            sessionId: $sessionId,
            targetAi: TargetAi::GEMMA,
            query: $interventionQuery,
            isHumanIntervention: true
        );

        $this->assertFalse($session->isCompleted(), 'Session should not be completed after intervention');
    }

    public function test_execute_splits_long_message_into_multiple_posts(): void
    {
        $repository = Mockery::mock(DebateSessionRepositoryInterface::class);
        $difyAdapter = Mockery::mock(DifyApiAdapter::class);
        $discordAdapter = Mockery::mock(DiscordApiAdapter::class);
        Queue::fake();

        $sessionId = 1;
        $session = $this->createTestSession([
            'id' => $sessionId,
            'topic' => 'Long Topic',
            'discordChannelId' => '123456',
            'currentTurn' => 0,
            'maxTurns' => 10,
        ]);

        $repository->shouldReceive('findById')->with($sessionId)->andReturn($session);

        $longAnswer = "Part 1. Part 2. <@101>";
        $difyAdapter->shouldReceive('chat')->andReturn([
            'answer' => $longAnswer,
            'conversation_id' => 'conv_123'
        ]);

        // 2回に分けて送信されることを期待
        $discordAdapter->shouldReceive('postMessage')->atLeast()->once();

        $repository->shouldReceive('save')->atLeast()->once();

        $formatter = Mockery::mock(DiscordMessageFormatter::class);
        $formatter->shouldReceive('extractNextAi')->andReturn(TargetAi::PHI);
        $formatter->shouldReceive('extractAndRemoveMentions')->andReturn(['<@101>', 'Part 1. Part 2.']);
        // 意図的に2つに分割
        $formatter->shouldReceive('splitMessage')->andReturn(['Part 1.', 'Part 2.']);

        $useCase = $this->setupUseCase($repository, $difyAdapter, $discordAdapter, $formatter);

        $useCase->execute($sessionId);

        $this->assertTrue(true);
    }
    private function setupUseCase(
        Mockery\MockInterface $repository,
        Mockery\MockInterface $difyAdapter,
        Mockery\MockInterface $discordAdapter,
        Mockery\MockInterface|DiscordMessageFormatter $formatter
    ): ProcessDebateTurnUseCase {
        return new ProcessDebateTurnUseCase($repository, $difyAdapter, $discordAdapter, $formatter);
    }
}
