<?php

declare(strict_types=1);

namespace TnlMedia\TrendingSummary\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use TnlMedia\TrendingSummary\Events\ArticleSynced;
use TnlMedia\TrendingSummary\Models\TrendingArticle;
use willvincent\Feeds\Facades\FeedsFacade as Feeds;

/**
 * RSS Feed 同步服務
 *
 * 負責從設定檔中的 RSS feed 來源抓取文章與影片，
 * 解析 RSS entry、分類內容類型、去重、儲存為 TrendingArticle，
 * 並觸發 ArticleSynced 事件。
 */
class RssFeedService
{
    /**
     * 影片平台域名清單，用於偵測影片類型內容。
     *
     * @var list<string>
     */
    private const array VIDEO_DOMAINS = [
        'youtube.com',
        'youtu.be',
        'vimeo.com',
        'dailymotion.com',
        'twitch.tv',
    ];

    /**
     * 同步所有設定的 RSS feed 來源。
     *
     * 從 config('trending-summary.feeds.sources') 讀取所有來源，
     * 逐一解析並儲存新文章，回傳新同步的文章總數。
     *
     * @return int 新同步的文章數量
     */
    public function sync(): int
    {
        /** @var list<array{name: string, url: string, content_type: string}> $sources */
        $sources = config('trending-summary.feeds.sources', []);
        $totalSynced = 0;

        foreach ($sources as $feedConfig) {
            $totalSynced += $this->syncFeed($feedConfig);
        }

        return $totalSynced;
    }

