<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Services;

use App\Domain\Enums\TargetAi;
use App\Domain\Services\DiscordMessageFormatter;
use Tests\TestCase;

class DiscordMessageFormatterTest extends TestCase
{
    private DiscordMessageFormatter $formatter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->formatter = new DiscordMessageFormatter();

        // configのセットアップ
        config(['services.discord.bot_ids' => [
            '111' => 'gemini',
            '222' => 'gemma',
            '333' => 'phi',
            '444' => 'llama',
            '555' => 'gpt-oss-q2', // アンダースコアをハイフンに修正（本番のボット名に合わせる）
        ]]);
    }

    public function test_format_replaces_mentions_correctly(): void
    {
        $content = "こんにちは <@999> さん。";
        // phi (333) へのメンションに置き換わることを期待
        $result = $this->formatter->format($content, TargetAi::PHI);

        $this->assertStringContainsString('<@333>', $result);
        $this->assertStringNotContainsString('<@999>', $result);
    }

    public function test_format_appends_mention_if_none_exists(): void
    {
        $content = "こんにちは。";
        $result = $this->formatter->format($content, TargetAi::PHI);

        $this->assertStringContainsString('<@333>', $result);
    }

    public function test_format_removes_name_artifacts(): void
    {
        $content = "こんにちは @Phi (Phi) <@333>";
        $result = $this->formatter->format($content, TargetAi::PHI);

        $this->assertStringNotContainsString('@Phi', $result);
        $this->assertStringNotContainsString('(Phi)', $result);
        $this->assertStringContainsString('<@333>', $result);
    }

    public function test_extractNextAi_identifies_target_correctly(): void
    {
        $content = "次は <@333> さん、お願いします。";
        $result = $this->formatter->extractNextAi($content, TargetAi::GEMINI);

        $this->assertEquals(TargetAi::PHI, $result);
    }

    public function test_extractNextAi_falls_back_on_self_mention(): void
    {
        $content = "次は <@333> さん（自分）にお願い。";
        // 現在の発言者が Phi (333) の場合、自己メンションが検出されるが
        // 実装ではランダムフォールバックが走るため、nullにはならないはず（他のAIが設定されていれば）
        $result = $this->formatter->extractNextAi($content, TargetAi::PHI);

        $this->assertNotNull($result);
        $this->assertNotEquals(TargetAi::PHI, $result);
    }

    public function test_extractNextAi_falls_back_to_random_when_no_mention(): void
    {
        $content = "メンションがありません。";
        // Gemini (111) からの呼び出しで、ランダムに他のAI（gemma, phi, llama, gpt-oss-q2）のいずれかが選ばれる
        $result = $this->formatter->extractNextAi($content, TargetAi::GEMINI);

        $this->assertNotNull($result);
        $this->assertNotEquals(TargetAi::GEMINI, $result);
    }

    public function test_extractAndRemoveMentions_works_correctly(): void
    {
        $content = "こんにちは <@333> さん。 (Phi) @Phi どうですか？";
        [$mention, $cleaned] = $this->formatter->extractAndRemoveMentions($content);

        $this->assertEquals('<@333>', $mention);
        $this->assertEquals('こんにちは さん。 どうですか？', $cleaned);
    }

    public function test_extractAndRemoveMentions_preserves_newlines(): void
    {
        $content = "第一行\n第二行 <@333>\n第三行";
        [$mention, $cleaned] = $this->formatter->extractAndRemoveMentions($content);

        $this->assertEquals('<@333>', $mention);
        $this->assertEquals("第一行\n第二行 \n第三行", $cleaned);
    }

    public function test_splitMessage_splits_long_text(): void
    {
        $longText = str_repeat("あ", 1000) . "\n" . str_repeat("い", 1000);
        $chunks = $this->formatter->splitMessage($longText, 1100);

        $this->assertCount(2, $chunks);
        $this->assertEquals(str_repeat("あ", 1000) . "\n", $chunks[0]);
        $this->assertEquals(str_repeat("い", 1000), $chunks[1]);
    }

    public function test_splitMessage_splits_at_period(): void
    {
        $longText = str_repeat("あ", 1000) . "。" . str_repeat("い", 1000);
        $chunks = $this->formatter->splitMessage($longText, 1100);

        $this->assertCount(2, $chunks);
        $this->assertEquals(str_repeat("あ", 1000) . "。", $chunks[0]);
        $this->assertEquals(str_repeat("い", 1000), $chunks[1]);
    }
}
