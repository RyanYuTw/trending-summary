<script setup lang="ts">
import { computed } from 'vue'
import type { TrendKeyword } from '@/types'

const props = defineProps<{
  keywords: TrendKeyword[]
  loading?: boolean
}>()

const maxVolume = computed(() =>
  props.keywords.length ? Math.max(...props.keywords.map((k) => k.traffic_volume)) : 1
)

function volumePercent(volume: number): number {
  return Math.round((volume / maxVolume.value) * 100)
}
</script>

<template>
  <div class="bg-white rounded-lg shadow p-6">
    <h2 class="text-lg font-semibold text-gray-900 mb-4">趨勢關鍵字</h2>

    <div v-if="loading" class="flex items-center justify-center py-8">
      <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600" />
    </div>

    <div v-else-if="keywords.length === 0" class="text-gray-500 text-sm py-4 text-center">
      目前沒有趨勢關鍵字
    </div>

    <div v-else class="flex flex-wrap gap-2">
      <div
        v-for="kw in keywords"
        :key="kw.id"
        class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full text-sm font-medium bg-indigo-50 text-indigo-700 border border-indigo-200"
      >
        <span>{{ kw.keyword }}</span>
        <span
          class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-semibold"
          :class="{
            'bg-red-100 text-red-700': volumePercent(kw.traffic_volume) >= 80,
            'bg-yellow-100 text-yellow-700': volumePercent(kw.traffic_volume) >= 50 && volumePercent(kw.traffic_volume) < 80,
            'bg-gray-100 text-gray-600': volumePercent(kw.traffic_volume) < 50,
          }"
        >
          {{ kw.traffic_volume.toLocaleString() }}
        </span>
      </div>
    </div>
  </div>
</template>