    /**
     * 同步單一 RSS feed 來源。
     *
     * 嘗試抓取並解析指定的 RSS feed，對每個 entry 進行去重檢查後儲存。
     * 若 feed 不可達或 XML 無效，記錄錯誤並繼續。
     *
     * @param array{name: string, url: string, content_type: string} $feedConfig feed 來源設定
     * @return int 此 feed 新同步的文章數量
     */
    public function syncFeed(array $feedConfig): int
    {
        $url = $feedConfig['url'] ?? '';
        $sourceName = $feedConfig['name'] ?? 'Unknown';

        try {
            $feed = Feeds::make($url);
        } catch (\Throwable $e) {
            Log::error('RSS feed 解析失敗', [
                'source_name' => $sourceName,
                'feed_url' => $url,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }

        if ($feed->error()) {
            Log::error('RSS feed 不可達或 XML 無效', [
                'source_name' => $sourceName,
                'feed_url' => $url,
                'error' => $feed->error(),
            ]);

            return 0;
        }

        $synced = 0;
        $items = $feed->get_items() ?? [];

        foreach ($items as $item) {
            $parsed = $this->parseEntry($item, $feedConfig);

            if ($parsed === null) {
                continue;
            }

            if ($this->isDuplicate($parsed['original_url'])) {
                continue;
            }

            $article = TrendingArticle::create([
                'uuid' => Str::uuid()->toString(),
                'title' => $parsed['title'],
                'original_title' => $parsed['title'],
                'original_url' => $parsed['original_url'],
                'source_name' => $parsed['source_name'],
                'content_type' => $parsed['content_type'],
                'content_body' => $parsed['content_body'],
                'status' => 'pending',
                'trend_keywords' => [],
            ]);

            // 若為影片類型且有字幕 URL，建立字幕記錄
            if ($parsed['content_type'] === 'video' && ! empty($parsed['subtitle_url'])) {
                $article->subtitle()->create([
                    'original_language' => 'en',
                    'source_format' => $this->detectSubtitleFormat($parsed['subtitle_url']),
                    'original_cues' => [],
                    'translation_status' => 'pending',
                ]);
            }

            ArticleSynced::dispatch($article);
            $synced++;
        }

        return $synced;
    }

    /**
     * 解析單一 RSS entry。
     *
     * 從 RSS item 中提取 title、original_url、source_name、content_body，
     * 並判斷 content_type 與字幕 URL。
     *
     * @param \SimplePie_Item $item RSS feed item
     * @param array{name: string, url: string, content_type: string} $feedConfig feed 來源設定
     * @return array{title: string, original_url: string, source_name: string, content_body: string, content_type: string, subtitle_url: ?string, published_at: ?string}|null 解析結果，無效時回傳 null
     */
    public function parseEntry($item, array $feedConfig): ?array
    {
        $title = $item->get_title();
        $link = $item->get_link();

        if (empty($title) || empty($link)) {
            return null;
        }

        $contentBody = $item->get_content()
            ?? $item->get_description()
            ?? '';

        $contentType = $this->detectContentType($item, $feedConfig);
        $subtitleUrl = $contentType === 'video'
            ? $this->extractSubtitleUrl($item)
            : null;

        return [
            'title' => $title,
            'original_url' => $link,
            'source_name' => $feedConfig['name'] ?? 'Unknown',
            'content_body' => $contentBody,
            'content_type' => $contentType,
            'subtitle_url' => $subtitleUrl,
            'published_at' => $item->get_date('Y-m-d H:i:s'),
        ];
    }

    /**
     * 偵測 RSS entry 的內容類型（article 或 video）。
     *
     * 判斷邏輯優先順序：
     * 1. feed 來源設定的 content_type 為 'video'
     * 2. entry URL 包含影片平台域名
     * 3. entry 含有 video enclosure 或 media:content（medium=video）
     * 4. 以上皆非則為 'article'
     *
     * @param \SimplePie_Item $item RSS feed item
     * @param array{name: string, url: string, content_type: string} $feedConfig feed 來源設定
     * @return string 'article' 或 'video'
     */
    public function detectContentType($item, array $feedConfig): string
    {
        // 1. 設定檔指定為 video
        if (($feedConfig['content_type'] ?? 'article') === 'video') {
            return 'video';
        }

        // 2. URL 包含影片平台域名
        $link = $item->get_link() ?? '';
        foreach (self::VIDEO_DOMAINS as $domain) {
            if (str_contains(strtolower($link), $domain)) {
                return 'video';
            }
        }

        // 3. 檢查 enclosure 是否為影片類型
        $enclosures = $item->get_enclosures() ?? [];
        foreach ($enclosures as $enclosure) {
            $type = $enclosure->get_type() ?? '';
            $medium = $enclosure->get_medium() ?? '';

            if (str_starts_with($type, 'video/') || $medium === 'video') {
                return 'video';
            }
        }

        return 'article';
    }

    /**
     * 從 RSS entry 中提取字幕檔 URL。
     *
     * 搜尋 enclosure 與 media 元素中的字幕連結，
     * 支援 .srt 與 .vtt 格式，以及 media:subtitle / media:text 元素。
     *
     * @param \SimplePie_Item $item RSS feed item
     * @return string|null 字幕檔 URL，找不到時回傳 null
     */
    public function extractSubtitleUrl($item): ?string
    {
        // 檢查 enclosures 中的字幕連結
        $enclosures = $item->get_enclosures() ?? [];
        foreach ($enclosures as $enclosure) {
            // 檢查 captions
            $captions = $enclosure->get_captions() ?? [];
            foreach ($captions as $caption) {
                $captionLink = $caption->get_link();
                if (! empty($captionLink)) {
                    return $captionLink;
                }
            }

            // 檢查連結是否為字幕檔
            $enclosureLink = $enclosure->get_link() ?? '';
            if ($this->isSubtitleUrl($enclosureLink)) {
                return $enclosureLink;
            }
        }

        // 嘗試從 item 的原始 XML 資料中尋找字幕相關元素
        $rawData = $item->get_item_tags('http://search.yahoo.com/mrss/', 'subtitle')
            ?? $item->get_item_tags('http://search.yahoo.com/mrss/', 'text')
            ?? [];

        foreach ($rawData as $element) {
            $href = $element['attribs']['']['href'] ?? $element['data'] ?? '';
            if (! empty($href) && $this->isSubtitleUrl($href)) {
                return $href;
            }
        }

        return null;
    }

    /**
     * 檢查指定的 original_url 是否已存在於資料庫中。
     *
     * @param string $originalUrl 文章原始 URL
     * @return bool 是否為重複文章
     */
    public function isDuplicate(string $originalUrl): bool
    {
        return TrendingArticle::where('original_url', $originalUrl)->exists();
    }

    /**
     * 判斷 URL 是否為字幕檔。
     *
     * @param string $url 待檢查的 URL
     * @return bool 是否為字幕檔 URL
     */
    private function isSubtitleUrl(string $url): bool
    {
        if (empty($url)) {
            return false;
        }

        $path = strtolower(parse_url($url, PHP_URL_PATH) ?? '');

        return str_ends_with($path, '.srt') || str_ends_with($path, '.vtt');
    }

    /**
     * 從字幕 URL 偵測字幕格式。
     *
     * @param string $url 字幕檔 URL
     * @return string 字幕格式（'srt' 或 'vtt'）
     */
    private function detectSubtitleFormat(string $url): string
    {
        $path = strtolower(parse_url($url, PHP_URL_PATH) ?? '');

        if (str_ends_with($path, '.vtt')) {
            return 'vtt';
        }

        return 'srt';
    }
}
