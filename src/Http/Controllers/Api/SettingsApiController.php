<?php

declare(strict_types=1);

namespace TnlMedia\TrendingSummary\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * 設定 API 控制器
 *
 * 提供系統設定的讀取與更新端點。
 * 設定值來自 config，更新操作僅在當前請求生命週期內生效（runtime override）。
 * 持久化設定需由宿主專案自行處理（如寫入 .env 或資料庫）。
 */
class SettingsApiController extends Controller
{
    /**
     * 取得當前系統設定。
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => [
                'mode' => config('trending-summary.mode', 'review'),
                'auto_publish_threshold' => (int) config('trending-summary.auto_publish_threshold', 80),
                'schedule' => config('trending-summary.schedule'),
                'feeds' => config('trending-summary.feeds'),
                'ai' => [
                    'embedding' => [
                        'driver' => config('trending-summary.ai.embedding.driver'),
                        'model' => config('trending-summary.ai.embedding.model'),
                        'threshold' => config('trending-summary.ai.embedding.threshold'),
                    ],
                    'llm' => [
                        'driver' => config('trending-summary.ai.llm.driver'),
                        'model' => config('trending-summary.ai.llm.model'),
                    ],
                    'translation' => [
                        'driver' => config('trending-summary.ai.translation.driver'),
                        'model' => config('trending-summary.ai.translation.model'),
                    ],
                ],
                'images' => [
                    'cna_domains' => config('trending-summary.images.cna_domains'),
                    'inkmagine_enabled' => (bool) config('trending-summary.images.inkmagine.enabled'),
                ],
                'publishing' => [
                    'default_targets' => config('trending-summary.publishing.default_targets'),
                    'default_status' => config('trending-summary.publishing.default_status'),
                    'inkmagine_enabled' => (bool) config('trending-summary.publishing.inkmagine.enabled'),
                    'wordpress_enabled' => (bool) config('trending-summary.publishing.wordpress.enabled'),
                ],
                'subtitle' => config('trending-summary.subtitle'),
                'seo' => [
                    'enabled' => (bool) config('trending-summary.seo.enabled'),
                    'site_name' => config('trending-summary.seo.site_name'),
                    'aio_enabled' => (bool) config('trending-summary.seo.aio.enabled'),
                ],
            ],
        ]);
    }

    /**
     * 更新系統設定（runtime override）。
     *
     * 注意：此操作僅在當前請求生命週期內生效。
     * 持久化設定需由宿主專案自行處理。
     */
    public function update(Request $request): JsonResponse
    {
        $allowedKeys = [
            'mode',
            'auto_publish_threshold',
        ];

        $updated = [];

        foreach ($allowedKeys as $key) {
            if ($request->has($key)) {
                $value = $request->input($key);
                config(["trending-summary.{$key}" => $value]);
                $updated[$key] = $value;
            }
        }

        if ($updated === []) {
            return response()->json(['message' => '未提供可更新的設定。'], 422);
        }

        return response()->json([
            'message' => '設定已更新（runtime）。',
            'updated' => $updated,
        ]);
    }
}
