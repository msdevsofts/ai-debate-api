<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Adapters;

use App\Infrastructure\Adapters\DifyApiAdapter;
use App\Domain\Enums\TargetAi;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DifyApiAdapterNewlineTest extends TestCase
{
    public function test_chat_replaces_escaped_newlines_with_actual_newlines(): void
    {
        // 1. Setup mock with escaped newlines in 'answer'
        Http::fake([
            '*/chat-messages' => Http::response([
                'answer' => "Line 1\\nLine 2\\r\\nLine 3",
                'conversation_id' => 'conv_123'
            ], 200)
        ]);

        $adapter = new DifyApiAdapter();

        // 2. Execute
        $result = $adapter->chat('query', null, TargetAi::GEMMA, 'topic');

        // 3. Assert
        // The escaped "\n" and "\r\n" should be replaced with actual newline characters
        $this->assertEquals("Line 1\nLine 2\nLine 3", $result['answer']);
    }

    public function test_chat_does_not_break_markdown_while_replacing_newlines(): void
    {
        Http::fake([
            '*/chat-messages' => Http::response([
                'answer' => "**Bold Text**\\n*Italic*\\n- List Item",
                'conversation_id' => 'conv_123'
            ], 200)
        ]);

        $adapter = new DifyApiAdapter();
        $result = $adapter->chat('query', null, TargetAi::GEMMA, 'topic');

        $this->assertEquals("**Bold Text**\n*Italic*\n- List Item", $result['answer']);
    }
}
