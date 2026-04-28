<script setup lang="ts">
import { onMounted } from 'vue'
import { useTrendStore } from '@/stores/trendStore'
import { useArticleStore } from '@/stores/articleStore'
import TrendDashboard from '@/components/TrendDashboard.vue'

const trendStore = useTrendStore()
const articleStore = useArticleStore()

onMounted(async () => {
  await Promise.all([
    trendStore.fetchKeywords(),
    trendStore.fetchStats(),
    articleStore.fetchArticles(1),
  ])
})
</script>

<template>
  <div class="max-w-7xl mx-auto px-4 py-6 space-y-6">
    <h1 class="text-2xl font-bold text-gray-900">Dashboard</h1>

    <!-- Stats cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
      <div class="bg-white rounded-lg shadow p-5">
        <p class="text-sm font-medium text-gray-500">待審文章</p>
        <p class="mt-1 text-3xl font-semibold text-indigo-600">
          {{ trendStore.stats?.pending_review ?? 0 }}
        </p>
      </div>
      <div class="bg-white rounded-lg shadow p-5">
        <p class="text-sm font-medium text-gray-500">今日已發佈</p>
        <p class="mt-1 text-3xl font-semibold text-green-600">
          {{ trendStore.stats?.published_today ?? 0 }}
        </p>
      </div>
      <div class="bg-white rounded-lg shadow p-5">
        <p class="text-sm font-medium text-gray-500">累計發佈</p>
        <p class="mt-1 text-3xl font-semibold text-gray-900">
          {{ trendStore.stats?.published_total ?? 0 }}
        </p>
      </div>
      <div class="bg-white rounded-lg shadow p-5">
        <p class="text-sm font-medium text-gray-500">文章總數</p>
        <p class="mt-1 text-3xl font-semibold text-gray-900">
          {{ trendStore.stats?.total_articles ?? 0 }}
        </p>
      </div>
    </div>

    <!-- Trend keywords -->
    <TrendDashboard :keywords="trendStore.keywords" :loading="trendStore.loading" />
  </div>
</template>
