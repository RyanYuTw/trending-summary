<?php

declare(strict_types=1);

namespace TnlMedia\TrendingSummary\Services\Drivers;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use TnlMedia\TrendingSummary\Contracts\EmbeddingInterface;
use TnlMedia\TrendingSummary\Contracts\LlmInterface;
use TnlMedia\TrendingSummary\Contracts\TranslatorInterface;

/**
 * OpenAI AI Driver
 *
 * 透過 OpenAI REST API 實作向量嵌入、文字生成與翻譯功能。
 * 支援 text-embedding-3-small（嵌入）與 gpt-4o（生成/翻譯）模型。
 * API 金鑰以 Authorization Bearer header 方式傳遞。
 */
class OpenAiDriver implements EmbeddingInterface, LlmInterface, TranslatorInterface
{
    /**
     * Driver 設定
     *
     * @var array<string, mixed>
     */
    protected array $config;

    /**
     * API 基礎 URL
     */
    protected string $baseUrl;

    /**
     * API 金鑰
     */
    protected string $apiKey;

    /**
     * 模型名稱
     */
    protected string $model;

    /**
     * HTTP 請求逾時秒數
     */
    protected int $timeout;

    /**
     * 建立 OpenAiDriver 實例
     *
     * @param  array<string, mixed>  $config  設定陣列，包含 api_key、base_url、model、timeout 等
     */
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->apiKey = $config['api_key'] ?? '';
        $this->baseUrl = rtrim($config['base_url'] ?? 'https://api.openai.com/v1', '/');
        $this->model = $config['model'] ?? 'gpt-4o';
        $this->timeout = (int) ($config['timeout'] ?? 60);
    }

    /**
     * 將單一或多段文字轉換為向量嵌入
     *
     * 使用 OpenAI Embeddings API 將文字轉換為向量表示。
     * 當傳入陣列時，會取第一個元素進行嵌入。
     *
     * @param  string|array<int, string>  $text  單一文字字串或文字陣列
     * @return array<int, float>  向量嵌入（浮點數陣列）
     *
     * @throws RuntimeException  當 API 呼叫失敗時
     */
    public function embed(string|array $text): array
    {
        $textContent = is_array($text) ? ($text[0] ?? '') : $text;

        $embeddingModel = $this->resolveEmbeddingModel();

        $url = "{$this->baseUrl}/embeddings";

        $payload = [
            'model' => $embeddingModel,
            'input' => $textContent,
        ];

        $response = $this->sendRequest($url, $payload);

        return $response['data'][0]['embedding'] ?? [];
    }

    /**
     * 批次將多段文字轉換為向量嵌入
     *
     * 使用 OpenAI Embeddings API 一次處理多段文字（以陣列形式傳入 input）。
     *
     * @param  array<int, string>  $texts  文字陣列
     * @return array<int, array<int, float>>  向量嵌入陣列，每個元素對應一段文字的向量
     *
     * @throws RuntimeException  當 API 呼叫失敗時
     */
    public function batchEmbed(array $texts): array
    {
        if (empty($texts)) {
            return [];
        }

        $embeddingModel = $this->resolveEmbeddingModel();

        $url = "{$this->baseUrl}/embeddings";

        $payload = [
            'model' => $embeddingModel,
            'input' => array_values($texts),
        ];

        $response = $this->sendRequest($url, $payload);

        $data = $response['data'] ?? [];

        // OpenAI 回傳的 data 陣列依 index 排序，確保順序正確
        usort($data, fn (array $a, array $b): int => ($a['index'] ?? 0) <=> ($b['index'] ?? 0));

        return array_map(
            fn (array $item): array => $item['embedding'] ?? [],
            $data,
        );
    }

    /**
     * 根據提示詞生成文字回應
     *
     * 使用 OpenAI Chat Completions API 產生文字內容。
     *
     * @param  string  $prompt  提示詞
     * @param  array<string, mixed>  $options  額外選項（temperature、max_tokens 等）
     * @return string  生成的文字內容
     *
     * @throws RuntimeException  當 API 呼叫失敗或回應格式異常時
     */
    public function generate(string $prompt, array $options = []): string
    {
        $url = "{$this->baseUrl}/chat/completions";

        $payload = [
            'model' => $this->model,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ];

        $payload = $this->applyGenerationOptions($payload, $options);

        $response = $this->sendRequest($url, $payload);

        return $this->extractTextFromResponse($response);
    }

    /**
     * 根據提示詞與 JSON Schema 生成結構化輸出
     *
     * 使用 OpenAI Chat Completions API 搭配 response_format（json_schema）
     * 產生符合指定 schema 的 JSON 結構化資料。
     *
     * @param  string  $prompt  提示詞
     * @param  array<string, mixed>  $schema  期望的輸出 JSON Schema 定義
     * @param  array<string, mixed>  $options  額外選項（temperature、max_tokens 等）
     * @return array<string, mixed>  符合 schema 定義的結構化資料
     *
     * @throws RuntimeException  當 API 呼叫失敗或 JSON 解析失敗時
     */
    public function generateStructured(string $prompt, array $schema, array $options = []): array
    {
        $url = "{$this->baseUrl}/chat/completions";

        $payload = [
            'model' => $this->model,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'structured_output',
                    'schema' => $schema,
                ],
            ],
        ];

        $payload = $this->applyGenerationOptions($payload, $options);

        $response = $this->sendRequest($url, $payload);

        $text = $this->extractTextFromResponse($response);

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($text, true);

        if (! is_array($decoded)) {
            throw new RuntimeException(
                "OpenAI API 結構化輸出 JSON 解析失敗：{$text}"
            );
        }

        return $decoded;
    }

    /**
     * 將單一文字翻譯為目標語言
     *
     * 內部使用 generate() 搭配翻譯提示詞實作。
     *
     * @param  string  $text  待翻譯的文字
     * @param  string  $targetLang  目標語言代碼（預設：zh-TW）
     * @return string  翻譯後的文字
     *
     * @throws RuntimeException  當 API 呼叫失敗時
     */
    public function translate(string $text, string $targetLang = 'zh-TW'): string
    {
        $prompt = $this->buildTranslationPrompt($text, $targetLang);

        return $this->generate($prompt, [
            'temperature' => $this->config['temperature'] ?? 0.3,
        ]);
    }

    /**
     * 批次將多段文字翻譯為目標語言
     *
     * 透過單一提示詞批次翻譯多段文字，使用編號格式確保結果對應。
     *
     * @param  array<int, string>  $texts  待翻譯的文字陣列
     * @param  string  $targetLang  目標語言代碼（預設：zh-TW）
     * @return array<int, string>  翻譯後的文字陣列，順序與輸入對應
     *
     * @throws RuntimeException  當 API 呼叫失敗時
     */
    public function batchTranslate(array $texts, string $targetLang = 'zh-TW'): array
    {
        if (empty($texts)) {
            return [];
        }

        if (count($texts) === 1) {
            return [$this->translate($texts[0], $targetLang)];
        }

        $numberedTexts = '';
        foreach (array_values($texts) as $index => $text) {
            $num = $index + 1;
            $numberedTexts .= "[{$num}] {$text}\n";
        }

        $prompt = <<<PROMPT
將以下編號文字翻譯為{$targetLang}。
請保持編號順序，每行一個翻譯結果，格式為 [編號] 翻譯內容。
只輸出翻譯結果，不要加入任何解釋。

{$numberedTexts}
PROMPT;

        $result = $this->generate($prompt, [
            'temperature' => $this->config['temperature'] ?? 0.3,
        ]);

        return $this->parseBatchTranslationResult($result, count($texts));
    }

    /**
     * 解析嵌入模型名稱
     *
     * 若設定中的模型為嵌入模型（如 text-embedding-3-small），直接使用；
     * 否則使用預設嵌入模型。
     *
     * @return string  嵌入模型名稱
     */
    protected function resolveEmbeddingModel(): string
    {
        $model = $this->config['model'] ?? $this->model;

        // 若模型名稱包含 embedding，視為嵌入模型
        if (str_contains($model, 'embedding')) {
            return $model;
        }

        return 'text-embedding-3-small';
    }

    /**
     * 套用生成選項至請求 payload
     *
     * 將 options 中的參數（temperature、max_tokens 等）合併至 payload。
     *
     * @param  array<string, mixed>  $payload  原始請求 payload
     * @param  array<string, mixed>  $options  選項參數
     * @return array<string, mixed>  合併後的 payload
     */
    protected function applyGenerationOptions(array $payload, array $options): array
    {
        $temperature = $options['temperature'] ?? $this->config['temperature'] ?? null;
        if ($temperature !== null) {
            $payload['temperature'] = (float) $temperature;
        }

        $maxTokens = $options['max_tokens'] ?? $this->config['max_tokens'] ?? null;
        if ($maxTokens !== null) {
            $payload['max_tokens'] = (int) $maxTokens;
        }

        // 支援直接傳入 OpenAI 原生參數
        if (isset($options['top_p'])) {
            $payload['top_p'] = (float) $options['top_p'];
        }

        if (isset($options['frequency_penalty'])) {
            $payload['frequency_penalty'] = (float) $options['frequency_penalty'];
        }

        if (isset($options['presence_penalty'])) {
            $payload['presence_penalty'] = (float) $options['presence_penalty'];
        }

        return $payload;
    }

    /**
     * 建構翻譯提示詞
     *
     * @param  string  $text  待翻譯的文字
     * @param  string  $targetLang  目標語言代碼
     * @return string  翻譯提示詞
     */
    protected function buildTranslationPrompt(string $text, string $targetLang): string
    {
        return <<<PROMPT
你是一位專業翻譯。請將以下文字翻譯為{$targetLang}。
只輸出翻譯結果，不要加入任何解釋或額外文字。

{$text}
PROMPT;
    }

    /**
     * 從 OpenAI Chat Completions API 回應中提取文字內容
     *
     * @param  array<string, mixed>  $response  API 回應
     * @return string  提取的文字內容
     *
     * @throws RuntimeException  當回應中無法提取文字時
     */
    protected function extractTextFromResponse(array $response): string
    {
        $choices = $response['choices'] ?? [];

        if (empty($choices)) {
            throw new RuntimeException(
                'OpenAI API 回應中無候選結果。'
            );
        }

        $message = $choices[0]['message'] ?? [];

        if (empty($message)) {
            throw new RuntimeException(
                'OpenAI API 回應中無訊息內容。'
            );
        }

        return $message['content'] ?? '';
    }

    /**
     * 發送 HTTP POST 請求至 OpenAI API
     *
     * API 金鑰以 Authorization Bearer header 方式傳遞。
     *
     * @param  string  $url  API 端點 URL
     * @param  array<string, mixed>  $payload  請求內容
     * @return array<string, mixed>  API 回應
     *
     * @throws RuntimeException  當 HTTP 請求失敗或 API 回傳錯誤時
     */
    protected function sendRequest(string $url, array $payload): array
    {
        /** @var Response $response */
        $response = Http::timeout($this->timeout)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => "Bearer {$this->apiKey}",
            ])
            ->post($url, $payload);

        if ($response->failed()) {
            $status = $response->status();
            $body = $response->body();

            throw new RuntimeException(
                "OpenAI API 請求失敗（HTTP {$status}）：{$body}"
            );
        }

        /** @var array<string, mixed> $data */
        $data = $response->json();

        if (isset($data['error'])) {
            $errorMessage = $data['error']['message'] ?? '未知錯誤';
            $errorType = $data['error']['type'] ?? 'unknown';

            throw new RuntimeException(
                "OpenAI API 錯誤（{$errorType}）：{$errorMessage}"
            );
        }

        return $data;
    }

    /**
     * 解析批次翻譯結果
     *
     * 從 LLM 回應中解析編號格式的翻譯結果。
     *
     * @param  string  $result  LLM 回應文字
     * @param  int  $expectedCount  預期的翻譯數量
     * @return array<int, string>  翻譯結果陣列
     */
    protected function parseBatchTranslationResult(string $result, int $expectedCount): array
    {
        $translations = [];
        $lines = array_filter(
            array_map('trim', explode("\n", $result)),
            fn (string $line): bool => $line !== '',
        );

        foreach ($lines as $line) {
            // 匹配 [數字] 內容 格式
            if (preg_match('/^\[(\d+)\]\s*(.+)$/', $line, $matches)) {
                $index = (int) $matches[1] - 1;
                $translations[$index] = $matches[2];
            }
        }

        // 確保結果數量與輸入一致，缺少的以空字串填補
        $ordered = [];
        for ($i = 0; $i < $expectedCount; $i++) {
            $ordered[] = $translations[$i] ?? '';
        }

        return $ordered;
    }
}
