<?php

declare(strict_types=1);

namespace TnlMedia\TrendingSummary\Services\Publishers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use TnlMedia\TrendingSummary\Contracts\PublisherInterface;
use TnlMedia\TrendingSummary\DataTransferObjects\PublishResult;
use TnlMedia\TrendingSummary\Models\TrendingArticle;

/**
 * WordPress 發佈器
 *
 * 透過 WP REST API 將文章發佈至 WordPress。
 * 支援三種認證方式：
 * - Application Password（預設）：使用 WordPress 內建的應用程式密碼
 * - JWT：使用 JWT Authentication for WP REST API 外掛
 * - OAuth：使用 OAuth 1.0a 認證
 *
 * 處理流程：
 * 1. 依設定的認證方式建立已認證的 HTTP client
 * 2. 上傳封面圖至 WordPress Media endpoint
 * 3. 建立文章（title, content, excerpt, featured_media, categories, tags）
 * 4. 寫入 SEO meta（支援 Yoast 與 Rank Math 欄位對應）
 * 5. 支援 published / draft 狀態
 * 6. 記錄 external_id 與 external_url
 *
 * @see \TnlMedia\TrendingSummary\Contracts\PublisherInterface
 */
class WordPressPublisher implements PublisherInterface
{
    /**
     * JWT token 快取鍵名。
     */
    private const string CACHE_KEY_JWT_TOKEN = 'trending-summary:publish:wordpress:jwt_token';

    /**
     * 將文章發佈至 WordPress。
     *
     * 執行流程：
     * 1. 建立已認證的 HTTP client
     * 2. 上傳封面圖至 Media endpoint（若有配圖）
     * 3. 組裝文章內容（含 Direct Answer Block + FAQ）
     * 4. 建立文章至 WordPress
     * 5. 寫入 SEO meta 欄位
     * 6. 回傳 external_id 與 external_url
     *
     * @param  TrendingArticle  $article  待發佈的文章
     * @param  array<string, mixed>  $options  額外選項，支援 'status' => 'published'|'draft'
     * @return PublishResult  發佈結果
     */
    public function publish(TrendingArticle $article, array $options = []): PublishResult
    {
        try {
            $client = $this->buildAuthenticatedClient();
            $siteUrl = $this->getSiteUrl();

            // 上傳封面圖
            $featuredMediaId = $this->uploadFeaturedImage($article, $client, $siteUrl);

            // 組裝文章內容
            $content = $this->buildArticleContent($article);
            $status = $this->resolveStatus($options);

            $payload = [
                'title' => $article->selected_title ?? $article->title,
                'content' => $content,
                'excerpt' => $this->buildExcerpt($article),
                'status' => $status,
            ];

            // 封面圖
            if ($featuredMediaId !== null) {
                $payload['featured_media'] = $featuredMediaId;
            }

            // 作者
            $config = $this->getConfig();
            $authorId = $config['default_author_id'] ?? null;
            if ($authorId !== null) {
                $payload['author'] = (int) $authorId;
            }

            // 分類
            $categories = $this->resolveCategories($article);
            if ($categories !== []) {
                $payload['categories'] = $categories;
            }

            // 標籤
            $tags = $this->resolveTags($article, $client, $siteUrl);
            if ($tags !== []) {
                $payload['tags'] = $tags;
            }

            // 建立文章
            $response = $client->timeout(30)
                ->post("{$siteUrl}/wp-json/wp/v2/posts", $payload);

            if (! $response->successful()) {
                $errorMsg = sprintf(
                    'WordPress 建立文章失敗，HTTP %d：%s',
                    $response->status(),
                    $response->body(),
                );
                Log::error('WordPressPublisher: ' . $errorMsg, [
                    'article_id' => $article->id,
                ]);

                return PublishResult::failure($errorMsg);
            }

            $postId = (string) $response->json('id', '');
            $postUrl = (string) $response->json('link', '');

            // 寫入 SEO meta
            if ($postId !== '') {
                $this->writeSeoMeta($article, $client, $siteUrl, (int) $postId);
            }

            Log::info('WordPressPublisher: 文章發佈成功', [
                'article_id' => $article->id,
                'external_id' => $postId,
                'external_url' => $postUrl,
                'status' => $status,
            ]);

            return PublishResult::success($postId, $postUrl ?: null);
        } catch (\Throwable $e) {
            $errorMsg = "WordPress 發佈失敗：{$e->getMessage()}";
            Log::error('WordPressPublisher: ' . $errorMsg, [
                'article_id' => $article->id,
                'exception' => $e::class,
            ]);

            return PublishResult::failure($errorMsg);
        }
    }

