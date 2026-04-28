<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useArticleStore, type ArticleFilters } from '@/stores/articleStore'
import type { TrendingArticle, ArticleStatus } from '@/types'
import BatchActions from '@/components/BatchActions.vue'

const emit = defineEmits<{
  (e: 'select', article: TrendingArticle): void
}>()

const articleStore = useArticleStore()

const sortBy = ref<'relevance_score' | 'created_at'>('created_at')
const sortOrder = ref<'asc' | 'desc'>('desc')

const statusOptions: { label: string; value: ArticleStatus | '' }[] = [
  { label: '全部狀態', value: '' },
  { label: '待處理', value: 'pending' },
  { label: '候選', value: 'candidate' },
  { label: '已篩選', value: 'filtered' },
  { label: '已生成', value: 'generated' },
  { label: '審核中', value: 'reviewing' },
  { label: '已通過', value: 'approved' },
  { label: '已退回', value: 'rejected' },
  { label: '已發佈', value: 'published' },
  { label: '失敗', value: 'failed' },
]

function statusBadgeClass(status: ArticleStatus): string {
  const map: Record<string, string> = {
    pending: 'bg-gray-100 text-gray-700',
    candidate: 'bg-blue-100 text-blue-700',
    filtered: 'bg-cyan-100 text-cyan-700',
    generated: 'bg-purple-100 text-purple-700',
    reviewing: 'bg-yellow-100 text-yellow-700',
    approved: 'bg-green-100 text-green-700',
    rejected: 'bg-red-100 text-red-700',
    published: 'bg-emerald-100 text-emerald-700',
    failed: 'bg-red-200 text-red-800',
    scheduled: 'bg-indigo-100 text-indigo-700',
  }
  return map[status] ?? 'bg-gray-100 text-gray-700'
}

function onFilterChange(key: keyof ArticleFilters, value: string) {
  articleStore.setFilters({ [key]: value || null })
  articleStore.fetchArticles(1)
}

function onSort(field: 'relevance_score' | 'created_at') {
  if (sortBy.value === field) {
    sortOrder.value = sortOrder.value === 'asc' ? 'desc' : 'asc'
  } else {
    sortBy.value = field
    sortOrder.value = 'desc'
  }
  articleStore.fetchArticles(1)
}

function goToPage(page: number) {
  articleStore.fetchArticles(page)
}

function isSelected(id: number): boolean {
  return articleStore.selectedIds.includes(id)
}

onMounted(() => {
  articleStore.fetchArticles(1)
})
</script>

