<script setup lang="ts">
import { ref } from 'vue'
import type { ArticleSubtitle } from '@/types'

defineProps<{
  subtitle: ArticleSubtitle
  videoUrl?: string | null
}>()

type SubtitleMode = 'original' | 'translated' | 'bilingual'

const mode = ref<SubtitleMode>('translated')
const showSubtitles = ref(true)

const modeLabels: Record<SubtitleMode, string> = {
  original: '原文',
  translated: '譯文',
  bilingual: '雙語',
}
</script>

<template>
  <div class="space-y-3">
    <div class="flex items-center justify-between">
      <h3 class="text-sm font-semibold text-gray-900">字幕預覽</h3>
      <div class="flex items-center gap-3">
        <label class="flex items-center gap-1.5 text-sm text-gray-600">
          <input
            v-model="showSubtitles"
            type="checkbox"
            class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
          />
          顯示字幕
        </label>
        <select
          v-model="mode"
          class="rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
        >
          <option v-for="(label, key) in modeLabels" :key="key" :value="key">{{ label }}</option>
        </select>
      </div>
    </div>

    <!-- Video placeholder -->
    <div class="relative bg-black rounded-lg overflow-hidden aspect-video flex items-center justify-center">
      <div v-if="videoUrl" class="text-gray-400 text-sm">
        <!-- Placeholder for actual video embed -->
        <p>🎬 影片播放器</p>
        <p class="text-xs mt-1 text-gray-500">{{ videoUrl }}</p>
      </div>
      <div v-else class="text-gray-500 text-sm">
        <p>影片預覽區域</p>
      </div>

      <!-- Subtitle overlay -->
      <div
        v-if="showSubtitles && subtitle.original_cues.length > 0"
        class="absolute bottom-4 left-4 right-4 text-center"
      >
        <div class="inline-block bg-black/70 rounded px-3 py-1.5 max-w-full">
          <template v-if="mode === 'original' || mode === 'bilingual'">
            <p class="text-white text-sm">{{ subtitle.original_cues[0]?.text }}</p>
          </template>
          <template v-if="mode === 'translated' || mode === 'bilingual'">
            <p class="text-yellow-300 text-sm">
              {{ subtitle.translated_cues?.[0]?.text ?? '（尚未翻譯）' }}
            </p>
          </template>
        </div>
      </div>
    </div>

    <p class="text-xs text-gray-400">
      共 {{ subtitle.original_cues.length }} 段字幕
      <template v-if="subtitle.translated_cues">
        ，已翻譯 {{ subtitle.translated_cues.length }} 段
      </template>
    </p>
  </div>
</template>
