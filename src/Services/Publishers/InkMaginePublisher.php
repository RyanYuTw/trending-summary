<?php

declare(strict_types=1);

namespace TnlMedia\TrendingSummary\Services\Publishers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use TnlMedia\TrendingSummary\Contracts\PublisherInterface;
use TnlMedia\TrendingSummary\DataTransferObjects\PublishResult;
use TnlMedia\TrendingSummary\Models\TrendingArticle;

/**
 * InkMagine CMS 發佈器
 *
 * 透過 InkMagine Gateway API 將文章發佈至 InkMagine CMS。
 * 處理流程：
 * 1. 使用 OAuth client credentials 取得 Bearer Token（含快取）
 * 2. 上傳封面圖至 Bucket
 * 3. 建立文章（title, summary, body 含 Direct Answer Block + FAQ, cover, author, terms）
 * 4. 支援 published / draft 狀態
 * 5. 記錄 external_id 與 external_url
 *
 * 認證 Token 快取模式參考 ImageSourceService 的 OAuth 實作。
 *
 * @see \TnlMedia\TrendingSummary\Contracts\PublisherInterface
 */
class InkMaginePublisher implements PublisherInterface
{
    /**
     * OAuth token 快取鍵名。
     */
    private const string CACHE_KEY_OAUTH_TOKEN = 'trending-summary:publish:inkmagine:oauth_token';

    /**
     * 將文章發佈至 InkMagine CMS。
     *
     * 執行流程：
     * 1. 取得 OAuth Bearer Token
     * 2. 上傳封面圖至 Bucket（若有配圖）
     * 3. 組裝文章 body（含 Direct Answer Block + FAQ）
     * 4. 建立文章至 InkMagine CMS
     * 5. 回傳 external_id 與 external_url
     *
     * @param  TrendingArticle  $article  待發佈的文章
     * @param  array<string, mixed>  $options  額外選項，支援 'status' => 'published'|'draft'
     * @return PublishResult  發佈結果
     */
    public function publish(TrendingArticle $article, array $options = []): PublishResult
    {
        try {
            $token = $this->getOAuthToken();
            $baseUrl = $this->getBaseUrl();

            // 上傳封面圖
            $coverId = $this->uploadCoverImage($article, $token, $baseUrl);

            // 組裝文章內容
            $body = $this->buildArticleBody($article);
            $status = $this->resolveStatus($options);
            $config = $this->getConfig();

            $payload = [
                'title' => $article->selected_title ?? $article->title,
                'summary' => $this->buildSummaryExcerpt($article),
                'body' => $body,
                'status' => $status,
                'type' => $config['article_type'] ?? 'news',
            ];

            // 封面圖
            if ($coverId !== null) {
                $payload['cover'] = $coverId;
            }

            // 作者
            $authorId = $config['default_author_id'] ?? null;
            if ($authorId !== null && $authorId !== '') {
                $payload['author'] = $authorId;
            }

            // 分類 terms
            $terms = $this->resolveTerms($article);
            if ($terms !== []) {
                $payload['terms'] = $terms;
            }

            // 建立文章
            $response = Http::withToken($token)
                ->timeout(30)
                ->post("{$baseUrl}/api/articles", $payload);

            if (! $response->successful()) {
                $errorMsg = sprintf(
                    'InkMagine 建立文章失敗，HTTP %d：%s',
                    $response->status(),
                    $response->body(),
                );
                Log::error('InkMaginePublisher: ' . $errorMsg, [
                    'article_id' => $article->id,
                ]);

                return PublishResult::failure($errorMsg);
            }

            $externalId = (string) $response->json('data.id', '');
            $externalUrl = (string) $response->json('data.url', '');

            Log::info('InkMaginePublisher: 文章發佈成功', [
                'article_id' => $article->id,
                'external_id' => $externalId,
                'external_url' => $externalUrl,
                'status' => $status,
            ]);

            return PublishResult::success($externalId, $externalUrl ?: null);
        } catch (\Throwable $e) {
            $errorMsg = "InkMagine 發佈失敗：{$e->getMessage()}";
            Log::error('InkMaginePublisher: ' . $errorMsg, [
                'article_id' => $article->id,
                'exception' => $e::class,
            ]);

            return PublishResult::failure($errorMsg);
        }
    }

