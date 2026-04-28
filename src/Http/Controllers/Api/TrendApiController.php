<?php

declare(strict_types=1);

namespace TnlMedia\TrendingSummary\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use TnlMedia\TrendingSummary\Http\Resources\TrendResource;
use TnlMedia\TrendingSummary\Models\TrendingArticle;
use TnlMedia\TrendingSummary\Models\TrendKeyword;

/**
 * 趨勢 API 控制器
 *
 * 提供趨勢關鍵字列表與統計數據端點。
 */
class TrendApiController extends Controller
{
    /**
     * 取得當前趨勢關鍵字列表。
     */
    public function keywords(): JsonResponse
    {
        $keywords = TrendKeyword::query()
            ->orderByDesc('trend_date')
            ->orderByDesc('traffic_volume')
            ->limit(50)
            ->get();

        return response()->json([
            'data' => TrendResource::collection($keywords),
        ]);
    }

    /**
     * 取得文章統計數據（各狀態數量）。
     */
    public function stats(): JsonResponse
    {
        $statuses = TrendingArticle::query()
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $total = TrendingArticle::count();
        $pendingReview = $statuses->get('reviewing', 0);
        $published = $statuses->get('published', 0);
        $approved = $statuses->get('approved', 0);

        return response()->json([
            'data' => [
                'total' => $total,
                'pending_review' => $pendingReview,
                'published' => $published,
                'approved' => $approved,
                'by_status' => $statuses,
            ],
        ]);
    }
}
