<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Http\Controllers;

use App\Domain\Entities\DebateSession;
use App\Domain\Enums\TargetAi;
use App\Domain\Repositories\DebateSessionRepositoryInterface;
use App\Presentation\Jobs\ProcessDebateTurn;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Mockery;

class DiscordInterveneInteractionTest extends TestCase
{
    public function test_handle_intervene_command_dispatches_job(): void
    {
        Queue::fake();

        $channelId = '1234567890';
        $targetStr = 'gemma';
        $message = 'もっと具体的に話して。';
        $sessionId = 1;

        // リポジトリのモック作成
        $session = $this->createTestSession([
            'id' => $sessionId,
            'topic' => 'テスト議題',
            'initialAi' => TargetAi::GEMINI,
            'discordChannelId' => $channelId,
            'discordWebhookUrl' => 'http://webhook.url',
            'currentTurn' => 1,
            'status' => 'active'
        ]);

        $repository = Mockery::mock(DebateSessionRepositoryInterface::class);
        $repository->shouldReceive('findByDiscordChannelId')
            ->with($channelId)
            ->andReturn($session);

        $this->app->instance(DebateSessionRepositoryInterface::class, $repository);

        $response = $this->postJson("/api/discord/interactions?bot=gemini", [
            'type' => 2,
            'channel_id' => $channelId,
            'data' => [
                'name' => 'intervene',
                'options' => [
                    [
                        'name' => 'target',
                        'value' => $targetStr
                    ],
                    [
                        'name' => 'message',
                        'value' => $message
                    ]
                ]
            ]
        ], $this->getDiscordHeaders());

        $response->assertStatus(200)
            ->assertJson([
                'type' => 4,
                'data' => [
                    'content' => '介入指示を受け付けました。AIの応答をお待ちください。'
                ]
            ]);

        Queue::assertPushed(ProcessDebateTurn::class, function ($job) use ($sessionId, $message) {
            $expectedMessage = "【システム管理者（人間）からの最優先の介入指示】\n" . $message;
            return $job->debateSessionId === $sessionId &&
                   $job->targetAi === TargetAi::GEMMA &&
                   $job->query === $expectedMessage &&
                   $job->isHumanIntervention === true;
        });
    }

    public function test_handle_intervene_command_returns_error_if_message_is_empty(): void
    {
        Queue::fake();

        $channelId = '1234567890';

        $response = $this->postJson("/api/discord/interactions?bot=gemini", [
            'type' => 2,
            'channel_id' => $channelId,
            'data' => [
                'name' => 'intervene',
                'options' => [
                    [
                        'name' => 'target',
                        'value' => 'gemma'
                    ],
                    [
                        'name' => 'message',
                        'value' => ''
                    ]
                ]
            ]
        ], $this->getDiscordHeaders());

        $response->assertStatus(200)
            ->assertJson([
                'type' => 4,
                'data' => [
                    'content' => '指示内容が空です。メッセージを入力してください。',
                    'flags' => 64
                ]
            ]);

        Queue::assertNothingPushed();
    }

    public function test_handle_intervene_command_returns_error_if_session_not_found(): void
    {
        Queue::fake();

        $channelId = 'non-existent-channel';

        $repository = Mockery::mock(DebateSessionRepositoryInterface::class);
        $repository->shouldReceive('findByDiscordChannelId')
            ->with($channelId)
            ->andReturn(null);

        $this->app->instance(DebateSessionRepositoryInterface::class, $repository);

        $response = $this->postJson("/api/discord/interactions?bot=gemini", [
            'type' => 2,
            'channel_id' => $channelId,
            'data' => [
                'name' => 'intervene',
                'options' => [
                    ['name' => 'target', 'value' => 'gemma'],
                    ['name' => 'message', 'value' => 'hi']
                ]
            ]
        ], $this->getDiscordHeaders());

        $response->assertStatus(200)
            ->assertJson([
                'type' => 4,
                'data' => ['content' => 'このチャンネルでは有効なディベートセッションが見つかりませんでした。']
            ]);

        Queue::assertNothingPushed();
    }
}
