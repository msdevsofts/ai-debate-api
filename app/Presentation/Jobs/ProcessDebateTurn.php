<?php

declare(strict_types=1);

namespace App\Presentation\Jobs;

use App\Application\UseCases\ProcessDebateTurnUseCase;
use App\Domain\Enums\TargetAi;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessDebateTurn implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * タイムアウト時間を設定 (Dify APIの応答待ち時間に合わせる)
     */
    public int $timeout = 1100;

    /**
     * リトライ回数を制限
     */
    public int $tries = 3;

    public function __construct(
        public readonly int $debateSessionId,
        public readonly ?TargetAi $targetAi = null,
        public readonly ?string $query = null,
        public readonly ?string $replyToMessageId = null,
        public readonly bool $isHumanIntervention = false
    ) {
    }

    public function handle(ProcessDebateTurnUseCase $useCase): void
    {
        Log::info('ProcessDebateTurn Job Handling Started', [
            'session_id' => $this->debateSessionId,
            'target_ai' => $this->targetAi?->value,
            'query' => $this->query,
            'reply_to' => $this->replyToMessageId,
            'is_human_intervention' => $this->isHumanIntervention,
            'queue_connection' => config('queue.default'),
            'all_properties' => get_object_vars($this),
        ]);

        try {
            // targetAi が null の場合は execute 内でローテーションロジックが走る
            $useCase->execute(
                $this->debateSessionId,
                $this->targetAi,
                $this->query,
                $this->replyToMessageId,
                $this->isHumanIntervention
            );

            Log::info('ProcessDebateTurn Job Completed Successfully', [
                'session_id' => $this->debateSessionId,
            ]);
        } catch (\Throwable $e) {
            Log::error('ProcessDebateTurn Job Execution Error', [
                'session_id' => $this->debateSessionId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * ジョブが失敗したときの処理
     */
    public function failed(\Throwable $e): void
    {
        Log::error('ProcessDebateTurn Job Failed permanently', [
            'session_id' => $this->debateSessionId,
            'target_ai' => $this->targetAi?->value,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
    }
}
