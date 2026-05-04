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
    public int $timeout;

    public function __construct(
        public readonly string $topic,
        public readonly ?string $initialAi = null,
        public readonly ?string $triggerBot = null,
        public readonly ?string $applicationId = null,
        public readonly ?string $token = null
    ) {
        $this->timeout = (int) env('START_DEBATE_JOB_TIMEOUT', 300);
    }

    public function handle(StartDebateUseCase $useCase): void
    {
        $useCase->execute(
            $this->topic,
            $this->initialAi,
            $this->triggerBot,
            $this->applicationId,
            $this->token
        );
    }

    /**
     * ジョブが失敗したときの処理
     */
    public function failed(\Throwable $e): void
    {
        Log::error('StartDebateJob Failed permanently', [
            'topic' => $this->topic,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
    }
}