    /**
     * 同步更新已發佈至 WordPress 的文章。
     *
     * 使用 WP REST API 的 POST /wp/v2/posts/{id} 更新遠端文章。
     * 同時更新 SEO meta 欄位。
     *
     * @param  TrendingArticle  $article  已修改的文章
     * @param  string  $externalId  WordPress 遠端文章 ID
     * @return PublishResult  更新結果
     */
    public function update(TrendingArticle $article, string $externalId): PublishResult
    {
        try {
            $client = $this->buildAuthenticatedClient();
            $siteUrl = $this->getSiteUrl();

            // 重新上傳封面圖
            $featuredMediaId = $this->uploadFeaturedImage($article, $client, $siteUrl);

            $content = $this->buildArticleContent($article);

            $payload = [
                'title' => $article->selected_title ?? $article->title,
                'content' => $content,
                'excerpt' => $this->buildExcerpt($article),
            ];

            if ($featuredMediaId !== null) {
                $payload['featured_media'] = $featuredMediaId;
            }

            // 分類
            $categories = $this->resolveCategories($article);
            if ($categories !== []) {
                $payload['categories'] = $categories;
            }

            // 標籤
            $tags = $this->resolveTags($article, $client, $siteUrl);
            if ($tags !== []) {
                $payload['tags'] = $tags;
            }

            $response = $client->timeout(30)
                ->post("{$siteUrl}/wp-json/wp/v2/posts/{$externalId}", $payload);

            if (! $response->successful()) {
                $errorMsg = sprintf(
                    'WordPress 更新文章失敗，HTTP %d：%s',
                    $response->status(),
                    $response->body(),
                );
                Log::error('WordPressPublisher: ' . $errorMsg, [
                    'article_id' => $article->id,
                    'external_id' => $externalId,
                ]);

                return PublishResult::failure($errorMsg);
            }

            $postUrl = (string) $response->json('link', '');

            // 更新 SEO meta
            $this->writeSeoMeta($article, $client, $siteUrl, (int) $externalId);

            Log::info('WordPressPublisher: 文章更新成功', [
                'article_id' => $article->id,
                'external_id' => $externalId,
            ]);

            return PublishResult::success($externalId, $postUrl ?: null);
        } catch (\Throwable $e) {
            $errorMsg = "WordPress 更新失敗：{$e->getMessage()}";
            Log::error('WordPressPublisher: ' . $errorMsg, [
                'article_id' => $article->id,
                'external_id' => $externalId,
                'exception' => $e::class,
            ]);

            return PublishResult::failure($errorMsg);
        }
    }

