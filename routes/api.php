<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use TnlMedia\TrendingSummary\Http\Controllers\Api\ArticleApiController;
use TnlMedia\TrendingSummary\Http\Controllers\Api\ImageApiController;
use TnlMedia\TrendingSummary\Http\Controllers\Api\SeoApiController;
use TnlMedia\TrendingSummary\Http\Controllers\Api\SettingsApiController;
use TnlMedia\TrendingSummary\Http\Controllers\Api\SubtitleApiController;
use TnlMedia\TrendingSummary\Http\Controllers\Api\TemplateApiController;
use TnlMedia\TrendingSummary\Http\Controllers\Api\TrendApiController;

Route::prefix('trending-summary')->middleware(config('trending-summary.api_middleware', ['api']))->group(function () {
    // Articles
    Route::get('/articles', [ArticleApiController::class, 'index']);
    Route::get('/articles/{article}', [ArticleApiController::class, 'show']);
    Route::put('/articles/{article}', [ArticleApiController::class, 'update']);
    Route::post('/articles/{article}/regenerate', [ArticleApiController::class, 'regenerate']);
    Route::post('/articles/{article}/publish', [ArticleApiController::class, 'publish']);
    Route::get('/articles/{article}/publish-status', [ArticleApiController::class, 'publishStatus']);
    Route::post('/articles/batch', [ArticleApiController::class, 'batchAction']);

    // Trends
    Route::get('/trends/keywords', [TrendApiController::class, 'keywords']);
    Route::get('/trends/stats', [TrendApiController::class, 'stats']);

    // Templates
    Route::apiResource('templates', TemplateApiController::class);

    // Images
    Route::get('/images/search', [ImageApiController::class, 'search']);
    Route::post('/articles/{article}/image', [ImageApiController::class, 'attach']);

    // Subtitles
    Route::get('/articles/{article}/subtitle', [SubtitleApiController::class, 'show']);
    Route::post('/articles/{article}/subtitle/translate', [SubtitleApiController::class, 'translate']);
    Route::put('/articles/{article}/subtitle', [SubtitleApiController::class, 'update']);
    Route::get('/articles/{article}/subtitle/download', [SubtitleApiController::class, 'download']);

    // SEO
    Route::get('/articles/{article}/seo', [SeoApiController::class, 'show']);
    Route::put('/articles/{article}/seo', [SeoApiController::class, 'update']);
    Route::post('/articles/{article}/seo/regenerate', [SeoApiController::class, 'regenerate']);

    // Settings
    Route::get('/settings', [SettingsApiController::class, 'index']);
    Route::put('/settings', [SettingsApiController::class, 'update']);
});
