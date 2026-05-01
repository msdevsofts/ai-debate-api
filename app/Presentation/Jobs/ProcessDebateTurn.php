<?php

declare(strict_types=1);

namespace App\Presentation\Jobs;

use App\Application\UseCases\ProcessDebateTurnUseCase;
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
        private readonly int $debateSessionId
    ) {}

    public function handle(ProcessDebateTurnUseCase $useCase): void
    {
        try {
            $useCase->execute($this->debateSessionId);
        } catch (\Exception $e) {
            Log::error('ProcessDebateTurn Job Failed', [
                'session_id' => $this->debateSessionId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
