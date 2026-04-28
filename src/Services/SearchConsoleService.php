<?php

declare(strict_types=1);

namespace TnlMedia\TrendingSummary\Services;

use Illuminate\Support\Facades\Log;

/**
 * Google Search Console 整合服務
 *
 * 負責從 Google Search Console API 取得自家站台的熱門搜尋查詢詞，
 * 並與 Google Trends 關鍵字合併，形成更精準的複合關鍵字集合。
 * 此整合為可選功能，未啟用時不影響系統運作。
 */
class SearchConsoleService
{
    /**
     * 檢查 Search Console 整合是否已啟用。
     *
     * 依據設定檔 `trending-summary.search_console.enabled` 判斷。
     *
     * @return bool 是否啟用
     */
    public function isEnabled(): bool
    {
        return (bool) config('trending-summary.search_console.enabled', false);
    }

    /**
     * 從 Google Search Console API 取得熱門搜尋查詢詞。
     *
     * 當功能未啟用或 google/apiclient 未安裝時，回傳空陣列。
     * 查詢指定站台在最近 N 天內的熱門搜尋詞，依點擊數排序。
     *
     * @return array<int, string> 搜尋查詢詞字串陣列
     */
    public function fetchQueries(): array
    {
        if (! $this->isEnabled()) {
            return [];
        }

        // google/apiclient 為可選依賴，檢查是否已安裝
        if (! class_exists(\Google\Client::class)) {
            Log::warning('SearchConsoleService：google/apiclient 未安裝，無法取得 Search Console 資料。請執行 composer require google/apiclient 以啟用此功能。');

            return [];
        }

        try {
            return $this->querySearchConsoleApi();
        } catch (\Throwable $e) {
            Log::error('SearchConsoleService：取得 Search Console 資料失敗', [
                'error' => $e->getMessage(),
                'site_url' => config('trending-summary.search_console.site_url'),
            ]);

            return [];
        }
    }

    /**
     * 合併 Search Console 查詢詞與 Google Trends 關鍵字。
     *
     * 將兩個來源的關鍵字合併為不重複的複合關鍵字集合。
     * 若 Search Console 未啟用，直接回傳原始趨勢關鍵字。
     *
     * @param array<int, string> $trendKeywords Google Trends 關鍵字陣列
     * @return array<int, string> 合併後的不重複關鍵字陣列
     */
    public function mergeWithTrends(array $trendKeywords): array
    {
        $consoleQueries = $this->fetchQueries();

        if ($consoleQueries === []) {
            return array_values($trendKeywords);
        }

        // 合併兩個來源並去除重複（不區分大小寫）
        $merged = [];
        $seen = [];

        foreach (array_merge($trendKeywords, $consoleQueries) as $keyword) {
            $normalized = mb_strtolower(trim($keyword));

            if ($normalized === '' || isset($seen[$normalized])) {
                continue;
            }

            $seen[$normalized] = true;
            $merged[] = trim($keyword);
        }

        return array_values($merged);
    }

    /**
     * 透過 Google API Client 查詢 Search Console API。
     *
     * 使用服務帳號憑證認證，查詢指定站台在設定天數內的
     * 熱門搜尋查詢詞，依點擊數降序排列，回傳前 N 筆。
     *
     * @return array<int, string> 搜尋查詢詞字串陣列
     *
     * @throws \Throwable 當 API 呼叫失敗時拋出例外
     */
    private function querySearchConsoleApi(): array
    {
        $credentialsPath = (string) config('trending-summary.search_console.credentials_path', '');
        $siteUrl = (string) config('trending-summary.search_console.site_url', '');
        $days = (int) config('trending-summary.search_console.days', 7);
        $limit = (int) config('trending-summary.search_console.limit', 50);

        if ($siteUrl === '') {
            Log::warning('SearchConsoleService：未設定 site_url，略過 Search Console 查詢。');

            return [];
        }

        /** @var \Google\Client $client */
        $client = new \Google\Client();

        if ($credentialsPath !== '' && file_exists($credentialsPath)) {
            $client->setAuthConfig($credentialsPath);
        }

        $client->addScope('https://www.googleapis.com/auth/webmasters.readonly');

        /** @var \Google\Service\SearchConsole $service */
        $service = new \Google\Service\SearchConsole($client);

        $endDate = date('Y-m-d');
        $startDate = date('Y-m-d', strtotime("-{$days} days"));

        /** @var \Google\Service\SearchConsole\SearchAnalyticsQueryRequest $request */
        $request = new \Google\Service\SearchConsole\SearchAnalyticsQueryRequest();
        $request->setStartDate($startDate);
        $request->setEndDate($endDate);
        $request->setDimensions(['query']);
        $request->setRowLimit($limit);

        /** @var \Google\Service\SearchConsole\SearchAnalyticsQueryResponse $response */
        $response = $service->searchanalytics->query($siteUrl, $request);

        $queries = [];
        $rows = $response->getRows() ?? [];

        foreach ($rows as $row) {
            $keys = $row->getKeys();

            if (isset($keys[0]) && trim($keys[0]) !== '') {
                $queries[] = trim($keys[0]);
            }
        }

        Log::info('SearchConsoleService：成功取得 Search Console 查詢詞', [
            'site_url' => $siteUrl,
            'count' => count($queries),
            'period' => "{$startDate} ~ {$endDate}",
        ]);

        return $queries;
    }
}
