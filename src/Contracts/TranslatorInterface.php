<?php

declare(strict_types=1);

namespace TnlMedia\TrendingSummary\Contracts;

/**
 * 翻譯介面
 *
 * 定義文字翻譯的標準操作，預設目標語言為繁體中文（zh-TW）。
 * 宿主專案可透過 ServiceProvider 綁定自訂實作來覆寫預設行為。
 */
interface TranslatorInterface
{
    /**
     * 將單一文字翻譯為目標語言
     *
     * @param  string  $text  待翻譯的文字
     * @param  string  $targetLang  目標語言代碼（預設：zh-TW）
     * @return string  翻譯後的文字
     */
    public function translate(string $text, string $targetLang = 'zh-TW'): string;

    /**
     * 批次將多段文字翻譯為目標語言
     *
     * @param  array<int, string>  $texts  待翻譯的文字陣列
     * @param  string  $targetLang  目標語言代碼（預設：zh-TW）
     * @return array<int, string>  翻譯後的文字陣列，順序與輸入對應
     */
    public function batchTranslate(array $texts, string $targetLang = 'zh-TW'): array;
}
