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

        $response = $this->postJson('/api/discord/interactions', [
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

        $response->assertStatus(200)
            ->assertJson([
                'type' => 4,
                'data' => [
                    'content' => "🤖 議題『{$topic}』を受け付けました！新規チャンネルを作成してAIたちを呼び出します..."
                ]
            ]);

        \Illuminate\Support\Facades\Queue::assertPushed(\App\Presentation\Jobs\StartDebateJob::class, function ($job) use ($topic) {
            return $job->topic === $topic && $job->initialAi === null;
        });
    }

    public function test_handle_starts_debate_with_model_option(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $topic = 'AIの未来について';
        $model = 'gemini';

        $response = $this->postJson('/api/discord/interactions', [
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

        \Illuminate\Support\Facades\Queue::assertPushed(\App\Presentation\Jobs\StartDebateJob::class, function ($job) use ($topic, $model) {
            return $job->topic === $topic && $job->initialAi === $model;
        });
    }

    public function test_handle_returns_401_on_missing_signature(): void
    {
        $response = $this->postJson('/api/discord/interactions', [
            'type' => 1
        ]);

        $response->assertStatus(401);
    }
}
