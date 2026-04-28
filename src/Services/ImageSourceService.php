<?php

declare(strict_types=1);

namespace TnlMedia\TrendingSummary\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use TnlMedia\TrendingSummary\Contracts\ImageProviderInterface;
use TnlMedia\TrendingSummary\Models\ArticleImage;
use TnlMedia\TrendingSummary\Models\TrendingArticle;

/**
 * 圖片來源判斷與配圖服務
 *
 * 負責判斷文章圖片的版權來源並取得合法配圖。
 * 處理流程：
 * 1. 檢查文章原始圖片 URL 是否屬於 CNA 域名 → 直接使用，source_provider = "CNA"
 * 2. 非 CNA 且 InkMagine 已啟用 → 透過 InkMagine Gateway API 搜尋配圖（OAuth 認證 + 關鍵字搜尋）
 * 3. InkMagine 有結果 → 選取最相關圖片，使用 preferred_version
 * 4. 無結果或 API 不可達 → 標記 needs_manual = true
 *
 * 同時實作 ImageProviderInterface，提供 search() 與 getById() 方法供外部使用。
 */
class ImageSourceService implements ImageProviderInterface
{
    /**
     * OAuth token 快取鍵名。
     */
    private const string CACHE_KEY_OAUTH_TOKEN = 'trending-summary:inkmagine:oauth_token';

    /**
     * 建構子。
     */
    public function __construct() {}

    /**
     * 解析文章配圖來源並建立或更新 ArticleImage。
     *
     * 依序判斷：
     * 1. 文章是否有原始圖片 URL 且屬於 CNA 域名 → source_provider = "CNA"
     * 2. 非 CNA 且 InkMagine 啟用 → 搜尋 InkMagine 圖庫
     * 3. InkMagine 有結果 → 選取最相關圖片
     * 4. 無結果或錯誤 → needs_manual = true
     *
     * @param TrendingArticle $article 待配圖的文章
     * @return ArticleImage 建立或更新後的圖片記錄
     */
    public function resolveImage(TrendingArticle $article): ArticleImage
    {
        // 嘗試從文章內容中取得原始圖片 URL
        $originalImageUrl = $this->extractOriginalImageUrl($article);

        // 1. 有原始圖片且為 CNA 域名 → 直接使用
        if ($originalImageUrl !== null && $this->isCnaDomain($originalImageUrl)) {
            Log::info('ImageSourceService: 偵測到 CNA 圖片，直接使用', [
                'article_id' => $article->id,
                'url' => $originalImageUrl,
            ]);

            return $this->createOrUpdateImage($article, [
                'url' => $originalImageUrl,
                'thumbnail_url' => $originalImageUrl,
                'source_provider' => 'CNA',
                'needs_manual' => false,
            ]);
        }

        // 2. 非 CNA → 嘗試 InkMagine 搜尋
        $inkmagineConfig = config('trending-summary.images.inkmagine', []);
        $inkmagineEnabled = (bool) ($inkmagineConfig['enabled'] ?? false);

        if ($inkmagineEnabled) {
            $query = $this->buildSearchQuery($article);
            $results = $this->searchInkMagine($query);

            if ($results !== null && $results !== []) {
                // 選取第一筆（最相關）結果
                $bestMatch = $results[0];
                $preferredVersion = $inkmagineConfig['preferred_version'] ?? 'social';

                $imageUrl = $this->resolvePreferredVersionUrl($bestMatch, $preferredVersion);
                $thumbnailUrl = $bestMatch['thumbnail_url'] ?? $imageUrl;

                Log::info('ImageSourceService: 從 InkMagine 取得配圖', [
                    'article_id' => $article->id,
                    'image_id' => $bestMatch['id'] ?? null,
                    'preferred_version' => $preferredVersion,
                ]);

                return $this->createOrUpdateImage($article, [
                    'url' => $imageUrl,
                    'thumbnail_url' => $thumbnailUrl,
                    'source_provider' => 'InkMagine',
                    'caption' => $bestMatch['caption'] ?? null,
                    'versions' => $bestMatch['versions'] ?? null,
                    'needs_manual' => false,
                ]);
            }
        }

        // 3. 無結果或未啟用 InkMagine → 標記需人工補圖
        Log::info('ImageSourceService: 無可用配圖，標記 needs_manual', [
            'article_id' => $article->id,
            'inkmagine_enabled' => $inkmagineEnabled,
        ]);

        return $this->createOrUpdateImage($article, [
            'url' => $originalImageUrl ?? '',
            'thumbnail_url' => $originalImageUrl,
            'source_provider' => null,
            'needs_manual' => true,
        ]);
    }

