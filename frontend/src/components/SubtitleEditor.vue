<script setup lang="ts">
import { ref, computed } from 'vue'
import type { SubtitleCue, ArticleSubtitle } from '@/types'
import { translateSubtitle, updateSubtitle } from '@/api/subtitles'

const props = defineProps<{
  articleId: number
  subtitle: ArticleSubtitle
}>()

const emit = defineEmits<{
  (e: 'update', subtitle: ArticleSubtitle): void
}>()

const MAX_CHARS = 18
const editedCues = ref<SubtitleCue[]>(
  props.subtitle.translated_cues ? [...props.subtitle.translated_cues] : []
)
const translating = ref(false)
const saving = ref(false)

const hasEdits = computed(() => {
  const original = props.subtitle.translated_cues
  if (!original) return editedCues.value.length > 0
  return JSON.stringify(original) !== JSON.stringify(editedCues.value)
})

function charCount(text: string): number {
  return text.length
}

function isOverLimit(text: string): boolean {
  return text.split('\n').some((line) => charCount(line) > MAX_CHARS)
}

function updateCueText(index: number, text: string) {
  editedCues.value[index] = { ...editedCues.value[index], text }
}

async function batchTranslate() {
  translating.value = true
  try {
    const { data: response } = await translateSubtitle(props.articleId)
    editedCues.value = response.data.translated_cues ? [...response.data.translated_cues] : []
    emit('update', response.data)
  } finally {
    translating.value = false
  }
}

async function saveCues() {
  saving.value = true
  try {
    const { data: response } = await updateSubtitle(props.articleId, editedCues.value)
    emit('update', response.data)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="space-y-4">
    <div class="flex items-center justify-between">
      <h3 class="text-sm font-semibold text-gray-900">字幕編輯</h3>
      <div class="flex gap-2">
        <button
          :disabled="translating"
          class="px-3 py-1.5 text-sm font-medium rounded-md bg-indigo-600 text-white hover:bg-indigo-700 disabled:opacity-50"
          @click="batchTranslate"
        >
          {{ translating ? '翻譯中...' : '批次重譯' }}
        </button>
        <button
          v-if="hasEdits"
          :disabled="saving"
          class="px-3 py-1.5 text-sm font-medium rounded-md bg-green-600 text-white hover:bg-green-700 disabled:opacity-50"
          @click="saveCues"
        >
          {{ saving ? '儲存中...' : '儲存修改' }}
        </button>
      </div>
    </div>

    <div class="text-xs text-gray-500">
      狀態：{{ subtitle.translation_status }} ｜ 格式：{{ subtitle.source_format }} ｜ 原始語言：{{ subtitle.original_language }}
    </div>

    <!-- Cue list: original vs translated side by side -->
    <div class="border border-gray-200 rounded-lg overflow-hidden">
      <div class="grid grid-cols-2 bg-gray-50 border-b border-gray-200">
        <div class="px-3 py-2 text-xs font-medium text-gray-500 uppercase">原文</div>
        <div class="px-3 py-2 text-xs font-medium text-gray-500 uppercase">譯文</div>
      </div>
      <div class="max-h-96 overflow-y-auto divide-y divide-gray-100">
        <div
          v-for="(cue, idx) in subtitle.original_cues"
          :key="cue.index"
          class="grid grid-cols-2 gap-px"
        >
          <!-- Original cue -->
          <div class="px-3 py-2 bg-white">
            <div class="text-xs text-gray-400 mb-0.5">{{ cue.start_time }} → {{ cue.end_time }}</div>
            <p class="text-sm text-gray-700">{{ cue.text }}</p>
          </div>
          <!-- Translated cue -->
          <div class="px-3 py-2 bg-white">
            <textarea
              :value="editedCues[idx]?.text ?? ''"
              rows="2"
              class="w-full text-sm rounded border-gray-200 focus:border-indigo-500 focus:ring-indigo-500 resize-none"
              :class="{ 'border-red-300 bg-red-50': editedCues[idx] && isOverLimit(editedCues[idx].text) }"
              @input="updateCueText(idx, ($event.target as HTMLTextAreaElement).value)"
            />
            <div
              v-if="editedCues[idx]"
              class="text-xs mt-0.5"
              :class="isOverLimit(editedCues[idx].text) ? 'text-red-500' : 'text-gray-400'"
            >
              {{ charCount(editedCues[idx].text) }} 字
              <span v-if="isOverLimit(editedCues[idx].text)">（超過 {{ MAX_CHARS }} 字上限）</span>
            </div>
          </div>
        </div>
        <div v-if="subtitle.original_cues.length === 0" class="px-4 py-6 text-center text-sm text-gray-500 col-span-2">
          沒有字幕資料
        </div>
      </div>
    </div>
  </div>
</template>