    /**
     * 同步更新已發佈至 InkMagine CMS 的文章。
     *
     * 使用 PUT 方法更新遠端文章的 title、summary、body、cover、terms。
     *
     * @param  TrendingArticle  $article  已修改的文章
     * @param  string  $externalId  InkMagine 遠端文章 ID
     * @return PublishResult  更新結果
     */
    public function update(TrendingArticle $article, string $externalId): PublishResult
    {
        try {
            $token = $this->getOAuthToken();
            $baseUrl = $this->getBaseUrl();

            // 重新上傳封面圖
            $coverId = $this->uploadCoverImage($article, $token, $baseUrl);

            $body = $this->buildArticleBody($article);
            $config = $this->getConfig();

            $payload = [
                'title' => $article->selected_title ?? $article->title,
                'summary' => $this->buildSummaryExcerpt($article),
                'body' => $body,
            ];

            if ($coverId !== null) {
                $payload['cover'] = $coverId;
            }

            $authorId = $config['default_author_id'] ?? null;
            if ($authorId !== null && $authorId !== '') {
                $payload['author'] = $authorId;
            }

            $terms = $this->resolveTerms($article);
            if ($terms !== []) {
                $payload['terms'] = $terms;
            }

            $response = Http::withToken($token)
                ->timeout(30)
                ->put("{$baseUrl}/api/articles/{$externalId}", $payload);

            if (! $response->successful()) {
                $errorMsg = sprintf(
                    'InkMagine 更新文章失敗，HTTP %d：%s',
                    $response->status(),
                    $response->body(),
                );
                Log::error('InkMaginePublisher: ' . $errorMsg, [
                    'article_id' => $article->id,
                    'external_id' => $externalId,
                ]);

                return PublishResult::failure($errorMsg);
            }

            $externalUrl = (string) $response->json('data.url', '');

            Log::info('InkMaginePublisher: 文章更新成功', [
                'article_id' => $article->id,
                'external_id' => $externalId,
            ]);

            return PublishResult::success($externalId, $externalUrl ?: null);
        } catch (\Throwable $e) {
            $errorMsg = "InkMagine 更新失敗：{$e->getMessage()}";
            Log::error('InkMaginePublisher: ' . $errorMsg, [
                'article_id' => $article->id,
                'external_id' => $externalId,
                'exception' => $e::class,
            ]);

            return PublishResult::failure($errorMsg);
        }
    }

