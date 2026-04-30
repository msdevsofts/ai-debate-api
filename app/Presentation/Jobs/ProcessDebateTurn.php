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
