<?php

declare(strict_types=1);

namespace TnlMedia\TrendingSummary\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use TnlMedia\TrendingSummary\Contracts\AiModelManagerInterface;
use TnlMedia\TrendingSummary\Contracts\TranslatorInterface;

/**
 * 翻譯服務
 *
 * 負責將非繁體中文內容翻譯為繁體中文。透過 AiModelManagerInterface 取得
 * translation 角色的 driver 實例（實作 TranslatorInterface），並對翻譯結果
 * 進行快取（預設 7 天 TTL）以避免重複的 API 呼叫。
 *
 * 支援單筆翻譯與批次翻譯，批次翻譯時僅對未快取的文字發送 API 請求，
 * 已快取的文字直接從快取取得，以降低 API 成本。
 *
 * 提供簡易的語言偵測啟發式方法：當文字中 CJK 字元佔比超過 50% 時，
 * 判定為已是繁體中文，無需翻譯。
 */
class TranslationService
{
    /**
     * 快取鍵前綴
     */
    private const string CACHE_PREFIX = 'ts_translation:';

    /**
     * CJK 字元佔比門檻，超過此比例視為已是中文
     */
    private const float CJK_THRESHOLD = 0.5;

    /**
     * 建構子，注入 AiModelManagerInterface。
     *
     * @param AiModelManagerInterface $aiManager AI 模型管理器
     */
    public function __construct(
        protected AiModelManagerInterface $aiManager,
    ) {}

    /**
     * 將單一文字翻譯為目標語言（含快取）
     *
     * 先檢查快取是否已有翻譯結果，若有則直接回傳；
     * 若無則透過 TranslatorInterface 進行翻譯，並將結果存入快取。
     * 若文字為空或已是目標語言，則直接回傳原文。
     *
     * @param  string  $text  待翻譯的文字
     * @param  string  $targetLang  目標語言代碼（預設：zh-TW）
     * @return string  翻譯後的文字
     *
     * @throws \Throwable  當翻譯 API 呼叫失敗時
     */
    public function translate(string $text, string $targetLang = 'zh-TW'): string
    {
        if (trim($text) === '') {
            return $text;
        }

        if (! $this->isTranslationNeeded($text)) {
            return $text;
        }

        $cacheKey = $this->buildCacheKey($text, $targetLang);

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return (string) $cached;
        }

        try {
            /** @var TranslatorInterface $translator */
            $translator = $this->aiManager->driver('translation');

            $translated = $translator->translate($text, $targetLang);

            Cache::put($cacheKey, $translated, $this->getCacheTtl());

            Log::debug('TranslationService: 翻譯完成並已快取', [
                'source_length' => mb_strlen($text),
                'target_lang' => $targetLang,
            ]);

            return $translated;
        } catch (\Throwable $e) {
            Log::error('TranslationService: 翻譯失敗', [
                'source_length' => mb_strlen($text),
                'target_lang' => $targetLang,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * 批次將多段文字翻譯為目標語言（含快取）
     *
     * 先檢查每段文字的快取，僅對未快取且需要翻譯的文字發送 API 請求。
     * 翻譯完成後將結果存入快取。回傳的陣列順序與輸入對應。
     *
     * @param  array<int, string>  $texts  待翻譯的文字陣列
     * @param  string  $targetLang  目標語言代碼（預設：zh-TW）
     * @return array<int, string>  翻譯後的文字陣列，順序與輸入對應
     *
     * @throws \Throwable  當翻譯 API 呼叫失敗時
     */
    public function batchTranslate(array $texts, string $targetLang = 'zh-TW'): array
    {
        if ($texts === []) {
            return [];
        }

        $results = [];
        $uncachedIndices = [];
        $uncachedTexts = [];

        // 第一輪：檢查快取與語言偵測
        foreach ($texts as $index => $text) {
            if (trim($text) === '' || ! $this->isTranslationNeeded($text)) {
                $results[$index] = $text;
                continue;
            }

            $cacheKey = $this->buildCacheKey($text, $targetLang);
            $cached = Cache::get($cacheKey);

            if ($cached !== null) {
                $results[$index] = (string) $cached;
            } else {
                $uncachedIndices[] = $index;
                $uncachedTexts[] = $text;
            }
        }

        // 若所有文字皆已快取或無需翻譯，直接回傳
        if ($uncachedTexts === []) {
            ksort($results);

            return $results;
        }

        try {
            /** @var TranslatorInterface $translator */
            $translator = $this->aiManager->driver('translation');

            $translatedTexts = $translator->batchTranslate($uncachedTexts, $targetLang);

            // 將翻譯結果對應回原始索引並存入快取
            foreach ($uncachedIndices as $i => $originalIndex) {
                $translated = $translatedTexts[$i] ?? $uncachedTexts[$i];
                $results[$originalIndex] = $translated;

                $cacheKey = $this->buildCacheKey($uncachedTexts[$i], $targetLang);
                Cache::put($cacheKey, $translated, $this->getCacheTtl());
            }

            Log::debug('TranslationService: 批次翻譯完成', [
                'total' => count($texts),
                'translated' => count($uncachedTexts),
                'cached' => count($texts) - count($uncachedTexts),
                'target_lang' => $targetLang,
            ]);
        } catch (\Throwable $e) {
            Log::error('TranslationService: 批次翻譯失敗', [
                'total' => count($texts),
                'uncached' => count($uncachedTexts),
                'target_lang' => $targetLang,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        ksort($results);

        return $results;
    }

    /**
     * 判斷文字是否需要翻譯
     *
     * 使用簡易啟發式方法：計算文字中 CJK 統一表意文字的佔比，
     * 若超過 50% 則判定為已是中文（繁體中文），無需翻譯。
     * 空白字元與標點符號不計入總字元數。
     *
     * @param  string  $text  待檢測的文字
     * @return bool  true 表示需要翻譯，false 表示已是目標語言
     */
    public function isTranslationNeeded(string $text): bool
    {
        $text = trim($text);

        if ($text === '') {
            return false;
        }

        // 移除空白字元與常見標點符號後計算
        $cleanText = (string) preg_replace('/[\s\p{P}\p{S}]+/u', '', $text);

        if ($cleanText === '') {
            return false;
        }

        $totalChars = mb_strlen($cleanText);

        // 計算 CJK 統一表意文字數量（U+4E00–U+9FFF, U+3400–U+4DBF, U+F900–U+FAFF）
        $cjkCount = (int) preg_match_all('/[\x{4E00}-\x{9FFF}\x{3400}-\x{4DBF}\x{F900}-\x{FAFF}]/u', $cleanText);

        $cjkRatio = $cjkCount / $totalChars;

        return $cjkRatio < self::CJK_THRESHOLD;
    }

    /**
     * 建構翻譯快取鍵
     *
     * 使用原始文字與目標語言的 SHA-256 雜湊值作為快取鍵，
     * 確保鍵值唯一且長度固定。
     *
     * @param  string  $text  原始文字
     * @param  string  $targetLang  目標語言代碼
     * @return string  快取鍵
     */
    private function buildCacheKey(string $text, string $targetLang): string
    {
        $hash = hash('sha256', $text . '|' . $targetLang);

        return self::CACHE_PREFIX . $hash;
    }

    /**
     * 取得翻譯快取 TTL（秒）
     *
     * 從設定檔讀取 `trending-summary.cache.translation_ttl`，
     * 預設為 604800 秒（7 天）。
     *
     * @return int  快取 TTL（秒）
     */
    private function getCacheTtl(): int
    {
        return (int) config('trending-summary.cache.translation_ttl', 604800);
    }
}
