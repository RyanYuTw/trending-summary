<?php

declare(strict_types=1);

namespace TnlMedia\TrendingSummary\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use TnlMedia\TrendingSummary\Models\TrendingArticle;

/**
 * 摘要生成完成事件
 *
 * 當 ArticleGeneratorService 成功為文章生成摘要後觸發，
 * 可用於觸發後續流程（圖片配置、SEO 產生等）。
 */
class SummaryGenerated
{
    use Dispatchable;
    use SerializesModels;

    /**
     * 建立新的事件實例。
     *
     * @param TrendingArticle $article 已生成摘要的趨勢文章
     */
    public function __construct(
        public readonly TrendingArticle $article,
    ) {}
}
