<script setup lang="ts">
import { ref } from 'vue'
import type { ArticleImage, ImageSearchResult } from '@/types'
import { searchImages, attachImage } from '@/api/images'

const props = defineProps<{
  articleId: number
  image?: ArticleImage | null
}>()

const emit = defineEmits<{
  (e: 'update', image: ArticleImage): void
}>()

const searchQuery = ref('')
const searchResults = ref<ImageSearchResult[]>([])
const searching = ref(false)
const manualUrl = ref('')
const showManual = ref(false)

async function doSearch() {
  if (!searchQuery.value.trim()) return
  searching.value = true
  try {
    const { data: response } = await searchImages(searchQuery.value.trim())
    searchResults.value = response.data
  } finally {
    searching.value = false
  }
}

async function selectSearchResult(result: ImageSearchResult) {
  const { data: response } = await attachImage(props.articleId, {
    url: result.url,
    thumbnail_url: result.thumbnail_url,
    source_provider: result.source_provider,
    caption: result.caption,
    versions: result.versions,
  })
  emit('update', response.data)
  searchResults.value = []
  searchQuery.value = ''
}

async function submitManualUrl() {
  if (!manualUrl.value.trim()) return
  const { data: response } = await attachImage(props.articleId, {
    url: manualUrl.value.trim(),
    source_provider: 'manual',
  })
  emit('update', response.data)
  manualUrl.value = ''
  showManual.value = false
}
</script>

<template>
  <div class="space-y-4">
    <h3 class="text-sm font-semibold text-gray-900">配圖選擇</h3>

    <!-- Current image -->
    <div v-if="image" class="space-y-2">
      <div class="relative rounded-lg overflow-hidden border border-gray-200">
        <img
          :src="image.thumbnail_url || image.url"
          :alt="image.caption || '文章配圖'"
          class="w-full h-48 object-cover"
        />
        <div class="absolute bottom-0 left-0 right-0 bg-black/50 px-3 py-1.5">
          <p class="text-xs text-white truncate">{{ image.caption || image.source_provider || '配圖' }}</p>
        </div>
      </div>
      <div v-if="image.needs_manual" class="flex items-center gap-1.5 text-amber-600 text-sm">
        <span>⚠️</span>
        <span>需要手動補圖</span>
      </div>
    </div>
    <div v-else class="text-sm text-gray-500">尚未設定配圖</div>

    <!-- Search InkMagine -->
    <div class="space-y-2">
      <label class="block text-xs font-medium text-gray-500">搜尋 InkMagine 圖庫</label>
      <div class="flex gap-2">
        <input
          v-model="searchQuery"
          type="text"
          placeholder="輸入關鍵字搜尋..."
          class="flex-1 rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
          @keyup.enter="doSearch"
        />
        <button
          :disabled="searching"
          class="px-3 py-1.5 text-sm font-medium rounded-md bg-indigo-600 text-white hover:bg-indigo-700 disabled:opacity-50"
          @click="doSearch"
        >
          {{ searching ? '搜尋中...' : '搜尋' }}
        </button>
      </div>

      <!-- Search results -->
      <div v-if="searchResults.length > 0" class="grid grid-cols-3 gap-2">
        <div
          v-for="result in searchResults"
          :key="result.id"
          class="cursor-pointer rounded-md overflow-hidden border border-gray-200 hover:border-indigo-400 transition-colors"
          @click="selectSearchResult(result)"
        >
          <img
            :src="result.thumbnail_url || result.url"
            :alt="result.caption || '搜尋結果'"
            class="w-full h-20 object-cover"
          />
        </div>
      </div>
    </div>

    <!-- Manual URL -->
    <div>
      <button
        class="text-sm text-indigo-600 hover:text-indigo-800"
        @click="showManual = !showManual"
      >
        {{ showManual ? '取消' : '手動輸入圖片 URL' }}
      </button>
      <div v-if="showManual" class="flex gap-2 mt-2">
        <input
          v-model="manualUrl"
          type="url"
          placeholder="https://..."
          class="flex-1 rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
          @keyup.enter="submitManualUrl"
        />
        <button
          class="px-3 py-1.5 text-sm font-medium rounded-md bg-indigo-600 text-white hover:bg-indigo-700"
          @click="submitManualUrl"
        >
          確認
        </button>
      </div>
    </div>
  </div>
</template>
