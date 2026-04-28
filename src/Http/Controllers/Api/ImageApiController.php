<?php

declare(strict_types=1);

namespace TnlMedia\TrendingSummary\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use TnlMedia\TrendingSummary\Models\ArticleImage;
use TnlMedia\TrendingSummary\Models\TrendingArticle;
use TnlMedia\TrendingSummary\Services\ImageSourceService;

/**
 * 圖片 API 控制器
 *
 * 提供圖片搜尋與附加至文章的端點，委派至 ImageSourceService 處理。
 */
class ImageApiController extends Controller
{
    /**
     * 建構子，注入 ImageSourceService。
     */
    public function __construct(
        protected ImageSourceService $imageService,
    ) {}

    /**
     * 搜尋圖片（InkMagine 圖庫）。
     */
    public function search(Request $request): JsonResponse
    {
        $query = (string) $request->input('q', '');

        if ($query === '') {
            return response()->json(['data' => []]);
        }

        $results = $this->imageService->search($query);

        return response()->json(['data' => $results]);
    }

    /**
     * 將圖片附加至文章。
     */
    public function attach(Request $request, TrendingArticle $article): JsonResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'string', 'url'],
            'thumbnail_url' => ['nullable', 'string', 'url'],
            'source_provider' => ['nullable', 'string', 'max:50'],
            'caption' => ['nullable', 'string', 'max:500'],
        ]);

        $image = ArticleImage::updateOrCreate(
            ['article_id' => $article->id],
            [
                'url' => $validated['url'],
                'thumbnail_url' => $validated['thumbnail_url'] ?? $validated['url'],
                'source_provider' => $validated['source_provider'] ?? 'manual',
                'caption' => $validated['caption'] ?? null,
                'needs_manual' => false,
            ],
        );

        return response()->json([
            'message' => '圖片已附加至文章。',
            'data' => $image,
        ]);
    }
}
