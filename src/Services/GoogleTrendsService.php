<?php

declare(strict_types=1);

namespace TnlMedia\TrendingSummary\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use TnlMedia\TrendingSummary\Models\TrendKeyword;

/**
 * Google Trends 趨勢關鍵字同步服務
 *
 * 負責從 Google Trends RSS feed（geo=TW）抓取當前熱搜關鍵字，
 * 實作快取邏輯以減少重複請求，並將關鍵字儲存為 TrendKeyword 記錄。
 * 當 feed 不可達時，自動回退使用最近的快取資料。
 */
class GoogleTrendsService
{
    /**
     * 快取鍵前綴。
     */
    private const string CACHE_PREFIX = 'trending-summary:trends:';

    /**
     * 同步 Google Trends 趨勢關鍵字。
     *
     * 主要入口方法。優先從快取取得關鍵字，快取過期時從 RSS feed 抓取新資料，
     * 並將每個關鍵字以 updateOrCreate 儲存為 TrendKeyword 記錄。
     *
     * @return array<int, TrendKeyword> 同步後的 TrendKeyword 模型陣列
     */
    public function sync(): array
    {
        $cacheKey = $this->getCacheKey();
        $cacheTtl = (int) config('trending-summary.trends.cache_ttl', 3600);

        // 快取未過期時直接回傳快取的關鍵字資料
        if (Cache::has($cacheKey)) {
            /** @var array<int, array{keyword: string, traffic_volume: int, trend_date: string}> $cached */
            $cached = Cache::get($cacheKey);

            return $this->storeKeywords($cached);
        }

        // 從 RSS feed 抓取新資料
        $keywords = $this->fetchFromRss();

        if ($keywords !== []) {
            // 抓取成功，更新快取
            Cache::put($cacheKey, $keywords, $cacheTtl);

            return $this->storeKeywords($keywords);
        }

        // feed 不可達，嘗試使用最近快取（即使已過期）
        Log::warning('Google Trends RSS 不可達，嘗試使用最近快取', [
            'cache_key' => $cacheKey,
        ]);

        /** @var array<int, array{keyword: string, traffic_volume: int, trend_date: string}>|null $fallback */
        $fallback = Cache::get($cacheKey);

        if ($fallback !== null) {
            return $this->storeKeywords($fallback);
        }

        // 完全無快取可用，從資料庫取得最近的關鍵字
        return TrendKeyword::query()
            ->orderByDesc('trend_date')
            ->orderByDesc('traffic_volume')
            ->limit(20)
            ->get()
            ->all();
    }

