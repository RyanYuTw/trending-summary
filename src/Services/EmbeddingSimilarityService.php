<?php

declare(strict_types=1);

namespace TnlMedia\TrendingSummary\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use TnlMedia\TrendingSummary\Contracts\EmbeddingInterface;
use TnlMedia\TrendingSummary\Models\TrendingArticle;
use TnlMedia\TrendingSummary\Models\TrendKeyword;

/**
 * Embedding 向量相似度粗篩服務
 *
 * 負責以向量嵌入計算文章與趨勢關鍵字的相似度（第一層粗篩）。
 * 透過 EmbeddingInterface 取得文章 title+content 與關鍵字的 embedding 向量，
 * 計算 cosine similarity 後依 Embedding_Threshold 分類為 candidate 或 rejected。
 * 已計算的 embedding 向量會快取以減少重複 API 呼叫。
 */
class EmbeddingSimilarityService
{
    /**
     * 快取鍵前綴。
     */
    private const string CACHE_PREFIX = 'trending-summary:embedding:';

    /**
     * 建構子，注入 EmbeddingInterface。
     *
     * @param EmbeddingInterface $embedding 向量嵌入介面實例
     */
    public function __construct(
        protected EmbeddingInterface $embedding,
    ) {}

    /**
     * 篩選所有 pending 狀態的文章。
     *
     * 主要入口方法。取得所有 pending 文章與當前趨勢關鍵字，
     * 計算每篇文章與關鍵字的最大 cosine similarity，
     * 依 Embedding_Threshold 將文章標記為 candidate 或 rejected。
     *
     * @return int 處理的文章數量
     */
    public function filterPendingArticles(): int
    {
        $keywords = TrendKeyword::query()
            ->orderByDesc('trend_date')
            ->orderByDesc('traffic_volume')
            ->pluck('keyword')
            ->all();

        if ($keywords === []) {
            Log::warning('EmbeddingSimilarityService: 無可用的趨勢關鍵字，跳過篩選');

            return 0;
        }

        $keywordEmbeddings = $this->getKeywordEmbeddings($keywords);

        if ($keywordEmbeddings === []) {
            Log::warning('EmbeddingSimilarityService: 無法取得關鍵字 embedding，跳過篩選');

            return 0;
        }

        $articles = TrendingArticle::where('status', 'pending')->get();
        $processed = 0;

        foreach ($articles as $article) {
            try {
                $this->filterArticle($article, $keywordEmbeddings);
                $processed++;
            } catch (\Throwable $e) {
                Log::error('EmbeddingSimilarityService: 文章篩選失敗', [
                    'article_id' => $article->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $processed;
    }

    /**
     * 篩選單一文章。
     *
     * 計算文章 embedding 與所有關鍵字 embedding 的最大 cosine similarity，
     * 依 Embedding_Threshold 更新文章狀態與 relevance_score。
     *
     * @param TrendingArticle $article 待篩選的文章
     * @param array<string, array<int, float>> $keywordEmbeddings 關鍵字 embedding 映射（keyword => vector）
     */
    public function filterArticle(TrendingArticle $article, array $keywordEmbeddings): void
    {
        $articleEmbedding = $this->getArticleEmbedding($article);

        if ($articleEmbedding === []) {
            Log::warning('EmbeddingSimilarityService: 文章 embedding 為空，跳過', [
                'article_id' => $article->id,
            ]);

            return;
        }

        $score = $this->maxSimilarity($articleEmbedding, $keywordEmbeddings);
        $threshold = (float) config('trending-summary.ai.embedding.threshold', 0.75);

        if ($score >= $threshold) {
            $article->update([
                'status' => 'candidate',
                'relevance_score' => $score,
            ]);
        } else {
            $article->update([
                'status' => 'rejected',
                'relevance_score' => $score,
            ]);
        }
    }

    /**
     * 取得文章的 embedding 向量（含快取）。
     *
     * 將文章的 title 與 content_body 合併為輸入文字，
     * 以文字的 SHA-256 雜湊作為快取鍵。快取命中時直接回傳，
     * 否則透過 EmbeddingInterface 計算後存入快取。
     *
     * @param TrendingArticle $article 文章模型
     * @return array<int, float> embedding 向量，失敗時回傳空陣列
     */
    public function getArticleEmbedding(TrendingArticle $article): array
    {
        $text = $this->buildArticleText($article);

        if (trim($text) === '') {
            return [];
        }

        $cacheKey = $this->buildCacheKey($text);
        $cacheTtl = (int) config('trending-summary.cache.embedding_ttl', 86400);

        /** @var array<int, float>|null $cached */
        $cached = Cache::get($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        try {
            /** @var array<int, float> $vector */
            $vector = $this->embedding->embed($text);

            if ($vector !== []) {
                Cache::put($cacheKey, $vector, $cacheTtl);
            }

            return $vector;
        } catch (\Throwable $e) {
            Log::error('EmbeddingSimilarityService: 文章 embedding 計算失敗', [
                'article_id' => $article->id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * 取得多個關鍵字的 embedding 向量（含快取）。
     *
     * 先檢查每個關鍵字是否已有快取，未快取的關鍵字透過
     * EmbeddingInterface::batchEmbed 批次計算後存入快取。
     *
     * @param array<int, string> $keywords 關鍵字陣列
     * @return array<string, array<int, float>> 關鍵字 embedding 映射（keyword => vector）
     */
    public function getKeywordEmbeddings(array $keywords): array
    {
        $cacheTtl = (int) config('trending-summary.cache.embedding_ttl', 86400);
        $result = [];
        $uncached = [];
        $uncachedIndices = [];

        foreach ($keywords as $index => $keyword) {
            $cacheKey = $this->buildCacheKey($keyword);

            /** @var array<int, float>|null $cached */
            $cached = Cache::get($cacheKey);

            if ($cached !== null) {
                $result[$keyword] = $cached;
            } else {
                $uncached[] = $keyword;
                $uncachedIndices[] = $index;
            }
        }

        if ($uncached !== []) {
            try {
                $vectors = $this->embedding->batchEmbed($uncached);

                foreach ($uncached as $i => $keyword) {
                    if (isset($vectors[$i]) && $vectors[$i] !== []) {
                        $result[$keyword] = $vectors[$i];
                        Cache::put($this->buildCacheKey($keyword), $vectors[$i], $cacheTtl);
                    }
                }
            } catch (\Throwable $e) {
                Log::error('EmbeddingSimilarityService: 關鍵字 batch embedding 計算失敗', [
                    'keywords_count' => count($uncached),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $result;
    }

    /**
     * 計算兩個向量的 cosine similarity。
     *
     * 公式：cos(θ) = dot(A, B) / (||A|| * ||B||)
     * 處理邊界情況：零向量、空向量、長度不一致。
     *
     * @param array<int, float> $vectorA 向量 A
     * @param array<int, float> $vectorB 向量 B
     * @return float cosine similarity 值，範圍 [-1, 1]；零向量回傳 0.0
     */
    public function cosineSimilarity(array $vectorA, array $vectorB): float
    {
        if ($vectorA === [] || $vectorB === []) {
            return 0.0;
        }

        $dimensions = min(count($vectorA), count($vectorB));

        $dotProduct = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        for ($i = 0; $i < $dimensions; $i++) {
            $a = $vectorA[$i];
            $b = $vectorB[$i];

            $dotProduct += $a * $b;
            $normA += $a * $a;
            $normB += $b * $b;
        }

        $normA = sqrt($normA);
        $normB = sqrt($normB);

        // 零向量處理
        if ($normA == 0.0 || $normB == 0.0) {
            return 0.0;
        }

        return $dotProduct / ($normA * $normB);
    }

    /**
     * 計算文章 embedding 與所有關鍵字 embedding 的最大 cosine similarity。
     *
     * 遍歷所有關鍵字向量，取最大的 cosine similarity 作為文章的相關性分數。
     *
     * @param array<int, float> $articleEmbedding 文章 embedding 向量
     * @param array<string, array<int, float>> $keywordEmbeddings 關鍵字 embedding 映射
     * @return float 最大 cosine similarity 值；無關鍵字時回傳 0.0
     */
    public function maxSimilarity(array $articleEmbedding, array $keywordEmbeddings): float
    {
        if ($keywordEmbeddings === [] || $articleEmbedding === []) {
            return 0.0;
        }

        $maxScore = -1.0;

        foreach ($keywordEmbeddings as $keywordVector) {
            $score = $this->cosineSimilarity($articleEmbedding, $keywordVector);

            if ($score > $maxScore) {
                $maxScore = $score;
            }
        }

        return $maxScore;
    }

    /**
     * 組合文章的 title 與 content_body 作為 embedding 輸入文字。
     *
     * @param TrendingArticle $article 文章模型
     * @return string 合併後的文字
     */
    private function buildArticleText(TrendingArticle $article): string
    {
        $title = trim((string) $article->title);
        $content = trim((string) $article->content_body);

        if ($title === '' && $content === '') {
            return '';
        }

        if ($title === '') {
            return $content;
        }

        if ($content === '') {
            return $title;
        }

        return $title . "\n\n" . $content;
    }

    /**
     * 建立 embedding 快取鍵。
     *
     * 使用 SHA-256 雜湊文字內容以產生固定長度的快取鍵。
     *
     * @param string $text 輸入文字
     * @return string 快取鍵
     */
    private function buildCacheKey(string $text): string
    {
        return self::CACHE_PREFIX . hash('sha256', $text);
    }
}
