<?php

declare(strict_types=1);

namespace TnlMedia\TrendingSummary\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use TnlMedia\TrendingSummary\Models\TrendingArticle;
use TnlMedia\TrendingSummary\Services\SeoService;

/**
 * SEO API 控制器
 *
 * 提供 SEO 資料查看、手動覆寫、重新生成等端點，委派至 SeoService 處理。
 */
class SeoApiController extends Controller
{
    /**
     * 建構子，注入 SeoService。
     */
    public function __construct(
        protected SeoService $seoService,
    ) {}

    /**
     * 取得文章的 SEO 資料。
     */
    public function show(TrendingArticle $article): JsonResponse
    {
        $seo = $article->seo;

        if ($seo === null) {
            return response()->json(['message' => '此文章尚無 SEO 資料。'], 404);
        }

        return response()->json(['data' => $seo]);
    }

    /**
     * 手動覆寫 SEO 欄位。
     */
    public function update(Request $request, TrendingArticle $article): JsonResponse
    {
        $seo = $article->seo;

        if ($seo === null) {
            return response()->json(['message' => '此文章尚無 SEO 資料，請先生成。'], 404);
        }

        $updated = $this->seoService->updateSeo($seo, $request->all());

        return response()->json([
            'message' => 'SEO 資料已更新。',
            'data' => $updated,
        ]);
    }

    /**
     * 重新生成 SEO 資料。
     */
    public function regenerate(TrendingArticle $article): JsonResponse
    {
        $seo = $this->seoService->generateSeo($article);

        return response()->json([
            'message' => 'SEO 資料已重新生成。',
            'data' => $seo,
        ]);
    }
}