<template>
  <div class="space-y-4">
    <!-- Filters -->
    <div class="flex flex-wrap gap-3 items-end">
      <div>
        <label class="block text-xs font-medium text-gray-500 mb-1">狀態</label>
        <select
          class="rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
          :value="articleStore.filters.status ?? ''"
          @change="onFilterChange('status', ($event.target as HTMLSelectElement).value)"
        >
          <option v-for="opt in statusOptions" :key="opt.value" :value="opt.value">
            {{ opt.label }}
          </option>
        </select>
      </div>
      <div>
        <label class="block text-xs font-medium text-gray-500 mb-1">來源</label>
        <input
          type="text"
          placeholder="來源名稱"
          class="rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
          :value="articleStore.filters.source ?? ''"
          @change="onFilterChange('source', ($event.target as HTMLInputElement).value)"
        />
      </div>
      <div>
        <label class="block text-xs font-medium text-gray-500 mb-1">起始日期</label>
        <input
          type="date"
          class="rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
          :value="articleStore.filters.dateFrom ?? ''"
          @change="onFilterChange('dateFrom', ($event.target as HTMLInputElement).value)"
        />
      </div>
      <div>
        <label class="block text-xs font-medium text-gray-500 mb-1">結束日期</label>
        <input
          type="date"
          class="rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
          :value="articleStore.filters.dateTo ?? ''"
          @change="onFilterChange('dateTo', ($event.target as HTMLInputElement).value)"
        />
      </div>
      <button
        class="px-3 py-1.5 text-sm text-gray-600 hover:text-gray-800"
        @click="articleStore.resetFilters(); articleStore.fetchArticles(1)"
      >
        重設
      </button>
    </div>

    <!-- Batch actions -->
    <BatchActions
      :selection-count="articleStore.selectionCount"
      :loading="articleStore.loading"
      @approve="articleStore.batchAction('approve')"
      @reject="articleStore.batchAction('reject')"
      @skip="articleStore.batchAction('skip')"
      @clear="articleStore.clearSelection()"
    />

    <!-- Table -->
    <div class="bg-white shadow rounded-lg overflow-hidden">
      <div v-if="articleStore.loading" class="flex items-center justify-center py-12">
        <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600" />
      </div>

      <table v-else class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
          <tr>
            <th class="w-10 px-4 py-3">
              <input
                type="checkbox"
                class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                @change="
                  ($event.target as HTMLInputElement).checked
                    ? articleStore.articles.forEach((a) => { if (!isSelected(a.id)) articleStore.toggleSelection(a.id) })
                    : articleStore.clearSelection()
                "
              />
            </th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">標題</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">來源</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">狀態</th>
            <th
              class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase cursor-pointer hover:text-gray-700"
              @click="onSort('relevance_score')"
            >
              相關性 {{ sortBy === 'relevance_score' ? (sortOrder === 'asc' ? '↑' : '↓') : '' }}
            </th>
            <th
              class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase cursor-pointer hover:text-gray-700"
              @click="onSort('created_at')"
            >
              建立時間 {{ sortBy === 'created_at' ? (sortOrder === 'asc' ? '↑' : '↓') : '' }}
            </th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-200">
          <tr
            v-for="article in articleStore.articles"
            :key="article.id"
            class="hover:bg-gray-50 cursor-pointer"
            @click="emit('select', article)"
          >
            <td class="px-4 py-3" @click.stop>
              <input
                type="checkbox"
                class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                :checked="isSelected(article.id)"
                @change="articleStore.toggleSelection(article.id)"
              />
            </td>
            <td class="px-4 py-3">
              <div class="text-sm font-medium text-gray-900 line-clamp-1">{{ article.title }}</div>
              <div v-if="article.content_type === 'video'" class="text-xs text-purple-600 mt-0.5">🎬 影片</div>
            </td>
            <td class="px-4 py-3 text-sm text-gray-500">{{ article.source_name }}</td>
            <td class="px-4 py-3">
              <span
                class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium"
                :class="statusBadgeClass(article.status)"
              >
                {{ article.status }}
              </span>
            </td>
            <td class="px-4 py-3 text-sm text-gray-500">
              {{ article.relevance_score.toFixed(2) }}
            </td>
            <td class="px-4 py-3 text-sm text-gray-500">
              {{ new Date(article.created_at).toLocaleDateString() }}
            </td>
          </tr>
          <tr v-if="articleStore.articles.length === 0">
            <td colspan="6" class="px-4 py-8 text-center text-sm text-gray-500">
              沒有符合條件的文章
            </td>
          </tr>
        </tbody>
      </table>

      <!-- Pagination -->
      <div
        v-if="articleStore.pagination.lastPage > 1"
        class="flex items-center justify-between px-4 py-3 bg-gray-50 border-t border-gray-200"
      >
        <span class="text-sm text-gray-500">
          共 {{ articleStore.pagination.total }} 篇，第 {{ articleStore.pagination.currentPage }} / {{ articleStore.pagination.lastPage }} 頁
        </span>
        <div class="flex gap-1">
          <button
            :disabled="articleStore.pagination.currentPage <= 1"
            class="px-3 py-1 text-sm rounded-md border border-gray-300 hover:bg-gray-100 disabled:opacity-50"
            @click="goToPage(articleStore.pagination.currentPage - 1)"
          >
            上一頁
          </button>
          <button
            :disabled="articleStore.pagination.currentPage >= articleStore.pagination.lastPage"
            class="px-3 py-1 text-sm rounded-md border border-gray-300 hover:bg-gray-100 disabled:opacity-50"
            @click="goToPage(articleStore.pagination.currentPage + 1)"
          >
            下一頁
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
