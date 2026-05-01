<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Domain\Entities\DebateSession;
use App\Domain\Repositories\DebateSessionRepositoryInterface;
use App\Infrastructure\Eloquent\DebateSessionModel;

class DebateSessionRepository implements DebateSessionRepositoryInterface
{
    public function findById(int $id): ?DebateSession
    {
        $model = DebateSessionModel::find($id);
        if (!$model) {
            return null;
        }

        return $this->toEntity($model);
    }

    public function save(DebateSession $session): DebateSession
    {
        $model = DebateSessionModel::updateOrCreate(
            ['id' => $session->id],
            [
                'topic' => $session->topic,
                'initial_ai' => $session->initialAi?->value,
                'discord_thread_id' => $session->discordThreadId,
                'current_turn' => $session->currentTurn,
                'max_turns' => $session->maxTurns,
                'dify_conversation_id' => $session->difyConversationId,
                'status' => $session->status,
            ]
        );

        return $this->toEntity($model);
    }

    private function toEntity(DebateSessionModel $model): DebateSession
    {
        return new DebateSession(
            id: $model->id,
            topic: $model->topic,
            initialAi: $model->initial_ai ? TargetAi::from($model->initial_ai) : null,
            discordThreadId: $model->discord_thread_id,
            currentTurn: $model->current_turn,
            maxTurns: $model->max_turns,
            difyConversationId: $model->dify_conversation_id,
            status: $model->status
        );
    }
}
