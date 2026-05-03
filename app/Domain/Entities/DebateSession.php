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
        public ?string $discordWebhookUrl,
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

        // 最初のターン（currentTurn=0）は必ずGeminiが議題を提示する
        if ($this->currentTurn === 0) {
            return TargetAi::GEMINI;
        }

        // 司会(Gemini)を除いた参加者のリスト
        $participants = [
            TargetAi::GEMMA,
            TargetAi::PHI,
            TargetAi::LLAMA,
            TargetAi::GPT_OSS_Q2,
        ];

        // 1ターン目以降は参加者が順番に発言する
        // (currentTurn - 1) % count($participants) で司会以外のAIをローテーション
        $index = ($this->currentTurn - 1) % count($participants);
        if ($index < 0) {
            $index = 0;
        }

        return $participants[$index];
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
