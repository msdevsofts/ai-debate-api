<?php

declare(strict_types=1);

namespace App\Domain\Services;

use App\Domain\Enums\TargetAi;

class DiscordMessageFormatter
{
    /**
     * AIの回答をDiscord向けに整形する
     * メンションの同期、名前のクリーンアップ、文字数制限のための分割を行う
     */
    public function format(string $content, TargetAi $nextAi): string
    {
        $nextBotId = $nextAi->getBotId();
        if (!$nextBotId || $nextAi === TargetAi::GEMINI_CONCLUSION) {
            return $content;
        }

        $newMention = "<@{$nextBotId}>";

        // 1. テキスト内に何らかのメンションタグ（ <@\d+> または <@!\d+> ）が存在する場合、すべて置換
        if (preg_match('/<@!?\d+>/', $content)) {
            $content = preg_replace('/<@!?\d+>/', $newMention, $content);
        } else {
            // 2. メンションタグが全く無かった場合は、末尾に追記
            $content .= " {$newMention}";
        }

        // 3. 名前の残骸（@Name, (Name)）を除去
        foreach (TargetAi::cases() as $case) {
            $name = preg_quote($case->getName(), '/');
            // 「@Name」や「(Name)」を正規表現で除去（大文字小文字を区別しない）
            $content = preg_replace("/@{$name}/i", '', $content);
            $content = preg_replace("/\({$name}\)/i", '', $content);
        }

        // 余分な空白を整理（改行は維持）
        return trim(preg_replace('/[ \t]+/', ' ', $content));
    }

    /**
     * テキストからメンション（<@数字>）を抽出し、元のテキストから削除する。
     * 抽出したメンション文字列（またはnull）と、クリーンアップ後のテキストを返す。
     *
     * @return array{0: string|null, 1: string} [mention, cleaned_text]
     */
    public function extractAndRemoveMentions(string $content): array
    {
        $mention = null;
        if (preg_match('/<@!?(\d+)>/', $content, $matches)) {
            $mention = $matches[0];
            $content = preg_replace('/<@!?\d+>/', '', $content);
        }

        // 名前の残骸も除去
        foreach (TargetAi::cases() as $case) {
            $name = preg_quote($case->getName(), '/');
            $content = preg_replace("/@{$name}/i", '', $content);
            $content = preg_replace("/\({$name}\)/i", '', $content);
        }

        return [$mention, trim(preg_replace('/[ \t]+/', ' ', $content))];
    }

    /**
     * テキストを最大文字数ごとに分割する。
     * 改行や句点を優先して分割を試みる。
     */
    public function splitMessage(string $text, int $maxLength = 1900): array
    {
        if (mb_strlen($text) <= $maxLength) {
            return [$text];
        }

        $chunks = [];
        while (mb_strlen($text) > 0) {
            if (mb_strlen($text) <= $maxLength) {
                $chunks[] = $text;
                break;
            }

            // 指定された最大長までの部分を取得
            $candidate = mb_substr($text, 0, $maxLength);

            // 分割の優先順位: 1. 改行 (\n), 2. 句点 (。)
            $splitPos = mb_strrpos($candidate, "\n");
            if ($splitPos === false || $splitPos === 0) {
                $splitPos = mb_strrpos($candidate, "。");
            }

            // 分割ポイントが見つからない場合は、最大長でぶつ切り
            if ($splitPos === false || $splitPos === 0) {
                $splitPos = $maxLength - 1;
            }

            $chunks[] = mb_substr($text, 0, $splitPos + 1);
            $text = mb_substr($text, $splitPos + 1);
        }

        return array_filter($chunks);
    }

    /**
     * メッセージ内容から次に発言すべきAIを特定する
     */
    public function extractNextAi(string $content, TargetAi $currentAi, int $currentTurn = 0): ?TargetAi
    {
        // 1. <@ID> または <@!ID> 形式を正規表現ですべて抽出
        if (preg_match_all('/<@!?(\d+)>/', $content, $matches)) {
            $mentionedIds = $matches[1];

            // 抽出したIDリストの「最初の1つ」をターゲットとする
            $targetId = (string)$mentionedIds[0];
            $targetAi = TargetAi::fromBotId($targetId);

            // 自己メンションのチェック
            if ($targetAi !== null && $targetAi === $currentAi) {
                \Illuminate\Support\Facades\Log::warning("AI mentioned itself. Blocking self-loop.", [
                    'bot_id' => $targetId,
                    'ai' => $targetAi->value,
                    'current_ai' => $currentAi->value
                ]);
                // 自己メンションの場合はループ停止へ
                return null;
            } elseif ($targetAi !== null) {
                return $targetAi;
            }
        }

        // メンションが見つからなかった場合
        \Illuminate\Support\Facades\Log::info("有効なメンションが見つからなかったため、ループを停止します。");

        return null;
    }
}
