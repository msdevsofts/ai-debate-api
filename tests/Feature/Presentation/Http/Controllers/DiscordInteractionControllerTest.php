<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Http\Controllers;

use App\Application\UseCases\StartDebateUseCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Mockery;

class DiscordInteractionControllerTest extends TestCase
{
    public function test_handle_returns_ping_response(): void
    {
        $response = $this->postJson('/api/discord/interactions', [
            'type' => 1
        ], [
            'X-Signature-Ed25519' => 'dummy',
            'X-Signature-Timestamp' => '12345',
        ]);

        $response->assertStatus(200)
            ->assertJson(['type' => 1]);
    }

    public function test_handle_starts_debate_on_slash_command(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $topic = 'AIの未来について';
        $bot = 'llama';
        $applicationId = '123456789';
        $token = 'interaction_token_abc';

        $response = $this->postJson("/api/discord/interactions?bot={$bot}", [
            'type' => 2,
            'application_id' => $applicationId,
            'token' => $token,
            'data' => [
                'name' => 'discuss',
                'options' => [
                    [
                        'name' => 'topic',
                        'value' => $topic
                    ]
                ]
            ]
        ], [
            'X-Signature-Ed25519' => 'dummy',
            'X-Signature-Timestamp' => '12345',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'type' => 5,
            ]);

        \Illuminate\Support\Facades\Queue::assertPushed(\App\Presentation\Jobs\StartDebateJob::class, function ($job) use ($topic, $bot, $applicationId, $token) {
            return $job->topic === $topic &&
                   $job->initialAi === null &&
                   $job->triggerBot === $bot &&
                   $job->applicationId === $applicationId &&
                   $job->token === $token;
        });
    }

    public function test_handle_starts_debate_with_model_option(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $topic = 'AIの未来について';
        $model = 'gemini';
        $bot = 'gemini';

        $response = $this->postJson("/api/discord/interactions?bot={$bot}", [
            'type' => 2,
            'data' => [
                'name' => 'discuss',
                'options' => [
                    [
                        'name' => 'topic',
                        'value' => $topic
                    ],
                    [
                        'name' => 'model',
                        'value' => $model
                    ]
                ]
            ]
        ], [
            'X-Signature-Ed25519' => 'dummy',
            'X-Signature-Timestamp' => '12345',
        ]);

        $response->assertStatus(200);

        \Illuminate\Support\Facades\Queue::assertPushed(\App\Presentation\Jobs\StartDebateJob::class, function ($job) use ($topic, $model, $bot) {
            return $job->topic === $topic && $job->initialAi === $model && $job->triggerBot === $bot;
        });
    }

    public function test_handle_with_gpt_oss_q2_env_key(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $bot = 'gpt-oss-q2';
        $topic = 'test';

        $response = $this->postJson("/api/discord/interactions?bot={$bot}", [
            'type' => 2,
            'data' => [
                'name' => 'discuss',
                'options' => [
                    [
                        'name' => 'topic',
                        'value' => $topic
                    ]
                ]
            ]
        ], [
            'X-Signature-Ed25519' => 'dummy',
            'X-Signature-Timestamp' => '12345',
        ]);

        $response->assertStatus(200);

        \Illuminate\Support\Facades\Queue::assertPushed(\App\Presentation\Jobs\StartDebateJob::class, function ($job) use ($topic, $bot) {
            return $job->topic === $topic && $job->triggerBot === $bot;
        });
    }
    public function test_handle_returns_401_on_missing_signature(): void
    {
        $response = $this->postJson('/api/discord/interactions', [
            'type' => 2, // APPLICATION_COMMAND など、PING以外のタイプ
            'data' => ['name' => 'discuss']
        ]);

        $response->assertStatus(401);
    }
}
