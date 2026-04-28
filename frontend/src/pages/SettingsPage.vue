<script setup lang="ts">
import { ref, onMounted, watch } from 'vue'
import { getSettings, updateSettings, type SystemSettings } from '@/api/settings'

const settings = ref<SystemSettings | null>(null)
const loading = ref(true)
const saving = ref(false)
const saveMessage = ref('')

// Editable fields
const mode = ref<'review' | 'auto'>('review')
const threshold = ref(80)

onMounted(async () => {
  try {
    const { data: response } = await getSettings()
    settings.value = response.data
    mode.value = response.data.mode
    threshold.value = response.data.auto_publish_threshold
  } finally {
    loading.value = false
  }
})

async function saveMode() {
  saving.value = true
  saveMessage.value = ''
  try {
    await updateSettings({
      mode: mode.value,
      auto_publish_threshold: threshold.value,
    })
    saveMessage.value = '設定已儲存'
    setTimeout(() => (saveMessage.value = ''), 3000)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="max-w-4xl mx-auto px-4 py-6 space-y-6">
    <h1 class="text-2xl font-bold text-gray-900">系統設定</h1>

    <div v-if="loading" class="text-center py-12 text-gray-500">載入中…</div>

    <template v-else-if="settings">
      <!-- Operation Mode -->
      <section class="bg-white rounded-lg shadow p-5 space-y-4">
        <h2 class="text-lg font-semibold text-gray-900">運作模式</h2>
        <div class="flex items-center gap-4">
          <label class="inline-flex items-center gap-2 cursor-pointer">
            <input
              v-model="mode"
              type="radio"
              value="review"
              class="text-indigo-600 focus:ring-indigo-500"
            />
            <span class="text-sm text-gray-700">審核模式 (Review)</span>
          </label>
          <label class="inline-flex items-center gap-2 cursor-pointer">
            <input
              v-model="mode"
              type="radio"
              value="auto"
              class="text-indigo-600 focus:ring-indigo-500"
            />
            <span class="text-sm text-gray-700">自動模式 (Auto)</span>
          </label>
        </div>

        <div v-if="mode === 'auto'" class="space-y-2">
          <label class="block text-sm font-medium text-gray-700">
            自動發佈門檻：{{ threshold }}
          </label>
          <input
            v-model.number="threshold"
            type="range"
            min="0"
            max="100"
            step="5"
            class="w-full h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer accent-indigo-600"
          />
          <div class="flex justify-between text-xs text-gray-400">
            <span>0</span>
            <span>50</span>
            <span>100</span>
          </div>
          <p class="text-xs text-gray-500">品質分數 ≥ {{ threshold }} 的文章將自動發佈</p>
        </div>

        <div class="flex items-center gap-3">
          <button
            type="button"
            :disabled="saving"
            class="px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-md hover:bg-indigo-700 disabled:opacity-50"
            @click="saveMode"
          >
            {{ saving ? '儲存中…' : '儲存' }}
          </button>
          <span v-if="saveMessage" class="text-sm text-green-600">{{ saveMessage }}</span>
        </div>
      </section>

      <!-- RSS Sources -->
      <section class="bg-white rounded-lg shadow p-5 space-y-3">
        <h2 class="text-lg font-semibold text-gray-900">RSS 來源</h2>
        <p class="text-xs text-gray-500">來源設定於後端 config，此處僅供檢視。</p>
        <div class="divide-y">
          <div
            v-for="(source, i) in settings.feeds.sources"
            :key="i"
            class="py-2 flex items-center justify-between"
          >
            <div>
              <p class="text-sm font-medium text-gray-800">{{ source.name }}</p>
              <p class="text-xs text-gray-500 truncate max-w-md">{{ source.url }}</p>
            </div>
            <span
              class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium"
              :class="source.content_type === 'video' ? 'bg-purple-100 text-purple-800' : 'bg-gray-100 text-gray-800'"
            >
              {{ source.content_type }}
            </span>
          </div>
        </div>
      </section>

      <!-- AI Models -->
      <section class="bg-white rounded-lg shadow p-5 space-y-3">
        <h2 class="text-lg font-semibold text-gray-900">AI 模型</h2>
        <p class="text-xs text-gray-500">模型設定於後端 config，此處僅供檢視。</p>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
          <div class="p-3 bg-gray-50 rounded-md">
            <p class="text-xs font-medium text-gray-500 uppercase">Embedding</p>
            <p class="text-sm font-semibold text-gray-900 mt-1">{{ settings.ai.embedding.model }}</p>
            <p class="text-xs text-gray-500">Driver: {{ settings.ai.embedding.driver }}</p>
            <p class="text-xs text-gray-500">Threshold: {{ settings.ai.embedding.threshold }}</p>
          </div>
          <div class="p-3 bg-gray-50 rounded-md">
            <p class="text-xs font-medium text-gray-500 uppercase">LLM</p>
            <p class="text-sm font-semibold text-gray-900 mt-1">{{ settings.ai.llm.model }}</p>
            <p class="text-xs text-gray-500">Driver: {{ settings.ai.llm.driver }}</p>
          </div>
          <div class="p-3 bg-gray-50 rounded-md">
            <p class="text-xs font-medium text-gray-500 uppercase">Translation</p>
            <p class="text-sm font-semibold text-gray-900 mt-1">{{ settings.ai.translation.model }}</p>
            <p class="text-xs text-gray-500">Driver: {{ settings.ai.translation.driver }}</p>
          </div>
        </div>
      </section>

      <!-- Image Sources -->
      <section class="bg-white rounded-lg shadow p-5 space-y-3">
        <h2 class="text-lg font-semibold text-gray-900">圖片來源</h2>
        <div class="space-y-2">
          <div>
            <p class="text-sm font-medium text-gray-700">CNA 域名</p>
            <div class="flex flex-wrap gap-1.5 mt-1">
              <span
                v-for="domain in settings.images.cna_domains"
                :key="domain"
                class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-700"
              >
                {{ domain }}
              </span>
            </div>
          </div>
          <div class="flex items-center gap-2">
            <span class="text-sm text-gray-700">InkMagine 圖庫</span>
            <span
              class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium"
              :class="settings.images.inkmagine_enabled ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-500'"
            >
              {{ settings.images.inkmagine_enabled ? '已啟用' : '未啟用' }}
            </span>
          </div>
        </div>
      </section>

      <!-- Schedule -->
      <section class="bg-white rounded-lg shadow p-5 space-y-3">
        <h2 class="text-lg font-semibold text-gray-900">排程</h2>
        <div class="flex items-center gap-4">
          <div>
            <p class="text-sm text-gray-700">
              頻率：<span class="font-medium">{{ settings.schedule.frequency }}</span>
            </p>
          </div>
          <span
            class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium"
            :class="settings.schedule.enabled ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-500'"
          >
            {{ settings.schedule.enabled ? '已啟用' : '未啟用' }}
          </span>
        </div>
      </section>

      <!-- Publishing -->
      <section class="bg-white rounded-lg shadow p-5 space-y-3">
        <h2 class="text-lg font-semibold text-gray-900">發佈設定</h2>
        <div class="space-y-2">
          <div>
            <p class="text-sm text-gray-700">
              預設目標：
              <span class="font-medium">{{ settings.publishing.default_targets.join(', ') }}</span>
            </p>
          </div>
          <div>
            <p class="text-sm text-gray-700">
              預設狀態：
              <span class="font-medium">{{ settings.publishing.default_status }}</span>
            </p>
          </div>
          <div class="flex items-center gap-4">
            <span class="text-sm text-gray-700 flex items-center gap-1.5">
              InkMagine
              <span
                class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium"
                :class="settings.publishing.inkmagine_enabled ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-500'"
              >
                {{ settings.publishing.inkmagine_enabled ? '已啟用' : '未啟用' }}
              </span>
            </span>
            <span class="text-sm text-gray-700 flex items-center gap-1.5">
              WordPress
              <span
                class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium"
                :class="settings.publishing.wordpress_enabled ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-500'"
              >
                {{ settings.publishing.wordpress_enabled ? '已啟用' : '未啟用' }}
              </span>
            </span>
          </div>
        </div>
      </section>
    </template>
  </div>
</template>
