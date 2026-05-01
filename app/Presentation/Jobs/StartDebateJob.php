<?php

declare(strict_types=1);

namespace App\Presentation\Jobs;

use App\Application\UseCases\StartDebateUseCase;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class StartDebateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * スレッド作成等の処理も念のため長めに設定
     */
    public int $timeout = 300;

    public function __construct(
        public readonly string $topic,
        public readonly ?string $initialAi = null,
        public readonly ?string $triggerBot = null
    ) {}

    public function handle(StartDebateUseCase $useCase): void
    {
        try {
            $useCase->execute($this->topic, $this->initialAi, $this->triggerBot);
        } catch (\Exception $e) {
            Log::error('StartDebateJob Failed', [
                'topic' => $this->topic,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
