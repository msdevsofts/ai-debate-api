<?php

declare(strict_types=1);

namespace App\Domain\Services;

use App\Domain\Enums\TargetAi;

class DiscordMessageFormatter
{
    /**
     * AIの回答をDiscord向けに整形する（メンションの同期、名前のクリーンアップ）
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

        // 余分な空白を整理
        return trim(preg_replace('/\s+/', ' ', $content));
    }

    /**
     * メッセージ内容から次に発言すべきAIを特定する
     */
    public function extractNextAi(string $content, TargetAi $currentAi): ?TargetAi
    {
        // 1. <@ID> または <@!ID> 形式を正規表現ですべて抽出
        if (preg_match_all('/<@!?(\d+)>/', $content, $matches)) {
            $mentionedIds = $matches[1];

            // 抽出したIDリストの「最初の1つ」をターゲットとする
            $targetId = (string)$mentionedIds[0];
            $targetAi = TargetAi::fromBotId($targetId);

            // 自己メンションのチェック
            if ($targetAi !== null && $targetAi === $currentAi) {
                return null;
            }

            if ($targetAi !== null) {
                return $targetAi;
            }
        }

        // メンションが見つからなかった、または自己メンションだった場合

        // 現在の発言者が「Gemini（司会）」である場合：意図的な議論終了
        if ($currentAi === TargetAi::GEMINI || $currentAi === TargetAi::GEMINI_CONCLUSION) {
            return null;
        }

        // フォールバック：自分とGemini以外のAIからランダムに選択
        $botIds = config('services.discord.bot_ids', []);
        $availableAis = [];

        foreach ($botIds as $id => $name) {
            $ai = TargetAi::fromBotId((string)$id);
            if ($ai && $ai !== $currentAi && $ai !== TargetAi::GEMINI && $ai !== TargetAi::GEMINI_CONCLUSION) {
                $availableAis[] = $ai;
            }
        }

        if (empty($availableAis)) {
            return null;
        }

        return $availableAis[array_rand($availableAis)];
    }
}
