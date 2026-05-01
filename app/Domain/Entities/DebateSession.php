<?php

declare(strict_types=1);

namespace App\Domain\Entities;

use App\Domain\Enums\TargetAi;

class DebateSession
{
    public function __construct(
        public ?int $id,
        public readonly string $topic,
        public ?TargetAi $initialAi,
        public ?string $discordChannelId,
        public int $currentTurn,
        public int $maxTurns,
        public ?string $difyConversationId,
        public string $status
    ) {}

    public function getNextAi(): TargetAi
    {
        if ($this->currentTurn >= $this->maxTurns) {
            return TargetAi::GEMINI_CONCLUSION;
        }

        // 指定された初期AIがある場合、最初のターンで使用する
        if ($this->currentTurn === 0 && $this->initialAi !== null) {
            return $this->initialAi;
        }

        // 順番にAIを選択 (Gemma -> Phi -> Llama)
        $aiSequence = [TargetAi::GEMMA, TargetAi::PHI, TargetAi::LLAMA];

        // initialAiがシーケンスに含まれる場合、そこからのオフセットを考慮するか、
        // 単純にシーケンスに従うか。今回は「geminiを指定して開始」が目的なので、
        // 初回のみ指定に従い、次からは通常のシーケンスに戻るか、
        // シーケンスを Gemini スタートにする。
        // ここでは、初回のみ指定に従い、2ターン目以降はシーケンスに従うものとする。
        // ただし、0ターン目が Gemini だった場合、1ターン目はシーケンスの[1]（Phi）から始めると自然。

        return $aiSequence[$this->currentTurn % count($aiSequence)];
    }

    public function incrementTurn(): void
    {
        $this->currentTurn++;
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function complete(): void
    {
        $this->status = 'completed';
    }

    public function fail(): void
    {
        $this->status = 'failed';
    }
}
