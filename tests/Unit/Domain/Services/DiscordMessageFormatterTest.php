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
            '555' => 'gpt_oss_q2',
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

    public function test_extractNextAi_returns_null_on_self_mention(): void
    {
        $content = "次は <@333> さん（自分）にお願い。";
        // 現在の発言者が Phi (333) の場合
        $result = $this->formatter->extractNextAi($content, TargetAi::PHI);

        $this->assertNull($result);
    }

    public function test_extractNextAi_returns_null_if_no_mention(): void
    {
        $content = "メンションがありません。";
        $result = $this->formatter->extractNextAi($content, TargetAi::GEMINI);

        $this->assertNull($result);
    }

    public function test_extractNextAi_handles_exclamation_mark_in_mention(): void
    {
        $content = "次は <@!333> さん。";
        $result = $this->formatter->extractNextAi($content, TargetAi::GEMINI);

        $this->assertEquals(TargetAi::PHI, $result);
    }
}