    /**
     * 判斷 URL 是否屬於 CNA 域名。
     *
     * 從 config 讀取 cna_domains 清單，比對 URL 的 host 部分。
     * 支援完全匹配與子域名匹配。
     *
     * @param string $url 待檢查的圖片 URL
     * @return bool 是否為 CNA 域名
     */
    public function isCnaDomain(string $url): bool
    {
        $cnaDomains = config('trending-summary.images.cna_domains', []);

        if ($cnaDomains === []) {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);

        if ($host === null || $host === false || $host === '') {
            return false;
        }

        $host = strtolower((string) $host);

        foreach ($cnaDomains as $domain) {
            $domain = strtolower(trim($domain));

            if ($domain === '') {
                continue;
            }

            // 完全匹配或子域名匹配
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 透過 InkMagine Gateway API 搜尋圖片。
     *
     * 使用 OAuth Bearer Token 認證，以關鍵字搜尋 InkMagine 圖庫。
     * API 不可達或回傳錯誤時回傳 null。
     *
     * @param string $query 搜尋關鍵字
     * @return array<int, array<string, mixed>>|null 圖片結果陣列，失敗時回傳 null
     */
    public function searchInkMagine(string $query): ?array
    {
        try {
            $token = $this->getOAuthToken();
            $baseUrl = rtrim((string) config('trending-summary.images.inkmagine.gateway_url', ''), '/');

            if ($baseUrl === '') {
                Log::warning('ImageSourceService: InkMagine gateway_url 未設定');

                return null;
            }

            $response = Http::withToken($token)
                ->timeout(15)
                ->get("{$baseUrl}/api/images", [
                    'q' => $query,
                ]);

            if (! $response->successful()) {
                Log::warning('ImageSourceService: InkMagine 搜尋失敗', [
                    'status' => $response->status(),
                    'query' => $query,
                ]);

                return null;
            }

            $data = $response->json('data', []);

            if (! is_array($data)) {
                return null;
            }

            return $data;
        } catch (\Throwable $e) {
            Log::error('ImageSourceService: InkMagine API 不可達', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * 取得 InkMagine OAuth Bearer Token。
     *
     * 使用 client_credentials grant type 向 InkMagine Gateway 取得 access token。
     * Token 會依其 expires_in 快取，避免重複請求。
     *
     * @return string Bearer Token
     *
     * @throws \RuntimeException 當無法取得 token 時
     */
    public function getOAuthToken(): string
    {
        $cached = Cache::get(self::CACHE_KEY_OAUTH_TOKEN);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $baseUrl = rtrim((string) config('trending-summary.images.inkmagine.gateway_url', ''), '/');
        $clientId = (string) config('trending-summary.images.inkmagine.client_id', '');
        $clientSecret = (string) config('trending-summary.images.inkmagine.client_secret', '');

        if ($baseUrl === '' || $clientId === '' || $clientSecret === '') {
            throw new \RuntimeException(
                'ImageSourceService: InkMagine OAuth 設定不完整（gateway_url、client_id、client_secret 皆為必填）'
            );
        }

        $response = Http::asForm()
            ->timeout(10)
            ->post("{$baseUrl}/oauth/token", [
                'grant_type' => 'client_credentials',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException(
                "ImageSourceService: InkMagine OAuth 認證失敗，HTTP {$response->status()}"
            );
        }

        $accessToken = $response->json('access_token', '');
        $expiresIn = (int) $response->json('expires_in', 3600);

        if (! is_string($accessToken) || $accessToken === '') {
            throw new \RuntimeException(
                'ImageSourceService: InkMagine OAuth 回應中缺少 access_token'
            );
        }

        // 提前 60 秒過期，避免邊界情況
        $cacheTtl = max($expiresIn - 60, 60);
        Cache::put(self::CACHE_KEY_OAUTH_TOKEN, $accessToken, $cacheTtl);

        return $accessToken;
    }

    /**
     * 依關鍵字搜尋圖片（實作 ImageProviderInterface）。
     *
     * 當 InkMagine 啟用時透過 InkMagine API 搜尋，否則回傳空陣列。
     *
     * @param string $query 搜尋關鍵字
     * @param array<string, mixed> $options 額外選項（保留供未來擴充）
     * @return array<int, array<string, mixed>> 圖片結果陣列
     */
    public function search(string $query, array $options = []): array
    {
        $inkmagineEnabled = (bool) config('trending-summary.images.inkmagine.enabled', false);

        if (! $inkmagineEnabled) {
            return [];
        }

        return $this->searchInkMagine($query) ?? [];
    }

    /**
     * 依 ID 取得單一圖片資訊（實作 ImageProviderInterface）。
     *
     * 透過 InkMagine Gateway API 取得指定 ID 的圖片詳細資訊。
     *
     * @param string $id 圖片識別碼
     * @return array<string, mixed>|null 圖片資訊，找不到時回傳 null
     */
    public function getById(string $id): ?array
    {
        $inkmagineEnabled = (bool) config('trending-summary.images.inkmagine.enabled', false);

        if (! $inkmagineEnabled) {
            return null;
        }

        try {
            $token = $this->getOAuthToken();
            $baseUrl = rtrim((string) config('trending-summary.images.inkmagine.gateway_url', ''), '/');

            if ($baseUrl === '') {
                return null;
            }

            $response = Http::withToken($token)
                ->timeout(15)
                ->get("{$baseUrl}/api/images/{$id}");

            if (! $response->successful()) {
                Log::warning('ImageSourceService: InkMagine getById 失敗', [
                    'id' => $id,
                    'status' => $response->status(),
                ]);

                return null;
            }

            $data = $response->json('data');

            return is_array($data) ? $data : null;
        } catch (\Throwable $e) {
            Log::error('ImageSourceService: InkMagine getById API 不可達', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * 從文章中提取原始圖片 URL。
     *
     * 嘗試從文章的 content_body 中解析第一個 <img> 標籤的 src 屬性。
     *
     * @param TrendingArticle $article 文章
     * @return string|null 圖片 URL，找不到時回傳 null
     */
    private function extractOriginalImageUrl(TrendingArticle $article): ?string
    {
        $contentBody = (string) $article->content_body;

        if ($contentBody === '') {
            return null;
        }

        // 嘗試從 HTML 內容中提取第一個 img src
        if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $contentBody, $matches)) {
            $url = trim($matches[1]);

            return $url !== '' ? $url : null;
        }

        return null;
    }

    /**
     * 建構 InkMagine 搜尋查詢字串。
     *
     * 結合文章標題與趨勢關鍵字作為搜尋查詢。
     *
     * @param TrendingArticle $article 文章
     * @return string 搜尋查詢字串
     */
    private function buildSearchQuery(TrendingArticle $article): string
    {
        $parts = [];

        $title = trim((string) $article->title);
        if ($title !== '') {
            $parts[] = $title;
        }

        $trendKeywords = $article->trend_keywords;
        if (is_array($trendKeywords)) {
            foreach (array_slice($trendKeywords, 0, 3) as $keyword) {
                if (is_string($keyword) && trim($keyword) !== '') {
                    $parts[] = trim($keyword);
                }
            }
        }

        return implode(' ', $parts);
    }

    /**
     * 從圖片資料中解析 preferred_version 的 URL。
     *
     * 依 preferred_version 設定（如 social、desktop、mobile）從 versions 中取得對應 URL。
     * 若 versions 中無對應版本，fallback 至圖片主 URL。
     *
     * @param array<string, mixed> $imageData 圖片資料
     * @param string $preferredVersion 偏好版本名稱
     * @return string 圖片 URL
     */
    private function resolvePreferredVersionUrl(array $imageData, string $preferredVersion): string
    {
        $versions = $imageData['versions'] ?? [];

        if (is_array($versions) && isset($versions[$preferredVersion])) {
            $versionData = $versions[$preferredVersion];

            // versions 可能是 ['social' => 'url'] 或 ['social' => ['url' => '...']]
            if (is_string($versionData) && $versionData !== '') {
                return $versionData;
            }

            if (is_array($versionData) && isset($versionData['url']) && is_string($versionData['url'])) {
                return $versionData['url'];
            }
        }

        // Fallback 至主 URL
        return (string) ($imageData['url'] ?? '');
    }

    /**
     * 建立或更新文章的 ArticleImage 記錄。
     *
     * 若文章已有 ArticleImage 則更新，否則建立新記錄。
     *
     * @param TrendingArticle $article 文章
     * @param array<string, mixed> $attributes 圖片屬性
     * @return ArticleImage 建立或更新後的圖片記錄
     */
    private function createOrUpdateImage(TrendingArticle $article, array $attributes): ArticleImage
    {
        $existing = $article->image;

        if ($existing !== null) {
            $existing->update($attributes);

            return $existing->refresh();
        }

        $attributes['article_id'] = $article->id;

        /** @var ArticleImage $image */
        $image = ArticleImage::create($attributes);

        // 清除 relationship 快取
        $article->unsetRelation('image');

        return $image;
    }
}
