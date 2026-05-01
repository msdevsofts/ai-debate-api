<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Adapters;

use App\Infrastructure\Adapters\DifyApiAdapter;
use App\Domain\Enums\TargetAi;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DifyApiAdapterTrimmingTest extends TestCase
{
    public function test_chat_trims_think_tags_successfully(): void
    {
        // 1. Setup mock
        Http::fake([
            '*/chat-messages' => Http::response([
                'answer' => "<think>I should process this request.</think>Actual response content",
                'conversation_id' => 'conv_123'
            ], 200)
        ]);

        $adapter = new DifyApiAdapter();

        // 2. Execute
        $result = $adapter->chat('query', null, TargetAi::GEMMA, 'topic');

        // 3. Assert
        $this->assertEquals('Actual response content', $result['answer']);
    }

    public function test_chat_trims_multiline_think_tags(): void
    {
        Http::fake([
            '*/chat-messages' => Http::response([
                'answer' => "<thought>\nLine 1 of thinking\nLine 2\n</thought>\nCorrect answer",
                'conversation_id' => 'conv_123'
            ], 200)
        ]);

        $adapter = new DifyApiAdapter();
        $result = $adapter->chat('query', null, TargetAi::GEMMA, 'topic');

        $this->assertEquals('Correct answer', $result['answer']);
    }

    public function test_chat_trims_parenthesis_think_successfully(): void
    {
        Http::fake([
            '*/chat-messages' => Http::response([
                'answer' => "(think) I am thinking\nActual answer here",
                'conversation_id' => 'conv_123'
            ], 200)
        ]);

        $adapter = new DifyApiAdapter();
        $result = $adapter->chat('query', null, TargetAi::GEMMA, 'topic');

        $this->assertEquals('Actual answer here', $result['answer']);
    }

    public function test_chat_trims_multiline_parenthesis_think(): void
    {
        Http::fake([
            '*/chat-messages' => Http::response([
                'answer' => "(think)\nThinking step 1\nThinking step 2\n\nResult text",
                'conversation_id' => 'conv_123'
            ], 200)
        ]);

        $adapter = new DifyApiAdapter();
        $result = $adapter->chat('query', null, TargetAi::GEMMA, 'topic');

        // 現在のユーザーの実装ではここが通らない可能性がある
        $this->assertEquals('Result text', $result['answer']);
    }

    public function test_chat_trims_mixed_thinking_formats(): void
    {
        Http::fake([
            '*/chat-messages' => Http::response([
                'answer' => "<think>Initial thoughts</think>\n(think) Additional thoughts\nFinal conclusion",
                'conversation_id' => 'conv_123'
            ], 200)
        ]);

        $adapter = new DifyApiAdapter();
        $result = $adapter->chat('query', null, TargetAi::GEMMA, 'topic');

        $this->assertEquals('Final conclusion', $result['answer']);
    }
}
