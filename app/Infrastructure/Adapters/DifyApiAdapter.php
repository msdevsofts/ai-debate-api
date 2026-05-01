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
            ->timeout(900)
            ->connectTimeout(900)
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

        $data = $response->json();

        // answerフィールドが存在する場合、思考ログ（<think>タグや(think)など）を除去する
        if (isset($data['answer'])) {
            // 1. <think>...</think> や <thought>...</thought> を正規表現で削除 (sフラグで改行対応)
            $data['answer'] = preg_replace('/<(think|thought)>.*?<\/\1>/s', '', $data['answer']);

            // 2. (think) 形式を削除
            // (think) で始まり、その後の内容を、2つの連続する改行、または次のタグの開始まで削除
            $data['answer'] = preg_replace('/\(think\).*?(\n\n|(?=<)|$)/s', '', $data['answer']);
            // インラインや行末の (think) マーカーを個別に削除
            $data['answer'] = preg_replace('/\(think\)/', '', $data['answer']);

            // 3. 残った余計な改行や空白を整理
            $data['answer'] = trim($data['answer']);
        }

        return $data;
    }
}
