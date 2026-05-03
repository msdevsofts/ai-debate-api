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

    public function chat(string $query, ?string $conversationId, TargetAi $targetAi, string $topic, bool $isHumanIntervention = false): array
    {
        Log::info('Dify Request Query: ', ['query' => $query]);

        $response = Http::withToken($this->apiKey)
            ->timeout(1000)
            ->connectTimeout(60)
            ->post("{$this->baseUrl}/chat-messages", [
                'inputs' => [
                    'target_ai' => $targetAi->value,
                    'topic' => $topic,
                    'is_human_intervention' => $isHumanIntervention,
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
        $answer = $data['answer'] ?? '';

        // リテラルの '\n' や '\r\n' を実際の改行コードに置換
        $answer = str_replace(['\r\n', '\r', '\n'], "\n", $answer);

        $data['answer'] = $this->cleanAnswer($answer);

        return $data;
    }

    /**
     * AIの回答から思考プロセス（<think>タグ等）を除去し、必要に応じてフォールバックを行う
     */
    private function cleanAnswer(string $answer): string
    {
        if (empty($answer)) {
            return '';
        }

        $rawAnswer = $answer;

        // 1. <think>...</think> や <thought>...</thought> を正規表現で削除 (sフラグで改行対応)
        // タグの不整合（閉じタグが多すぎる、開始タグがない等）に対応するため、まずペアを消し、その後残ったタグを掃除する
        $answer = preg_replace('/<(think|thought)>.*?<\/\1>/s', '', $answer);

        // 残ってしまった開始タグ・閉じタグを個別に削除 (不完全な出力への対応)
        $answer = preg_replace('/<(think|thought)>|<\/(think|thought)>/s', '', $answer);

        // 2. (think) 形式を削除
        // (think) で始まり、その後の内容を、2つの連続する改行、または次のタグの開始まで削除
        $answer = preg_replace('/\(think\).*?(\n\n|(?=<)|$)/s', '', $answer);
        // インラインや行末の (think) マーカーを個別に削除
        $answer = preg_replace('/\(think\)/', '', $answer);

        // 3. [ENDTHINKFLAG] までの内容を削除 (最後に出現するフラグまでを貪欲にマッチ)
        $answer = preg_replace('/^.*\[ENDTHINKFLAG\]\s*/s', '', $answer);

        // 4. 残った余計な改行や空白を整理
        $answer = trim($answer);

        // 5. クレンジングの結果、空になった場合のフォールバック
        if (empty($answer)) {
            return $this->getFallbackAnswer($rawAnswer);
        }

        return $answer;
    }

    /**
     * 回答が空になった際の救済措置
     */
    private function getFallbackAnswer(string $rawAnswer): string
    {
        if (str_contains($rawAnswer, '[ENDTHINKFLAG]')) {
            // 最後の [ENDTHINKFLAG] より後ろを取得
            $parts = explode('[ENDTHINKFLAG]', $rawAnswer);
            $fallback = trim(end($parts));

            if (!empty($fallback)) {
                return $fallback;
            }

            // それでも空なら最後の思考の断片を抽出
            $lastFragment = trim($parts[count($parts) - 2] ?? '');
            return mb_substr($lastFragment, -300) ?: '（AIが思考のみを出力しました。結論を生成中です...）';
        }

        return '（AIが思考のみを出力しました。結論を生成中です...）';
    }
}
