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
