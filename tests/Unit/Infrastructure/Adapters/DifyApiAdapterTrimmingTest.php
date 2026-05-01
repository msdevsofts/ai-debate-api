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
                'answer' => "(think) I am thinking\n\nActual answer here",
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
                'answer' => "<think>Initial thoughts</think>\n\n(think) Additional thoughts\n\nFinal conclusion",
                'conversation_id' => 'conv_123'
            ], 200)
        ]);

        $adapter = new DifyApiAdapter();
        $result = $adapter->chat('query', null, TargetAi::GEMMA, 'topic');

        $this->assertEquals('Final conclusion', $result['answer']);
    }

    public function test_chat_trims_multiple_consecutive_think_tags(): void
    {
        $input = <<<EOD
<think>
Thinking block 1
</think>
</think>
</think>
</think>
Final actual response
EOD;

        Http::fake([
            '*/chat-messages' => Http::response([
                'answer' => $input,
                'conversation_id' => 'conv_123'
            ], 200)
        ]);

        $adapter = new DifyApiAdapter();
        $result = $adapter->chat('query', null, TargetAi::GEMMA, 'topic');

        $this->assertEquals('Final actual response', $result['answer']);
    }

    public function test_chat_handles_broken_tags_from_issue(): void
    {
        $input = <<<EOD
この繰り返し「チャンネル化テスト4」の場合、データ分析の視点では、ユーザーの期待やコンテンツ生成のパフォーマンスを理解する必要があります。
... (省略) ...
評価する必要性を評価する必要があります。
</think>この繰り返し「チャンネル化テスト4」は、ユーザーの期待やコンテンツ生成のパフォーマンスを理解するためのデータ分析の重要なステップである可能性があります。
... (省略) ...
</think>データ分析者は、「チャンネル化テスト4」入力を分析し、その目的と内容を理解することから始めます。
... (省略) ...
</think>
チャンネル化テスト4
この入力はUser が新しいコンテンツの生成アルゴリズムやユーザーインタラクションデザインを改善するための重要なステップです。これにより、User の期待やコンテンツ生成パフォーマンスに関する洞察が得られます。
EOD;

        Http::fake([
            '*/chat-messages' => Http::response([
                'answer' => $input,
                'conversation_id' => 'conv_123'
            ], 200)
        ]);

        $adapter = new DifyApiAdapter();
        $result = $adapter->chat('query', null, TargetAi::GEMMA, 'topic');

        // Check if common remnants are removed
        $this->assertStringNotContainsString('</think>', $result['answer']);
        $this->assertStringContainsString('チャンネル化テスト4', $result['answer']);
    }

    public function test_chat_trims_endthinkflag_successfully(): void
    {
        $input = <<<EOD
Thinking process 1
[ENDTHINKFLAG]
Additional thinking process 2
[ENDTHINKFLAG]

Final clean response
EOD;

        Http::fake([
            '*/chat-messages' => Http::response([
                'answer' => $input,
                'conversation_id' => 'conv_123'
            ], 200)
        ]);

        $adapter = new DifyApiAdapter();
        $result = $adapter->chat('query', null, TargetAi::GEMMA, 'topic');

        $this->assertEquals('Final clean response', $result['answer']);
    }

    public function test_chat_fallback_when_answer_is_empty_after_trimming(): void
    {
        Http::fake([
            '*/chat-messages' => Http::response([
                'answer' => "<think>I thought but have no conclusion</think>",
                'conversation_id' => 'conv_123'
            ], 200)
        ]);

        $adapter = new DifyApiAdapter();
        $result = $adapter->chat('query', null, TargetAi::GEMMA, 'topic');

        $this->assertEquals('（AIが思考のみを出力しました。結論を生成中です...）', $result['answer']);
    }

    public function test_chat_fallback_with_endthinkflag_remnant(): void
    {
        Http::fake([
            '*/chat-messages' => Http::response([
                'answer' => "Internal thought [ENDTHINKFLAG]",
                'conversation_id' => 'conv_123'
            ], 200)
        ]);

        $adapter = new DifyApiAdapter();
        $result = $adapter->chat('query', null, TargetAi::GEMMA, 'topic');

        // [ENDTHINKFLAG] より前が抽出される（300文字以内）
        $this->assertEquals('Internal thought', $result['answer']);
    }

    public function test_chat_fallback_with_multiple_endthinkflag_remnant(): void
    {
        $thought = str_repeat('Long thought process... ', 20); // 24 * 20 = 480 chars
        Http::fake([
            '*/chat-messages' => Http::response([
                'answer' => "First block [ENDTHINKFLAG] $thought [ENDTHINKFLAG]",
                'conversation_id' => 'conv_123'
            ], 200)
        ]);

        $adapter = new DifyApiAdapter();
        $result = $adapter->chat('query', null, TargetAi::GEMMA, 'topic');

        // 最後の思考ブロックが300文字に制限される
        $this->assertEquals(300, mb_strlen($result['answer']));
        $this->assertStringContainsString('Long thought process...', $result['answer']);
    }
}
