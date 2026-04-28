import { ref } from 'vue'
import { defineStore } from 'pinia'
import type { TrendKeyword } from '@/types'
import { getKeywords, getStats, type TrendStats } from '@/api/trends'

export const useTrendStore = defineStore('trend', () => {
  // State
  const keywords = ref<TrendKeyword[]>([])
  const stats = ref<TrendStats | null>(null)
  const loading = ref(false)

  // Actions
  async function fetchKeywords() {
    loading.value = true
    try {
      const { data: response } = await getKeywords()
      keywords.value = response.data
    } finally {
      loading.value = false
    }
  }

  async function fetchStats() {
    loading.value = true
    try {
      const { data: response } = await getStats()
      stats.value = response.data
    } finally {
      loading.value = false
    }
  }

  return {
    // State
    keywords,
    stats,
    loading,
    // Actions
    fetchKeywords,
    fetchStats,
  }
})
