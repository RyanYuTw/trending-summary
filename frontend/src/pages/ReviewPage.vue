<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useArticleStore } from '@/stores/articleStore'
import type { TrendingArticle } from '@/types'
import ArticleList from '@/components/ArticleList.vue'
import ArticleReview from '@/components/ArticleReview.vue'

const articleStore = useArticleStore()
const selectedArticle = ref<TrendingArticle | null>(null)

function onSelectArticle(article: TrendingArticle) {
  articleStore.fetchArticle(article.id).then(() => {
    selectedArticle.value = articleStore.currentArticle
  })
}

async function onReviewAction(action: 'approve' | 'reject' | 'skip') {
  if (!selectedArticle.value) return
  await articleStore.updateArticle(selectedArticle.value.id, {
    status: action === 'approve' ? 'approved' : action === 'reject' ? 'rejected' : 'reviewing',
  })
  // Refresh list and move to next
  await articleStore.fetchArticles(articleStore.pagination.currentPage)
  selectedArticle.value = null
}

function goBackToList() {
  selectedArticle.value = null
}

onMounted(() => {
  articleStore.setFilters({ status: 'reviewing' })
  articleStore.fetchArticles(1)
})
</script>

<template>
  <div class="max-w-7xl mx-auto px-4 py-6">
    <h1 class="text-2xl font-bold text-gray-900 mb-6">文章審核</h1>

    <!-- Desktop: side-by-side layout -->
    <div class="hidden lg:grid lg:grid-cols-5 lg:gap-6">
      <!-- Article list (left) -->
      <div class="col-span-2">
        <ArticleList @select="onSelectArticle" />
      </div>

      <!-- Review panel (right) -->
      <div class="col-span-3">
        <div v-if="articleStore.loadingArticle" class="flex items-center justify-center py-12">
          <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600" />
        </div>
        <div v-else-if="selectedArticle" class="bg-gray-50 rounded-lg p-4">
          <ArticleReview
            :article="selectedArticle"
            @action="onReviewAction"
          />
        </div>
        <div v-else class="flex items-center justify-center py-24 text-gray-400 text-sm">
          選擇一篇文章開始審核
        </div>
      </div>
    </div>

    <!-- Mobile: stacked layout -->
    <div class="lg:hidden">
      <template v-if="selectedArticle">
        <button
          class="mb-4 text-sm text-indigo-600 hover:text-indigo-800"
          @click="goBackToList"
        >
          ← 返回列表
        </button>
        <div v-if="articleStore.loadingArticle" class="flex items-center justify-center py-12">
          <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600" />
        </div>
        <ArticleReview
          v-else
          :article="selectedArticle"
          @action="onReviewAction"
        />
      </template>
      <template v-else>
        <ArticleList @select="onSelectArticle" />
      </template>
    </div>
  </div>
</template>
