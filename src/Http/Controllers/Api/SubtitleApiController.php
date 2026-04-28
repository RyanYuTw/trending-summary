<?php

declare(strict_types=1);

namespace TnlMedia\TrendingSummary\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;
use TnlMedia\TrendingSummary\Jobs\TranslateSubtitleJob;
use TnlMedia\TrendingSummary\Models\TrendingArticle;
use TnlMedia\TrendingSummary\Services\SubtitleService;

/**
 * 字幕 API 控制器
 *
 * 提供字幕查看、翻譯觸發、更新、下載等端點，委派至 SubtitleService 處理。
 */
class SubtitleApiController extends Controller
{
    /**
     * 建構子，注入 SubtitleService。
     */
    public function __construct(
        protected SubtitleService $subtitleService,
    ) {}

    /**
     * 取得文章的字幕資料。
     */
    public function show(TrendingArticle $article): JsonResponse
    {
        $subtitle = $article->subtitle;

        if ($subtitle === null) {
            return response()->json(['message' => '此文章無字幕資料。'], 404);
        }

        return response()->json(['data' => $subtitle]);
    }

    /**
     * 觸發字幕翻譯任務。
     */
    public function translate(TrendingArticle $article): JsonResponse
    {
        $subtitle = $article->subtitle;

        if ($subtitle === null) {
            return response()->json(['message' => '此文章無字幕資料。'], 404);
        }

        TranslateSubtitleJob::dispatch($subtitle);

        return response()->json(['message' => '字幕翻譯任務已排入佇列。']);
    }

    /**
     * 更新翻譯後的字幕 cues。
     */
    public function update(Request $request, TrendingArticle $article): JsonResponse
    {
        $subtitle = $article->subtitle;

        if ($subtitle === null) {
            return response()->json(['message' => '此文章無字幕資料。'], 404);
        }

        $validated = $request->validate([
            'translated_cues' => ['required', 'array'],
            'translated_cues.*.index' => ['required', 'integer'],
            'translated_cues.*.start_time' => ['required', 'string'],
            'translated_cues.*.end_time' => ['required', 'string'],
            'translated_cues.*.text' => ['required', 'string'],
        ]);

        $subtitle->update([
            'translated_cues' => $validated['translated_cues'],
            'translation_status' => 'reviewed',
        ]);

        return response()->json([
            'message' => '字幕已更新。',
            'data' => $subtitle->refresh(),
        ]);
    }

    /**
     * 下載翻譯後的字幕檔案（SRT 或 VTT）。
     */
    public function download(Request $request, TrendingArticle $article): JsonResponse|StreamedResponse
    {
        $subtitle = $article->subtitle;

        if ($subtitle === null) {
            return response()->json(['message' => '此文章無字幕資料。'], 404);
        }

        $translatedCues = $subtitle->translated_cues;

        if (empty($translatedCues)) {
            return response()->json(['message' => '尚無翻譯後的字幕可供下載。'], 404);
        }

        $format = $request->input('format', 'srt');

        $content = match ($format) {
            'vtt' => $this->subtitleService->formatVtt($translatedCues),
            default => $this->subtitleService->formatSrt($translatedCues),
        };

        $filename = "subtitle-{$article->id}.{$format}";
        $mimeType = $format === 'vtt' ? 'text/vtt' : 'application/x-subrip';

        return response()->streamDownload(function () use ($content) {
            echo $content;
        }, $filename, [
            'Content-Type' => $mimeType,
        ]);
    }
}
