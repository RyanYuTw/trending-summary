<?php

declare(strict_types=1);

namespace TnlMedia\TrendingSummary\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use TnlMedia\TrendingSummary\Http\Requests\BatchActionRequest;
use TnlMedia\TrendingSummary\Http\Requests\ReviewArticleRequest;
use TnlMedia\TrendingSummary\Http\Resources\ArticleResource;
use TnlMedia\TrendingSummary\Jobs\PublishArticleJob;
use TnlMedia\TrendingSummary\Models\TrendingArticle;
use TnlMedia\TrendingSummary\Services\ArticleGeneratorService;

/**
 * 文章 API 控制器
 *
 * 提供文章列表、詳情、更新、重新生成、發佈、批次操作、發佈狀態查詢等端點。
 * 所有業務邏輯委派至對應的 Service 層處理。
 */
class ArticleApiController extends Controller
{
    /**
     * 建構子，注入 ArticleGeneratorService。
     */
    public function __construct(
        protected ArticleGeneratorService $generatorService,
    ) {}

    /**
     * 取得文章列表（支援篩選與排序）。
     *
     * 支援的篩選條件：status、source_name、date_from、date_to、content_type。
     * 支援的排序：sort_by（relevance_score、created_at）、sort_dir（asc、desc）。
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = TrendingArticle::query();

        // 篩選：狀態
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        // 篩選：來源
        if ($request->filled('source_name')) {
            $query->where('source_name', $request->input('source_name'));
        }

        // 篩選：內容類型
        if ($request->filled('content_type')) {
            $query->where('content_type', $request->input('content_type'));
        }

        // 篩選：日期範圍
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        }

        // 排序
        $sortBy = $request->input('sort_by', 'created_at');
        $sortDir = $request->input('sort_dir', 'desc');

        if (in_array($sortBy, ['relevance_score', 'created_at', 'quality_score'], true)) {
            $query->orderBy($sortBy, $sortDir === 'asc' ? 'asc' : 'desc');
        }

        $perPage = min((int) $request->input('per_page', 20), 100);

        return ArticleResource::collection($query->paginate($perPage));
    }

    /**
     * 取得單篇文章詳情（含所有關聯資料）。
     */
    public function show(TrendingArticle $article): ArticleResource
    {
        $article->load([
            'generatedTitles',
            'image',
            'subtitle',
            'seo',
            'qualityReport',
            'publishRecords',
            'template',
        ]);

        return new ArticleResource($article);
    }

    /**
     * 更新文章（審核操作：approve / reject / skip，或更新 selected_title）。
     */
    public function update(ReviewArticleRequest $request, TrendingArticle $article): ArticleResource
    {
        $validated = $request->validated();

        if (isset($validated['action'])) {
            $statusMap = [
                'approve' => 'approved',
                'reject' => 'rejected',
                'skip' => 'reviewing',
            ];

            $article->update([
                'status' => $statusMap[$validated['action']],
            ]);
        }

        if (isset($validated['selected_title'])) {
            $article->update(['selected_title' => $validated['selected_title']]);
        }

        return new ArticleResource($article->refresh());
    }

    /**
     * 重新生成文章摘要。
     */
    public function regenerate(TrendingArticle $article): JsonResponse
    {
        $this->generatorService->regenerate($article);

        return response()->json([
            'message' => '摘要重新生成已完成。',
            'data' => new ArticleResource($article->refresh()),
        ]);
    }

    /**
     * 發佈文章至指定平台。
     */
    public function publish(Request $request, TrendingArticle $article): JsonResponse
    {
        /** @var array<int, string> $platforms */
        $platforms = $request->input(
            'platforms',
            config('trending-summary.publishing.default_targets', ['inkmagine']),
        );

        $publishStatus = (string) $request->input('publish_status', config('trending-summary.publishing.default_status', 'published'));

        foreach ($platforms as $platform) {
            PublishArticleJob::dispatch($article, $platform, ['status' => $publishStatus]);
        }

        $article->update(['status' => 'approved']);

        return response()->json([
            'message' => '發佈任務已排入佇列。',
            'platforms' => $platforms,
        ]);
    }

    /**
     * 取得文章的發佈狀態。
     */
    public function publishStatus(TrendingArticle $article): JsonResponse
    {
        $records = $article->publishRecords()->get();

        return response()->json([
            'data' => $records->map(fn ($record) => [
                'id' => $record->id,
                'platform' => $record->platform,
                'status' => $record->status,
                'external_id' => $record->external_id,
                'external_url' => $record->external_url,
                'error_message' => $record->error_message,
                'published_at' => $record->published_at?->toIso8601String(),
            ]),
        ]);
    }

    /**
     * 批次操作（approve / reject / skip）。
     */
    public function batchAction(BatchActionRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $statusMap = [
            'approve' => 'approved',
            'reject' => 'rejected',
            'skip' => 'reviewing',
        ];

        $status = $statusMap[$validated['action']];

        $updated = TrendingArticle::whereIn('id', $validated['article_ids'])
            ->update(['status' => $status]);

        return response()->json([
            'message' => "已更新 {$updated} 篇文章狀態為 {$status}。",
            'updated_count' => $updated,
        ]);
    }
}
