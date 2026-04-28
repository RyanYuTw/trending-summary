<?php

declare(strict_types=1);

namespace TnlMedia\TrendingSummary\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use TnlMedia\TrendingSummary\Models\TrendingArticle;

/**
 * RSS 文章同步完成事件
 *
 * 當 RssFeedService 成功同步一篇新文章後觸發，
 * 通知宿主專案有新文章已匯入系統。
 */
class ArticleSynced
{
    use Dispatchable;
    use SerializesModels;

    /**
     * 建立新的事件實例。
     *
     * @param TrendingArticle $article 已同步的趨勢文章
     */
    public function __construct(
        public readonly TrendingArticle $article,
    ) {}
}
