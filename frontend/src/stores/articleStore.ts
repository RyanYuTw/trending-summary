import { ref, computed } from 'vue'
import { defineStore } from 'pinia'
import type { TrendingArticle, ArticleStatus } from '@/types'
import {
  getArticles,
  getArticle,
  updateArticle as apiUpdateArticle,
  batchAction as apiBatchAction,
  type ArticleListParams,
} from '@/api/articles'

export interface ArticleFilters {
  status: ArticleStatus | null
  source: string | null
  dateFrom: string | null
  dateTo: string | null
}

export const useArticleStore = defineStore('article', () => {
  // State
  const articles = ref<TrendingArticle[]>([])
  const currentArticle = ref<TrendingArticle | null>(null)
  const selectedIds = ref<number[]>([])
  const loading = ref(false)
  const loadingArticle = ref(false)
  const filters = ref<ArticleFilters>({
    status: null,
    source: null,
    dateFrom: null,
    dateTo: null,
  })
  const pagination = ref({
    currentPage: 1,
    lastPage: 1,
    perPage: 20,
    total: 0,
  })

  // Getters
  const hasSelection = computed(() => selectedIds.value.length > 0)
  const selectionCount = computed(() => selectedIds.value.length)

  // Actions
  async function fetchArticles(page = 1) {
    loading.value = true
    try {
      const params: ArticleListParams = { page, per_page: pagination.value.perPage }
      if (filters.value.status) params.status = filters.value.status
      if (filters.value.source) params.source = filters.value.source
      if (filters.value.dateFrom) params.date_from = filters.value.dateFrom
      if (filters.value.dateTo) params.date_to = filters.value.dateTo

      const { data: response } = await getArticles(params)
      articles.value = response.data
      pagination.value = {
        currentPage: response.meta.current_page,
        lastPage: response.meta.last_page,
        perPage: response.meta.per_page,
        total: response.meta.total,
      }
    } finally {
      loading.value = false
    }
  }

  async function fetchArticle(id: number) {
    loadingArticle.value = true
    try {
      const { data: response } = await getArticle(id)
      currentArticle.value = response.data
    } finally {
      loadingArticle.value = false
    }
  }

  async function updateArticle(id: number, data: Partial<TrendingArticle>) {
    const { data: response } = await apiUpdateArticle(id, data)
    currentArticle.value = response.data
    const index = articles.value.findIndex((a) => a.id === id)
    if (index !== -1) {
      articles.value[index] = response.data
    }
  }

  async function batchAction(action: 'approve' | 'reject' | 'skip') {
    if (selectedIds.value.length === 0) return
    loading.value = true
    try {
      await apiBatchAction(selectedIds.value, action)
      selectedIds.value = []
      await fetchArticles(pagination.value.currentPage)
    } finally {
      loading.value = false
    }
  }

  function toggleSelection(id: number) {
    const index = selectedIds.value.indexOf(id)
    if (index === -1) {
      selectedIds.value.push(id)
    } else {
      selectedIds.value.splice(index, 1)
    }
  }

  function clearSelection() {
    selectedIds.value = []
  }

  function setFilters(newFilters: Partial<ArticleFilters>) {
    Object.assign(filters.value, newFilters)
  }

  function resetFilters() {
    filters.value = { status: null, source: null, dateFrom: null, dateTo: null }
  }

  return {
    // State
    articles,
    currentArticle,
    selectedIds,
    loading,
    loadingArticle,
    filters,
    pagination,
    // Getters
    hasSelection,
    selectionCount,
    // Actions
    fetchArticles,
    fetchArticle,
    updateArticle,
    batchAction,
    toggleSelection,
    clearSelection,
    setFilters,
    resetFilters,
  }
})
