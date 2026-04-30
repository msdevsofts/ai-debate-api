<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapters;

use App\Domain\Enums\TargetAi;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DifyApiAdapter
{
    private string $baseUrl;
    private string $apiKey;

    public function __construct()
    {
        $this->baseUrl = config('services.dify.base_url');
        $this->apiKey = config('services.dify.api_key');
    }

    public function chat(string $query, ?string $conversationId, TargetAi $targetAi, string $topic): array
    {
        $response = Http::withToken($this->apiKey)
            ->post("{$this->baseUrl}/chat-messages", [
                'inputs' => [
                    'target_ai' => $targetAi->value,
                    'topic' => $topic,
                ],
                'query' => $query,
                'response_mode' => 'blocking',
                'user' => 'debate-system',
                'conversation_id' => $conversationId,
            ]);

        if ($response->failed()) {
            Log::error('Dify API Error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException('Dify API call failed');
        }

        return $response->json();
    }
}