    /**
     * 從 Google Trends RSS feed 抓取並解析趨勢關鍵字。
     *
     * 透過 HTTP 請求取得 RSS XML，解析每個 item 的 title（關鍵字）、
     * ht:approx_traffic（搜尋量）與 pubDate（趨勢日期）。
     *
     * @return array<int, array{keyword: string, traffic_volume: int, trend_date: string}> 解析後的關鍵字陣列，失敗時回傳空陣列
     */
    public function fetchFromRss(): array
    {
        $rssUrl = (string) config('trending-summary.trends.rss_url', 'https://trends.google.com.tw/trending/rss?geo=TW');

        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'Accept' => 'application/rss+xml, application/xml, text/xml',
                    'User-Agent' => 'TrendingSummary/1.0',
                ])
                ->get($rssUrl);

            if (! $response->successful()) {
                Log::error('Google Trends RSS 回應異常', [
                    'url' => $rssUrl,
                    'status' => $response->status(),
                ]);

                return [];
            }

            return $this->parseRssXml($response->body());
        } catch (\Throwable $e) {
            Log::error('Google Trends RSS 抓取失敗', [
                'url' => $rssUrl,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * 解析 RSS XML 內容為關鍵字陣列。
     *
     * 從 XML 中提取每個 <item> 的 <title>、<ht:approx_traffic> 與 <pubDate>，
     * 轉換為結構化的關鍵字資料。
     *
     * @param string $xml RSS XML 字串
     * @return array<int, array{keyword: string, traffic_volume: int, trend_date: string}> 解析後的關鍵字陣列
     */
    public function parseRssXml(string $xml): array
    {
        if (trim($xml) === '') {
            return [];
        }

        // 抑制 XML 解析警告
        $previousUseErrors = libxml_use_internal_errors(true);

        try {
            $doc = simplexml_load_string($xml);

            if ($doc === false) {
                Log::error('Google Trends RSS XML 解析失敗', [
                    'errors' => array_map(
                        fn (\LibXMLError $e) => trim($e->message),
                        libxml_get_errors()
                    ),
                ]);
                libxml_clear_errors();

                return [];
            }

            $keywords = [];
            $items = $doc->channel->item ?? [];

            foreach ($items as $item) {
                $title = trim((string) ($item->title ?? ''));

                if ($title === '') {
                    continue;
                }

                // 解析 ht:approx_traffic（命名空間 https://trends.google.com/trending/rss）
                $htNamespaces = $item->getNamespaces(true);
                $trafficStr = '';

                // 嘗試從 ht 命名空間取得 approx_traffic
                foreach ($htNamespaces as $prefix => $ns) {
                    if (str_contains($ns, 'trends.google') || $prefix === 'ht') {
                        $htChildren = $item->children($ns);
                        $trafficStr = (string) ($htChildren->approx_traffic ?? '');

                        if ($trafficStr !== '') {
                            break;
                        }
                    }
                }

                // 若命名空間方式取不到，嘗試直接 xpath
                if ($trafficStr === '') {
                    $item->registerXPathNamespace('ht', 'https://trends.google.com.tw/trends/trendingsearches/daily');
                    $trafficNodes = $item->xpath('ht:approx_traffic');

                    if ($trafficNodes !== false && $trafficNodes !== []) {
                        $trafficStr = (string) $trafficNodes[0];
                    }
                }

                $trafficVolume = $this->parseTrafficVolume($trafficStr);

                // 解析 pubDate
                $pubDate = (string) ($item->pubDate ?? '');
                $trendDate = $this->parseTrendDate($pubDate);

                $keywords[] = [
                    'keyword' => $title,
                    'traffic_volume' => $trafficVolume,
                    'trend_date' => $trendDate,
                ];
            }

            return $keywords;
        } finally {
            libxml_use_internal_errors($previousUseErrors);
        }
    }

    /**
     * 解析流量字串為整數。
     *
     * 將 Google Trends 的 approx_traffic 格式（如 "100,000+"、"2M+"、"500K+"）
     * 轉換為整數值。移除逗號、加號等非數字字元後取得數值。
     *
     * @param string $traffic 流量字串，例如 "100,000+"、"2M+"、"500K+"
     * @return int 解析後的整數流量值，無法解析時回傳 0
     */
    public function parseTrafficVolume(string $traffic): int
    {
        $traffic = trim($traffic);

        if ($traffic === '') {
            return 0;
        }

        // 移除加號與空白
        $cleaned = str_replace(['+', ' '], '', $traffic);

        // 處理 K/M 後綴（如 "500K"、"2M"）
        if (preg_match('/^([\d,.]+)\s*([KkMm])?$/', $cleaned, $matches)) {
            $number = (float) str_replace(',', '', $matches[1]);
            $suffix = strtoupper($matches[2] ?? '');

            return (int) match ($suffix) {
                'K' => $number * 1_000,
                'M' => $number * 1_000_000,
                default => $number,
            };
        }

        // 移除所有非數字字元，直接轉換
        $digits = preg_replace('/[^\d]/', '', $cleaned);

        if ($digits === '' || $digits === null) {
            return 0;
        }

        return (int) $digits;
    }

    /**
     * 產生快取鍵。
     *
     * 根據設定的地區（geo）產生唯一的快取鍵，
     * 格式為 `trending-summary:trends:{geo}`。
     *
     * @return string 快取鍵
     */
    public function getCacheKey(): string
    {
        // 從 RSS URL 中提取 geo 參數，或使用預設值
        $rssUrl = (string) config('trending-summary.trends.rss_url', '');
        $geo = 'TW';

        if ($rssUrl !== '') {
            $query = parse_url($rssUrl, PHP_URL_QUERY);

            if (is_string($query)) {
                parse_str($query, $params);
                $geo = (string) ($params['geo'] ?? 'TW');
            }
        }

        return self::CACHE_PREFIX . strtoupper($geo);
    }

    /**
     * 將關鍵字資料陣列儲存為 TrendKeyword 記錄。
     *
     * 使用 updateOrCreate 以 keyword 為唯一鍵，
     * 更新或建立 TrendKeyword 記錄。
     *
     * @param array<int, array{keyword: string, traffic_volume: int, trend_date: string}> $keywords 關鍵字資料陣列
     * @return array<int, TrendKeyword> 儲存後的 TrendKeyword 模型陣列
     */
    private function storeKeywords(array $keywords): array
    {
        $models = [];

        foreach ($keywords as $data) {
            $models[] = TrendKeyword::updateOrCreate(
                ['keyword' => $data['keyword']],
                [
                    'traffic_volume' => $data['traffic_volume'],
                    'trend_date' => $data['trend_date'],
                ]
            );
        }

        return $models;
    }

    /**
     * 解析 pubDate 字串為日期格式。
     *
     * 嘗試解析 RSS 標準的 pubDate 格式，失敗時回傳今天的日期。
     *
     * @param string $pubDate RSS pubDate 字串
     * @return string Y-m-d 格式的日期字串
     */
    private function parseTrendDate(string $pubDate): string
    {
        if (trim($pubDate) === '') {
            return Carbon::today()->toDateString();
        }

        try {
            return Carbon::parse($pubDate)->toDateString();
        } catch (\Throwable) {
            return Carbon::today()->toDateString();
        }
    }
}