    /**
     * 檢查與 WordPress 的連線是否正常。
     *
     * 嘗試呼叫 WP REST API 根端點確認連線。
     *
     * @return bool  連線正常回傳 true，否則回傳 false
     */
    public function ping(): bool
    {
        try {
            $client = $this->buildAuthenticatedClient();
            $siteUrl = $this->getSiteUrl();

            $response = $client->timeout(10)
                ->get("{$siteUrl}/wp-json/wp/v2/types");

            return $response->successful();
        } catch (\Throwable $e) {
            Log::warning('WordPressPublisher: ping 失敗', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * 取得此發佈器對應的平台名稱。
     *
     * @return string  'wordpress'
     */
    public function platform(): string
    {
        return 'wordpress';
    }

    // ──────────────────────────────────────────────────────────
    // 認證
    // ──────────────────────────────────────────────────────────

    /**
     * 建立已認證的 HTTP client。
     *
     * 依 config 中的 auth_method 設定，建立對應認證方式的 PendingRequest：
     * - application_password：使用 HTTP Basic Auth（username + application password）
     * - jwt：使用 JWT Bearer Token（含快取）
     * - oauth：使用 OAuth Bearer Token
     *
     * @return PendingRequest  已認證的 HTTP client
     *
     * @throws \RuntimeException  當認證設定不完整或認證失敗時
     */
    private function buildAuthenticatedClient(): PendingRequest
    {
        $config = $this->getConfig();
        $authMethod = (string) ($config['auth_method'] ?? 'application_password');

        return match ($authMethod) {
            'application_password' => $this->buildApplicationPasswordClient($config),
            'jwt' => $this->buildJwtClient($config),
            'oauth' => $this->buildOAuthClient($config),
            default => throw new \RuntimeException(
                "WordPressPublisher: 不支援的認證方式 '{$authMethod}'，支援：application_password、jwt、oauth"
            ),
        };
    }

    /**
     * 建立 Application Password 認證的 HTTP client。
     *
     * 使用 WordPress 內建的應用程式密碼功能，透過 HTTP Basic Auth 認證。
     *
     * @param  array<string, mixed>  $config  WordPress 設定
     * @return PendingRequest  已認證的 HTTP client
     *
     * @throws \RuntimeException  當 username 或 password 未設定時
     */
    private function buildApplicationPasswordClient(array $config): PendingRequest
    {
        $username = (string) ($config['username'] ?? '');
        $password = (string) ($config['application_password'] ?? '');

        if ($username === '' || $password === '') {
            throw new \RuntimeException(
                'WordPressPublisher: Application Password 認證需要 username 與 application_password'
            );
        }

        return Http::withBasicAuth($username, $password)
            ->acceptJson();
    }

    /**
     * 建立 JWT 認證的 HTTP client。
     *
     * 使用 JWT Authentication for WP REST API 外掛，
     * 透過 JWT endpoint 取得 token 後以 Bearer Token 認證。
     * Token 會快取以避免重複請求。
     *
     * @param  array<string, mixed>  $config  WordPress 設定
     * @return PendingRequest  已認證的 HTTP client
     *
     * @throws \RuntimeException  當 JWT 設定不完整或認證失敗時
     */
    private function buildJwtClient(array $config): PendingRequest
    {
        $token = $this->getJwtToken($config);

        return Http::withToken($token)
            ->acceptJson();
    }

    /**
     * 取得 JWT Token。
     *
     * 優先使用快取的 token，過期時重新向 JWT endpoint 請求。
     * 若 config 中已設定靜態 jwt_token，直接使用。
     *
     * @param  array<string, mixed>  $config  WordPress 設定
     * @return string  JWT Token
     *
     * @throws \RuntimeException  當無法取得 token 時
     */
    private function getJwtToken(array $config): string
    {
        // 若有靜態設定的 JWT token，直接使用
        $staticToken = (string) ($config['jwt_token'] ?? '');
        if ($staticToken !== '') {
            return $staticToken;
        }

        // 嘗試從快取取得
        $cached = Cache::get(self::CACHE_KEY_JWT_TOKEN);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        // 向 JWT endpoint 請求新 token
        $siteUrl = $this->getSiteUrl();
        $jwtEndpoint = (string) ($config['jwt_endpoint'] ?? "{$siteUrl}/wp-json/jwt-auth/v1/token");
        $username = (string) ($config['username'] ?? '');
        $password = (string) ($config['application_password'] ?? '');

        if ($username === '' || $password === '') {
            throw new \RuntimeException(
                'WordPressPublisher: JWT 認證需要 username 與 password 來取得 token'
            );
        }

        $response = Http::timeout(10)
            ->post($jwtEndpoint, [
                'username' => $username,
                'password' => $password,
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException(
                "WordPressPublisher: JWT 認證失敗，HTTP {$response->status()}"
            );
        }

        $token = (string) $response->json('token', '');
        if ($token === '') {
            throw new \RuntimeException(
                'WordPressPublisher: JWT 回應中缺少 token'
            );
        }

        // 快取 token（預設 1 小時，提前 60 秒過期）
        Cache::put(self::CACHE_KEY_JWT_TOKEN, $token, 3540);

        return $token;
    }

    /**
     * 建立 OAuth 認證的 HTTP client。
     *
     * 使用 OAuth Bearer Token 認證。Token 從 config 中的 jwt_token 欄位讀取
     * （OAuth 場景下此欄位作為 access token 使用）。
     *
     * @param  array<string, mixed>  $config  WordPress 設定
     * @return PendingRequest  已認證的 HTTP client
     *
     * @throws \RuntimeException  當 OAuth token 未設定時
     */
    private function buildOAuthClient(array $config): PendingRequest
    {
        $token = (string) ($config['jwt_token'] ?? '');

        if ($token === '') {
            throw new \RuntimeException(
                'WordPressPublisher: OAuth 認證需要設定 jwt_token 作為 access token'
            );
        }

        return Http::withToken($token)
            ->acceptJson();
    }

    // ──────────────────────────────────────────────────────────
    // 封面圖上傳
    // ──────────────────────────────────────────────────────────

    /**
     * 上傳封面圖至 WordPress Media endpoint。
     *
     * 從文章的 ArticleImage 取得圖片 URL，下載後上傳至 WordPress。
     * 若文章無配圖或上傳失敗，回傳 null。
     *
     * @param  TrendingArticle  $article  文章
     * @param  PendingRequest  $client  已認證的 HTTP client
     * @param  string  $siteUrl  WordPress 站台 URL
     * @return int|null  WordPress Media ID，失敗時回傳 null
     */
    private function uploadFeaturedImage(
        TrendingArticle $article,
        PendingRequest $client,
        string $siteUrl,
    ): ?int {
        $image = $article->image;

        if ($image === null || $image->needs_manual || $image->url === '') {
            return null;
        }

        try {
            // 下載圖片
            $imageResponse = Http::timeout(15)->get($image->url);

            if (! $imageResponse->successful()) {
                Log::warning('WordPressPublisher: 下載封面圖失敗', [
                    'article_id' => $article->id,
                    'image_url' => $image->url,
                    'status' => $imageResponse->status(),
                ]);

                return null;
            }

            $imageContent = $imageResponse->body();
            $contentType = $imageResponse->header('Content-Type') ?: 'image/jpeg';
            $extension = $this->guessExtension($contentType);
            $filename = sprintf('cover-%d-%s.%s', $article->id, now()->format('YmdHis'), $extension);

            // 上傳至 WordPress Media
            $uploadResponse = $client->timeout(30)
                ->withHeaders([
                    'Content-Disposition' => "attachment; filename=\"{$filename}\"",
                    'Content-Type' => $contentType,
                ])
                ->withBody($imageContent, $contentType)
                ->post("{$siteUrl}/wp-json/wp/v2/media");

            if (! $uploadResponse->successful()) {
                Log::warning('WordPressPublisher: 上傳封面圖至 WordPress 失敗', [
                    'article_id' => $article->id,
                    'status' => $uploadResponse->status(),
                ]);

                return null;
            }

            $mediaId = (int) $uploadResponse->json('id', 0);

            // 設定圖片 alt text
            $caption = $image->caption ?? ($article->selected_title ?? $article->title);
            if ($mediaId > 0 && $caption !== '') {
                $client->timeout(10)
                    ->post("{$siteUrl}/wp-json/wp/v2/media/{$mediaId}", [
                        'alt_text' => $caption,
                    ]);
            }

            Log::info('WordPressPublisher: 封面圖上傳成功', [
                'article_id' => $article->id,
                'media_id' => $mediaId,
            ]);

            return $mediaId > 0 ? $mediaId : null;
        } catch (\Throwable $e) {
            Log::warning('WordPressPublisher: 封面圖上傳過程發生錯誤', [
                'article_id' => $article->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    // ──────────────────────────────────────────────────────────
    // SEO Meta 寫入
    // ──────────────────────────────────────────────────────────

    /**
     * 寫入 SEO meta 欄位至 WordPress 文章。
     *
     * 依 config 中的 seo_plugin 設定，將 SEO 資料寫入對應外掛的 meta 欄位：
     * - Yoast SEO：_yoast_wpseo_title、_yoast_wpseo_metadesc、_yoast_wpseo_focuskw 等
     * - Rank Math：rank_math_title、rank_math_description、rank_math_focus_keyword 等
     *
     * @param  TrendingArticle  $article  文章
     * @param  PendingRequest  $client  已認證的 HTTP client
     * @param  string  $siteUrl  WordPress 站台 URL
     * @param  int  $postId  WordPress 文章 ID
     */
    private function writeSeoMeta(
        TrendingArticle $article,
        PendingRequest $client,
        string $siteUrl,
        int $postId,
    ): void {
        $seo = $article->seo;

        if ($seo === null) {
            return;
        }

        $config = $this->getConfig();
        $seoPlugin = (string) ($config['seo_plugin'] ?? 'yoast');

        $meta = match ($seoPlugin) {
            'yoast' => $this->buildYoastMeta($seo),
            'rankmath' => $this->buildRankMathMeta($seo),
            default => [],
        };

        if ($meta === []) {
            return;
        }

        try {
            $response = $client->timeout(15)
                ->post("{$siteUrl}/wp-json/wp/v2/posts/{$postId}", [
                    'meta' => $meta,
                ]);

            if (! $response->successful()) {
                Log::warning('WordPressPublisher: 寫入 SEO meta 失敗', [
                    'article_id' => $article->id,
                    'post_id' => $postId,
                    'seo_plugin' => $seoPlugin,
                    'status' => $response->status(),
                ]);
            } else {
                Log::info('WordPressPublisher: SEO meta 寫入成功', [
                    'article_id' => $article->id,
                    'post_id' => $postId,
                    'seo_plugin' => $seoPlugin,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('WordPressPublisher: 寫入 SEO meta 過程發生錯誤', [
                'article_id' => $article->id,
                'post_id' => $postId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 建構 Yoast SEO meta 欄位。
     *
     * 對應 Yoast SEO 外掛的 post meta 欄位名稱。
     *
     * @param  \TnlMedia\TrendingSummary\Models\ArticleSeo  $seo  SEO 資料
     * @return array<string, mixed>  Yoast meta 欄位
     */
    private function buildYoastMeta(\TnlMedia\TrendingSummary\Models\ArticleSeo $seo): array
    {
        $meta = [];

        $metaTitle = (string) $seo->meta_title;
        if ($metaTitle !== '') {
            $meta['_yoast_wpseo_title'] = $metaTitle;
        }

        $metaDesc = (string) $seo->meta_description;
        if ($metaDesc !== '') {
            $meta['_yoast_wpseo_metadesc'] = $metaDesc;
        }

        $focusKeyword = (string) $seo->focus_keyword;
        if ($focusKeyword !== '') {
            $meta['_yoast_wpseo_focuskw'] = $focusKeyword;
        }

        $canonicalUrl = (string) $seo->canonical_url;
        if ($canonicalUrl !== '') {
            $meta['_yoast_wpseo_canonical'] = $canonicalUrl;
        }

        // OG 資料
        $ogData = $seo->og_data;
        if (is_array($ogData)) {
            if (isset($ogData['og:title']) && is_string($ogData['og:title'])) {
                $meta['_yoast_wpseo_opengraph-title'] = $ogData['og:title'];
            }
            if (isset($ogData['og:description']) && is_string($ogData['og:description'])) {
                $meta['_yoast_wpseo_opengraph-description'] = $ogData['og:description'];
            }
            if (isset($ogData['og:image']) && is_string($ogData['og:image'])) {
                $meta['_yoast_wpseo_opengraph-image'] = $ogData['og:image'];
            }
        }

        // Twitter 資料
        $twitterData = $seo->twitter_data;
        if (is_array($twitterData)) {
            if (isset($twitterData['twitter:title']) && is_string($twitterData['twitter:title'])) {
                $meta['_yoast_wpseo_twitter-title'] = $twitterData['twitter:title'];
            }
            if (isset($twitterData['twitter:description']) && is_string($twitterData['twitter:description'])) {
                $meta['_yoast_wpseo_twitter-description'] = $twitterData['twitter:description'];
            }
            if (isset($twitterData['twitter:image']) && is_string($twitterData['twitter:image'])) {
                $meta['_yoast_wpseo_twitter-image'] = $twitterData['twitter:image'];
            }
        }

        // JSON-LD schema（Yoast 自動處理，但可透過 meta 補充）
        $jsonLd = $seo->json_ld;
        if (is_array($jsonLd)) {
            $meta['_yoast_wpseo_schema_page_type'] = $jsonLd['@type'] ?? 'WebPage';
            $meta['_yoast_wpseo_schema_article_type'] = $jsonLd['@type'] ?? 'Article';
        }

        return $meta;
    }

    /**
     * 建構 Rank Math SEO meta 欄位。
     *
     * 對應 Rank Math SEO 外掛的 post meta 欄位名稱。
     *
     * @param  \TnlMedia\TrendingSummary\Models\ArticleSeo  $seo  SEO 資料
     * @return array<string, mixed>  Rank Math meta 欄位
     */
    private function buildRankMathMeta(\TnlMedia\TrendingSummary\Models\ArticleSeo $seo): array
    {
        $meta = [];

        $metaTitle = (string) $seo->meta_title;
        if ($metaTitle !== '') {
            $meta['rank_math_title'] = $metaTitle;
        }

        $metaDesc = (string) $seo->meta_description;
        if ($metaDesc !== '') {
            $meta['rank_math_description'] = $metaDesc;
        }

        $focusKeyword = (string) $seo->focus_keyword;
        if ($focusKeyword !== '') {
            $meta['rank_math_focus_keyword'] = $focusKeyword;
        }

        $canonicalUrl = (string) $seo->canonical_url;
        if ($canonicalUrl !== '') {
            $meta['rank_math_canonical_url'] = $canonicalUrl;
        }

        // OG 資料
        $ogData = $seo->og_data;
        if (is_array($ogData)) {
            if (isset($ogData['og:title']) && is_string($ogData['og:title'])) {
                $meta['rank_math_facebook_title'] = $ogData['og:title'];
            }
            if (isset($ogData['og:description']) && is_string($ogData['og:description'])) {
                $meta['rank_math_facebook_description'] = $ogData['og:description'];
            }
            if (isset($ogData['og:image']) && is_string($ogData['og:image'])) {
                $meta['rank_math_facebook_image'] = $ogData['og:image'];
            }
        }

        // Twitter 資料
        $twitterData = $seo->twitter_data;
        if (is_array($twitterData)) {
            if (isset($twitterData['twitter:title']) && is_string($twitterData['twitter:title'])) {
                $meta['rank_math_twitter_title'] = $twitterData['twitter:title'];
            }
            if (isset($twitterData['twitter:description']) && is_string($twitterData['twitter:description'])) {
                $meta['rank_math_twitter_description'] = $twitterData['twitter:description'];
            }
            if (isset($twitterData['twitter:image']) && is_string($twitterData['twitter:image'])) {
                $meta['rank_math_twitter_image'] = $twitterData['twitter:image'];
            }
        }

        // Rank Math schema type
        $jsonLd = $seo->json_ld;
        if (is_array($jsonLd)) {
            $meta['rank_math_rich_snippet'] = strtolower((string) ($jsonLd['@type'] ?? 'article'));
        }

        // 次要關鍵字
        $secondaryKeywords = $seo->secondary_keywords;
        if (is_array($secondaryKeywords) && $secondaryKeywords !== []) {
            $meta['rank_math_focus_keyword'] = implode(
                ',',
                array_merge(
                    [$focusKeyword],
                    array_filter($secondaryKeywords, 'is_string'),
                ),
            );
        }

        return $meta;
    }

    // ──────────────────────────────────────────────────────────
    // 文章內容組裝
    // ──────────────────────────────────────────────────────────

    /**
     * 組裝文章 content 內容。
     *
     * 依序組合：Direct Answer Block → 摘要本文 → FAQ 段落。
     *
     * @param  TrendingArticle  $article  文章
     * @return string  完整的文章 content HTML
     */
    private function buildArticleContent(TrendingArticle $article): string
    {
        $parts = [];
        $seo = $article->seo;

        // Direct Answer Block（置於文章開頭）
        if ($seo !== null) {
            $directAnswer = $seo->direct_answer_block;
            if (is_string($directAnswer) && $directAnswer !== '') {
                $parts[] = '<div class="direct-answer-block">' . $directAnswer . '</div>';
            }
        }

        // 摘要本文
        $summary = (string) $article->summary;
        if ($summary !== '') {
            $parts[] = $summary;
        }

        // FAQ 段落
        if ($seo !== null) {
            $faqItems = $seo->faq_items;
            if (is_array($faqItems) && $faqItems !== []) {
                $parts[] = $this->buildFaqHtml($faqItems);
            }
        }

        return implode("\n\n", $parts);
    }

    /**
     * 建構 FAQ HTML 段落。
     *
     * @param  array<int, array<string, string>>  $faqItems  FAQ 問答陣列
     * @return string  FAQ HTML
     */
    private function buildFaqHtml(array $faqItems): string
    {
        $html = '<section class="faq-section">' . "\n";
        $html .= '<h2>常見問題</h2>' . "\n";

        foreach ($faqItems as $item) {
            $question = htmlspecialchars((string) ($item['question'] ?? ''), ENT_QUOTES, 'UTF-8');
            $answer = (string) ($item['answer'] ?? '');
            $html .= '<div class="faq-item">' . "\n";
            $html .= '  <h3>' . $question . '</h3>' . "\n";
            $html .= '  <p>' . $answer . '</p>' . "\n";
            $html .= '</div>' . "\n";
        }

        $html .= '</section>';

        return $html;
    }

    /**
     * 建構文章摘錄（用於 WordPress 的 excerpt 欄位）。
     *
     * 優先使用 SEO meta_description，否則截取摘要前 200 字。
     *
     * @param  TrendingArticle  $article  文章
     * @return string  摘錄文字
     */
    private function buildExcerpt(TrendingArticle $article): string
    {
        $seo = $article->seo;

        if ($seo !== null) {
            $metaDesc = (string) $seo->meta_description;
            if ($metaDesc !== '') {
                return $metaDesc;
            }
        }

        $summary = strip_tags((string) $article->summary);

        return mb_strlen($summary) > 200
            ? mb_substr($summary, 0, 200) . '…'
            : $summary;
    }

    // ──────────────────────────────────────────────────────────
    // 分類與標籤
    // ──────────────────────────────────────────────────────────

    /**
     * 解析 WordPress 分類 ID。
     *
     * 從文章的趨勢關鍵字與 config 中的 category_mapping 對應 WordPress category ID。
     *
     * @param  TrendingArticle  $article  文章
     * @return array<int, int>  WordPress category ID 陣列
     */
    private function resolveCategories(TrendingArticle $article): array
    {
        $config = $this->getConfig();
        $categoryMapping = $config['category_mapping'] ?? [];

        if (! is_array($categoryMapping) || $categoryMapping === []) {
            return [];
        }

        $categories = [];
        $trendKeywords = $article->trend_keywords;

        if (is_array($trendKeywords)) {
            foreach ($trendKeywords as $keyword) {
                if (is_string($keyword)) {
                    $slug = strtolower(trim($keyword));
                    if (isset($categoryMapping[$slug])) {
                        $categories[] = (int) $categoryMapping[$slug];
                    }
                }
            }
        }

        return array_values(array_unique($categories));
    }

    /**
     * 解析 WordPress 標籤 ID。
     *
     * 當 auto_create_tags 啟用時，將趨勢關鍵字自動建立為 WordPress tag。
     * 若 tag 已存在則使用現有 ID，否則建立新 tag。
     *
     * @param  TrendingArticle  $article  文章
     * @param  PendingRequest  $client  已認證的 HTTP client
     * @param  string  $siteUrl  WordPress 站台 URL
     * @return array<int, int>  WordPress tag ID 陣列
     */
    private function resolveTags(
        TrendingArticle $article,
        PendingRequest $client,
        string $siteUrl,
    ): array {
        $config = $this->getConfig();
        $autoCreateTags = (bool) ($config['auto_create_tags'] ?? true);

        if (! $autoCreateTags) {
            return [];
        }

        $trendKeywords = $article->trend_keywords;

        if (! is_array($trendKeywords) || $trendKeywords === []) {
            return [];
        }

        $tagIds = [];

        foreach ($trendKeywords as $keyword) {
            if (! is_string($keyword) || trim($keyword) === '') {
                continue;
            }

            $tagId = $this->findOrCreateTag($client, $siteUrl, trim($keyword));

            if ($tagId !== null) {
                $tagIds[] = $tagId;
            }
        }

        return array_values(array_unique($tagIds));
    }

    /**
     * 查找或建立 WordPress tag。
     *
     * 先搜尋是否已有同名 tag，若無則建立新 tag。
     *
     * @param  PendingRequest  $client  已認證的 HTTP client
     * @param  string  $siteUrl  WordPress 站台 URL
     * @param  string  $tagName  標籤名稱
     * @return int|null  WordPress tag ID，失敗時回傳 null
     */
    private function findOrCreateTag(PendingRequest $client, string $siteUrl, string $tagName): ?int
    {
        try {
            // 搜尋現有 tag
            $searchResponse = $client->timeout(10)
                ->get("{$siteUrl}/wp-json/wp/v2/tags", [
                    'search' => $tagName,
                    'per_page' => 5,
                ]);

            if ($searchResponse->successful()) {
                $tags = $searchResponse->json();
                if (is_array($tags)) {
                    foreach ($tags as $tag) {
                        if (is_array($tag) && isset($tag['name']) && strtolower((string) $tag['name']) === strtolower($tagName)) {
                            return (int) $tag['id'];
                        }
                    }
                }
            }

            // 建立新 tag
            $createResponse = $client->timeout(10)
                ->post("{$siteUrl}/wp-json/wp/v2/tags", [
                    'name' => $tagName,
                ]);

            if ($createResponse->successful()) {
                return (int) $createResponse->json('id', 0) ?: null;
            }

            return null;
        } catch (\Throwable $e) {
            Log::warning('WordPressPublisher: 建立 tag 失敗', [
                'tag_name' => $tagName,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    // ──────────────────────────────────────────────────────────
    // 輔助方法
    // ──────────────────────────────────────────────────────────

    /**
     * 解析發佈狀態。
     *
     * 將內部狀態值對應至 WordPress REST API 的 status 值。
     *
     * @param  array<string, mixed>  $options  發佈選項
     * @return string  WordPress 狀態值（publish / draft）
     */
    private function resolveStatus(array $options): string
    {
        $requestedStatus = (string) ($options['status']
            ?? config('trending-summary.publishing.default_status', 'published'));

        // WordPress REST API 使用 'publish' 而非 'published'
        return match ($requestedStatus) {
            'published' => 'publish',
            'draft' => 'draft',
            default => 'publish',
        };
    }

    /**
     * 取得 WordPress 站台 URL。
     *
     * @return string  站台 URL（已移除尾部斜線）
     */
    private function getSiteUrl(): string
    {
        $config = $this->getConfig();

        return rtrim((string) ($config['site_url'] ?? ''), '/');
    }

    /**
     * 取得 WordPress 發佈設定。
     *
     * @return array<string, mixed>  設定陣列
     */
    private function getConfig(): array
    {
        $config = config('trending-summary.publishing.wordpress', []);

        return is_array($config) ? $config : [];
    }

    /**
     * 依 Content-Type 猜測副檔名。
     *
     * @param  string  $contentType  HTTP Content-Type
     * @return string  副檔名
     */
    private function guessExtension(string $contentType): string
    {
        return match (true) {
            str_contains($contentType, 'png') => 'png',
            str_contains($contentType, 'gif') => 'gif',
            str_contains($contentType, 'webp') => 'webp',
            str_contains($contentType, 'svg') => 'svg',
            default => 'jpg',
        };
    }
}
