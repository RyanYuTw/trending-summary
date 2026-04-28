<?php

declare(strict_types=1);

namespace TnlMedia\TrendingSummary\Contracts;

/**
 * LLM 文字生成介面
 *
 * 定義大型語言模型的標準操作，用於文字生成與結構化輸出。
 * 宿主專案可透過 ServiceProvider 綁定自訂實作來覆寫預設行為。
 */
interface LlmInterface
{
    /**
     * 根據提示詞生成文字回應
     *
     * @param  string  $prompt  提示詞
     * @param  array<string, mixed>  $options  額外選項（如 temperature、max_tokens 等）
     * @return string  生成的文字內容
     */
    public function generate(string $prompt, array $options = []): string;

    /**
     * 根據提示詞與 JSON Schema 生成結構化輸出
     *
     * @param  string  $prompt  提示詞
     * @param  array<string, mixed>  $schema  期望的輸出 JSON Schema 定義
     * @param  array<string, mixed>  $options  額外選項（如 temperature、max_tokens 等）
     * @return array<string, mixed>  符合 schema 定義的結構化資料
     */
    public function generateStructured(string $prompt, array $schema, array $options = []): array;
}
