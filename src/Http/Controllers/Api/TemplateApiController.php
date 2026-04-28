<?php

declare(strict_types=1);

namespace TnlMedia\TrendingSummary\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use TnlMedia\TrendingSummary\Http\Requests\StoreTemplateRequest;
use TnlMedia\TrendingSummary\Http\Resources\TemplateResource;
use TnlMedia\TrendingSummary\Models\ArticleTemplate;
use TnlMedia\TrendingSummary\Services\ArticleTemplateService;

/**
 * 模板 API 控制器
 *
 * 提供模板的 CRUD 操作端點，委派至 ArticleTemplateService 處理。
 * 內建模板不可刪除。
 */
class TemplateApiController extends Controller
{
    /**
     * 建構子，注入 ArticleTemplateService。
     */
    public function __construct(
        protected ArticleTemplateService $templateService,
    ) {}

    /**
     * 取得所有模板列表。
     */
    public function index(): AnonymousResourceCollection
    {
        return TemplateResource::collection($this->templateService->list());
    }

    /**
     * 建立自訂模板。
     */
    public function store(StoreTemplateRequest $request): JsonResponse
    {
        $template = $this->templateService->create($request->validated());

        return response()->json([
            'data' => new TemplateResource($template),
        ], 201);
    }

    /**
     * 取得單一模板詳情。
     */
    public function show(ArticleTemplate $template): TemplateResource
    {
        return new TemplateResource($template);
    }

    /**
     * 更新模板。
     */
    public function update(StoreTemplateRequest $request, ArticleTemplate $template): TemplateResource
    {
        $updated = $this->templateService->update($template, $request->validated());

        return new TemplateResource($updated);
    }

    /**
     * 刪除模板（內建模板不可刪除）。
     */
    public function destroy(ArticleTemplate $template): JsonResponse
    {
        try {
            $this->templateService->delete($template);

            return response()->json(['message' => '模板已刪除。']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
