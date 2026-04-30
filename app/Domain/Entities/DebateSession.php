<?php

declare(strict_types=1);

namespace App\Domain\Entities;

use App\Domain\Enums\TargetAi;

class DebateSession
{
    public function __construct(
        public ?int $id,
        public readonly string $topic,
        public ?string $discordThreadId,
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

        // 順番にAIを選択 (Gemma -> Phi -> Llama)
        $aiSequence = [TargetAi::GEMMA, TargetAi::PHI, TargetAi::LLAMA];
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
