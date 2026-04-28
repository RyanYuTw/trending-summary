<?php

declare(strict_types=1);

namespace TnlMedia\TrendingSummary\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use TnlMedia\TrendingSummary\Models\PublishRecord;
use TnlMedia\TrendingSummary\Models\TrendingArticle;

/**
 * 文章發佈成功事件
 *
 * 當文章成功發佈至目標平台後觸發，
 * 通知宿主專案並可用於記錄審計日誌。
 */
class ArticlePublished
{
    use Dispatchable;
    use SerializesModels;

    /**
     * 建立新的事件實例。
     *
     * @param TrendingArticle $article 已發佈的趨勢文章
     * @param PublishRecord $publishRecord 對應的發佈記錄
     */
    public function __construct(
        public readonly TrendingArticle $article,
        public readonly PublishRecord $publishRecord,
    ) {}
}
