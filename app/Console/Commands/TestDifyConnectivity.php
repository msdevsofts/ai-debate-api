<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Enums\TargetAi;
use App\Infrastructure\Adapters\DifyApiAdapter;
use Illuminate\Console\Command;

class TestDifyConnectivity extends Command
{
    protected $signature = 'test:dify {--query=Hello} {--target=gemini}';
    protected $description = 'Test connectivity to Dify API';

    public function handle(DifyApiAdapter $adapter): int
    {
        $query = $this->option('query');
        $targetStr = $this->option('target');
        $targetAi = TargetAi::tryFrom($targetStr) ?? TargetAi::GEMINI;

        $this->info("Testing Dify API connectivity...");
        $this->info("Base URL: " . config('services.dify.base_url'));
        $this->info("Target AI: {$targetAi->value}");
        $this->info("Query: {$query}");

        try {
            $response = $adapter->chat(
                $query,
                null,
                $targetAi,
                'Connectivity Test',
                true
            );

            $this->info("Response received successfully!");
            $this->info("Answer: " . ($response['answer'] ?? 'NO ANSWER'));
            return 0;
        } catch (\Exception $e) {
            $this->error("Failed to call Dify API: " . $e->getMessage());
            return 1;
        }
    }
}