    /**
     * 檢查與 InkMagine CMS 的連線是否正常。
     *
     * 嘗試取得 OAuth Token 並呼叫健康檢查端點。
     *
     * @return bool  連線正常回傳 true，否則回傳 false
     */
    public function ping(): bool
    {
        try {
            $token = $this->getOAuthToken();
            $baseUrl = $this->getBaseUrl();

            $response = Http::withToken($token)
                ->timeout(10)
                ->get("{$baseUrl}/api/ping");

            return $response->successful();
        } catch (\Throwable $e) {
            Log::warning('InkMaginePublisher: ping 失敗', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * 取得此發佈器對應的平台名稱。
     *
     * @return string  'inkmagine'
     */
    public function platform(): string
    {
        return 'inkmagine';
    }

    // ──────────────────────────────────────────────────────────
    // OAuth 認證
    // ──────────────────────────────────────────────────────────

    /**
     * 取得 InkMagine OAuth Bearer Token。
     *
     * 使用 client_credentials grant type 向 InkMagine Gateway 取得 access token。
     * Token 會依其 expires_in 快取，避免重複請求。
     * 快取模式參考 ImageSourceService 的 OAuth 實作。
     *
     * @return string  Bearer Token
     *
     * @throws \RuntimeException  當無法取得 token 時
     */
    private function getOAuthToken(): string
    {
        $cached = Cache::get(self::CACHE_KEY_OAUTH_TOKEN);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $config = $this->getConfig();
        $baseUrl = $this->getBaseUrl();
        $clientId = (string) ($config['client_id'] ?? '');
        $clientSecret = (string) ($config['client_secret'] ?? '');

        if ($baseUrl === '' || $clientId === '' || $clientSecret === '') {
            throw new \RuntimeException(
                'InkMaginePublisher: OAuth 設定不完整（gateway_url、client_id、client_secret 皆為必填）'
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
                "InkMaginePublisher: OAuth 認證失敗，HTTP {$response->status()}"
            );
        }

        $accessToken = $response->json('access_token', '');
        $expiresIn = (int) $response->json('expires_in', 3600);

        if (! is_string($accessToken) || $accessToken === '') {
            throw new \RuntimeException(
                'InkMaginePublisher: OAuth 回應中缺少 access_token'
            );
        }

        // 提前 60 秒過期，避免邊界情況
        $cacheTtl = max($expiresIn - 60, 60);
        Cache::put(self::CACHE_KEY_OAUTH_TOKEN, $accessToken, $cacheTtl);

        return $accessToken;
    }

    // ──────────────────────────────────────────────────────────
    // 封面圖上傳
    // ──────────────────────────────────────────────────────────

    /**
     * 上傳封面圖至 InkMagine Bucket。
     *
     * 從文章的 ArticleImage 取得圖片 URL，下載後上傳至 InkMagine Bucket。
     * 若文章無配圖或上傳失敗，回傳 null。
     *
     * @param  TrendingArticle  $article  文章
     * @param  string  $token  OAuth Bearer Token
     * @param  string  $baseUrl  InkMagine Gateway 基礎 URL
     * @return string|null  上傳後的 Bucket 檔案 ID，失敗時回傳 null
     */
    private function uploadCoverImage(TrendingArticle $article, string $token, string $baseUrl): ?string
    {
        $image = $article->image;

        if ($image === null || $image->needs_manual || $image->url === '') {
            return null;
        }

        try {
            $config = $this->getConfig();
            $bucket = $config['bucket'] ?? $config['team_id'] ?? 'trending-summary';

            // 下載圖片
            $imageResponse = Http::timeout(15)->get($image->url);

            if (! $imageResponse->successful()) {
                Log::warning('InkMaginePublisher: 下載封面圖失敗', [
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

            // 上傳至 Bucket
            $uploadResponse = Http::withToken($token)
                ->timeout(30)
                ->attach('file', $imageContent, $filename)
                ->post("{$baseUrl}/api/buckets/{$bucket}/files");

            if (! $uploadResponse->successful()) {
                Log::warning('InkMaginePublisher: 上傳封面圖至 Bucket 失敗', [
                    'article_id' => $article->id,
                    'status' => $uploadResponse->status(),
                ]);

                return null;
            }

            $fileId = (string) $uploadResponse->json('data.id', '');

            Log::info('InkMaginePublisher: 封面圖上傳成功', [
                'article_id' => $article->id,
                'file_id' => $fileId,
                'bucket' => $bucket,
            ]);

            return $fileId !== '' ? $fileId : null;
        } catch (\Throwable $e) {
            Log::warning('InkMaginePublisher: 封面圖上傳過程發生錯誤', [
                'article_id' => $article->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    // ──────────────────────────────────────────────────────────
    // 文章內容組裝
    // ──────────────────────────────────────────────────────────

    /**
     * 組裝文章 body 內容。
     *
     * 依序組合：Direct Answer Block → 摘要本文 → FAQ 段落。
     * 若 SEO 資料中有 Direct Answer Block 或 FAQ，會自動嵌入。
     *
     * @param  TrendingArticle  $article  文章
     * @return string  完整的文章 body HTML
     */
    private function buildArticleBody(TrendingArticle $article): string
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
     * 建構摘要摘錄（用於 InkMagine 的 summary 欄位）。
     *
     * 優先使用 SEO meta_description，否則截取摘要前 200 字。
     *
     * @param  TrendingArticle  $article  文章
     * @return string  摘錄文字
     */
    private function buildSummaryExcerpt(TrendingArticle $article): string
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

    /**
     * 解析分類 terms 對應。
     *
     * 從文章的趨勢關鍵字與 config 中的 term_mapping 對應 InkMagine term ID。
     *
     * @param  TrendingArticle  $article  文章
     * @return array<int, string>  InkMagine term ID 陣列
     */
    private function resolveTerms(TrendingArticle $article): array
    {
        $config = $this->getConfig();
        $termMapping = $config['term_mapping'] ?? [];

        if (! is_array($termMapping) || $termMapping === []) {
            return [];
        }

        $terms = [];
        $trendKeywords = $article->trend_keywords;

        if (is_array($trendKeywords)) {
            foreach ($trendKeywords as $keyword) {
                if (is_string($keyword)) {
                    $slug = strtolower(trim($keyword));
                    if (isset($termMapping[$slug])) {
                        $terms[] = (string) $termMapping[$slug];
                    }
                }
            }
        }

        return array_unique($terms);
    }

    // ──────────────────────────────────────────────────────────
    // 輔助方法
    // ──────────────────────────────────────────────────────────

    /**
     * 解析發佈狀態。
     *
     * 從 options 或 config 中取得發佈狀態，對應至 InkMagine 的狀態值。
     *
     * @param  array<string, mixed>  $options  發佈選項
     * @return string  InkMagine 狀態值
     */
    private function resolveStatus(array $options): string
    {
        $config = $this->getConfig();
        $statusMapping = $config['status_mapping'] ?? [
            'published' => 'published',
            'draft' => 'draft',
        ];

        $requestedStatus = (string) ($options['status']
            ?? config('trending-summary.publishing.default_status', 'published'));

        return (string) ($statusMapping[$requestedStatus] ?? $requestedStatus);
    }

    /**
     * 取得 InkMagine Gateway 基礎 URL。
     *
     * @return string  基礎 URL（已移除尾部斜線）
     */
    private function getBaseUrl(): string
    {
        $config = $this->getConfig();

        return rtrim((string) ($config['gateway_url'] ?? ''), '/');
    }

    /**
     * 取得 InkMagine 發佈設定。
     *
     * @return array<string, mixed>  設定陣列
     */
    private function getConfig(): array
    {
        $config = config('trending-summary.publishing.inkmagine', []);

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
