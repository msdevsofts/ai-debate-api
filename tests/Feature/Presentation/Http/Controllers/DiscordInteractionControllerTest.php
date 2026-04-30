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
        $useCase = Mockery::mock(StartDebateUseCase::class);
        $this->app->instance(StartDebateUseCase::class, $useCase);

        $topic = 'AIの未来について';
        $threadId = 'thread_456';

        $useCase->shouldReceive('execute')->once()->with($topic)->andReturn($threadId);

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
                    'content' => "専用スレッドを作成しました: <#{$threadId}>"
                ]
            ]);
    }

    public function test_handle_returns_401_on_missing_signature(): void
    {
        $response = $this->postJson('/api/discord/interactions', [
            'type' => 1
        ]);

        $response->assertStatus(401);
    }
}
