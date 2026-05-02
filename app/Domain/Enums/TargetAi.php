<?php

declare(strict_types=1);

namespace App\Domain\Enums;

enum TargetAi: string
{
    case GEMMA = 'gemma';
    case PHI = 'phi';
    case LLAMA = 'llama';
    case GEMINI = 'gemini';
    case GEMINI_CONCLUSION = 'gemini_conclusion';
    case GPT_OSS_Q2 = 'gpt_oss_q2';

    public function getLabel(): string
    {
        return match ($this) {
            self::GEMMA => '@Gemma',
            self::PHI => '@Phi',
            self::LLAMA => '@Llama',
            self::GEMINI, self::GEMINI_CONCLUSION => '@Gemini',
            self::GPT_OSS_Q2 => '@GPT-OSS-Q2',
        };
    }

    public static function fromMention(string $content): ?self
    {
        foreach (self::cases() as $case) {
            if (stripos($content, $case->getLabel()) !== false) {
                return $case;
            }
        }
        return null;
    }

    public static function fromBotId(string $botId): ?self
    {
        $botIds = config('services.discord.bot_ids', []);

        $botName = $botIds[$botId] ?? null;
        if ($botName === null) {
            return null;
        }

        return match (strtolower((string)$botName)) {
            'gemma' => self::GEMMA,
            'phi' => self::PHI,
            'llama' => self::LLAMA,
            'gemini' => self::GEMINI,
            'gpt_oss_q2', 'gpt-oss-q2' => self::GPT_OSS_Q2,
            default => null,
        };
    }

    public function getName(): string
    {
        return match ($this) {
            self::GEMMA => 'Gemma',
            self::PHI => 'Phi',
            self::LLAMA => 'Llama',
            self::GEMINI => 'Gemini',
            self::GEMINI_CONCLUSION => 'Gemini',
            self::GPT_OSS_Q2 => 'GPT-OSS-Q2',
        };
    }

    public function getBotId(): ?string
    {
        $botIds = config('services.discord.bot_ids', []);
        $name = match ($this) {
            self::GEMMA => 'gemma',
            self::PHI => 'phi',
            self::LLAMA => 'llama',
            self::GEMINI, self::GEMINI_CONCLUSION => 'gemini',
            self::GPT_OSS_Q2 => 'gpt_oss_q2',
        };

        foreach ($botIds as $id => $botName) {
            if (strtolower((string)$botName) === $name) {
                return (string)$id;
            }
        }

        return null;
    }

    public function getAvatarUrl(): string
    {
        // 適切なアバターURLを設定（今回はプレースホルダ）
        return match ($this) {
            self::GEMMA => 'https://api.dicebear.com/7.x/bottts/svg?seed=gemma',
            self::PHI => 'https://api.dicebear.com/7.x/bottts/svg?seed=phi',
            self::LLAMA => 'https://api.dicebear.com/7.x/bottts/svg?seed=llama',
            self::GPT_OSS_Q2 => 'https://api.dicebear.com/7.x/bottts/svg?seed=gpt-oss-q2',
            self::GEMINI, self::GEMINI_CONCLUSION => 'https://api.dicebear.com/7.x/bottts/svg?seed=gemini',
        };
    }
}
