<script setup lang="ts">
import { ref, computed } from 'vue'
import type { GeneratedTitle, TitleStyle } from '@/types'

const props = defineProps<{
  titles: GeneratedTitle[]
  selectedTitle?: string | null
}>()

const emit = defineEmits<{
  (e: 'select', title: string): void
}>()

const editingId = ref<number | null>(null)
const editText = ref('')
const customTitle = ref('')
const showCustom = ref(false)

const styleLabels: Record<TitleStyle, string> = {
  news: '新聞風格',
  social: '社群風格',
  seo: 'SEO 風格',
}

const styleColors: Record<TitleStyle, string> = {
  news: 'bg-blue-50 text-blue-700 border-blue-200',
  social: 'bg-pink-50 text-pink-700 border-pink-200',
  seo: 'bg-green-50 text-green-700 border-green-200',
}

const groupedTitles = computed(() => {
  const groups: Record<TitleStyle, GeneratedTitle[]> = { news: [], social: [], seo: [] }
  for (const t of props.titles) {
    groups[t.style]?.push(t)
  }
  return groups
})

function startEdit(title: GeneratedTitle) {
  editingId.value = title.id
  editText.value = title.text
}

function confirmEdit() {
  if (editText.value.trim()) {
    emit('select', editText.value.trim())
  }
  editingId.value = null
}

function submitCustom() {
  if (customTitle.value.trim()) {
    emit('select', customTitle.value.trim())
    customTitle.value = ''
    showCustom.value = false
  }
}
</script>

<template>
  <div class="space-y-4">
    <div class="flex items-center justify-between">
      <h3 class="text-sm font-semibold text-gray-900">標題選擇</h3>
      <button
        class="text-sm text-indigo-600 hover:text-indigo-800"
        @click="showCustom = !showCustom"
      >
        {{ showCustom ? '取消自訂' : '自訂標題' }}
      </button>
    </div>

    <!-- Custom title input -->
    <div v-if="showCustom" class="flex gap-2">
      <input
        v-model="customTitle"
        type="text"
        placeholder="輸入自訂標題..."
        class="flex-1 rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
        @keyup.enter="submitCustom"
      />
      <button
        class="px-3 py-1.5 text-sm font-medium rounded-md bg-indigo-600 text-white hover:bg-indigo-700"
        @click="submitCustom"
      >
        確認
      </button>
    </div>

    <!-- Grouped titles -->
    <div v-for="(style, key) in groupedTitles" :key="key" class="space-y-2">
      <template v-if="style.length > 0">
        <span
          class="inline-flex px-2 py-0.5 rounded text-xs font-medium border"
          :class="styleColors[key as TitleStyle]"
        >
          {{ styleLabels[key as TitleStyle] }}
        </span>
        <div
          v-for="title in style"
          :key="title.id"
          class="flex items-start gap-2 p-2 rounded-md border cursor-pointer transition-colors"
          :class="selectedTitle === title.text ? 'border-indigo-500 bg-indigo-50' : 'border-gray-200 hover:border-gray-300'"
          @click="editingId !== title.id && emit('select', title.text)"
        >
          <div class="flex-1">
            <template v-if="editingId === title.id">
              <input
                v-model="editText"
                type="text"
                class="w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                @keyup.enter="confirmEdit"
                @keyup.escape="editingId = null"
                @click.stop
              />
              <div class="flex gap-1 mt-1">
                <button class="text-xs text-indigo-600 hover:text-indigo-800" @click.stop="confirmEdit">確認</button>
                <button class="text-xs text-gray-500 hover:text-gray-700" @click.stop="editingId = null">取消</button>
              </div>
            </template>
            <template v-else>
              <p class="text-sm text-gray-900">{{ title.text }}</p>
            </template>
          </div>
          <button
            v-if="editingId !== title.id"
            class="text-xs text-gray-400 hover:text-gray-600 shrink-0"
            @click.stop="startEdit(title)"
          >
            編輯
          </button>
        </div>
      </template>
    </div>

    <p v-if="titles.length === 0" class="text-sm text-gray-500">尚未產生標題候選</p>
  </div>
</template>
