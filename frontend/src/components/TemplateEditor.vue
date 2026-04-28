<script setup lang="ts">
import { ref, reactive, computed, watch } from 'vue'
import type { ArticleTemplate, OutputField, TemplateSettings } from '@/types'

const props = defineProps<{
  template?: ArticleTemplate | null
}>()

const emit = defineEmits<{
  save: [data: { slug: string; name: string; description: string; output_structure: OutputField[]; settings: TemplateSettings }]
  cancel: []
}>()

const fieldTypes: OutputField['type'][] = ['text', 'paragraph', 'list', 'table', 'quote']

const form = reactive({
  slug: '',
  name: '',
  description: '',
  tone: 'professional',
  use_emoji: false,
  max_paragraphs: 6,
  max_total_chars: 3000,
})

const fields = ref<OutputField[]>([
  { key: 'summary', label: '摘要', type: 'paragraph', required: true },
])

const errors = ref<Record<string, string>>({})

// Populate form when editing existing template
watch(
  () => props.template,
  (tpl) => {
    if (tpl) {
      form.slug = tpl.slug
      form.name = tpl.name
      form.description = tpl.description
      form.tone = tpl.settings.tone
      form.use_emoji = tpl.settings.use_emoji
      form.max_paragraphs = tpl.settings.max_paragraphs
      form.max_total_chars = tpl.settings.max_total_chars
      fields.value = tpl.output_structure.map((f) => ({ ...f }))
    }
  },
  { immediate: true },
)

const isEditing = computed(() => !!props.template)

function addField() {
  fields.value.push({
    key: '',
    label: '',
    type: 'text',
    required: false,
  })
}

function removeField(index: number) {
  fields.value.splice(index, 1)
}

function moveField(index: number, direction: -1 | 1) {
  const target = index + direction
  if (target < 0 || target >= fields.value.length) return
  const temp = fields.value[index]
  fields.value[index] = fields.value[target]
  fields.value[target] = temp
}

function validate(): boolean {
  const e: Record<string, string> = {}
  if (!form.slug.trim()) e.slug = 'Slug 為必填'
  else if (!/^[a-z0-9]+(-[a-z0-9]+)*$/.test(form.slug)) e.slug = 'Slug 格式不正確（小寫英數 + 連字號）'
  if (!form.name.trim()) e.name = '名稱為必填'
  if (form.max_paragraphs < 1) e.max_paragraphs = '段落數至少為 1'
  if (form.max_total_chars < 100) e.max_total_chars = '字數上限至少 100'
  if (fields.value.length === 0) e.fields = '至少需要一個輸出欄位'
  fields.value.forEach((f, i) => {
    if (!f.key.trim()) e[`field_${i}_key`] = `欄位 ${i + 1} 的 key 為必填`
    if (!f.label.trim()) e[`field_${i}_label`] = `欄位 ${i + 1} 的 label 為必填`
  })
  errors.value = e
  return Object.keys(e).length === 0
}

function handleSave() {
  if (!validate()) return
  emit('save', {
    slug: form.slug.trim(),
    name: form.name.trim(),
    description: form.description.trim(),
    output_structure: fields.value.map((f) => ({ ...f })),
    settings: {
      tone: form.tone,
      use_emoji: form.use_emoji,
      max_paragraphs: form.max_paragraphs,
      max_total_chars: form.max_total_chars,
    },
  })
}
</script>

<template>
  <div class="bg-white rounded-lg shadow p-6 space-y-6">
    <h2 class="text-lg font-semibold text-gray-900">
      {{ isEditing ? '編輯模板' : '新增模板' }}
    </h2>

    <!-- Basic fields -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Slug</label>
        <input
          v-model="form.slug"
          type="text"
          :disabled="isEditing && template?.is_builtin"
          class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm disabled:bg-gray-100"
          placeholder="my-template"
        />
        <p v-if="errors.slug" class="mt-1 text-xs text-red-600">{{ errors.slug }}</p>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">名稱</label>
        <input
          v-model="form.name"
          type="text"
          class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
          placeholder="模板名稱"
        />
        <p v-if="errors.name" class="mt-1 text-xs text-red-600">{{ errors.name }}</p>
      </div>
    </div>

    <div>
      <label class="block text-sm font-medium text-gray-700 mb-1">描述</label>
      <textarea
        v-model="form.description"
        rows="2"
        class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
        placeholder="模板用途說明"
      />
    </div>

    <!-- Settings -->
    <div class="border-t pt-4">
      <h3 class="text-sm font-semibold text-gray-800 mb-3">模板設定</h3>
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">語氣 (Tone)</label>
          <select
            v-model="form.tone"
            class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
          >
            <option value="professional">專業</option>
            <option value="casual">輕鬆</option>
            <option value="formal">正式</option>
            <option value="friendly">親切</option>
          </select>
        </div>
        <div class="flex items-center gap-2 pt-5">
          <input
            v-model="form.use_emoji"
            type="checkbox"
            class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
          />
          <label class="text-sm text-gray-700">使用 Emoji</label>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">最大段落數</label>
          <input
            v-model.number="form.max_paragraphs"
            type="number"
            min="1"
            max="20"
            class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
          />
          <p v-if="errors.max_paragraphs" class="mt-1 text-xs text-red-600">{{ errors.max_paragraphs }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">最大字數</label>
          <input
            v-model.number="form.max_total_chars"
            type="number"
            min="100"
            max="50000"
            class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
          />
          <p v-if="errors.max_total_chars" class="mt-1 text-xs text-red-600">{{ errors.max_total_chars }}</p>
        </div>
      </div>
    </div>

    <!-- Output structure -->
    <div class="border-t pt-4">
      <div class="flex items-center justify-between mb-3">
        <h3 class="text-sm font-semibold text-gray-800">輸出結構</h3>
        <button
          type="button"
          class="inline-flex items-center gap-1 text-sm text-indigo-600 hover:text-indigo-800"
          @click="addField"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
          </svg>
          新增欄位
        </button>
      </div>
      <p v-if="errors.fields" class="mb-2 text-xs text-red-600">{{ errors.fields }}</p>

      <div class="space-y-3">
        <div
          v-for="(field, index) in fields"
          :key="index"
          class="flex items-start gap-2 p-3 bg-gray-50 rounded-md"
        >
          <div class="flex flex-col gap-1 pt-1">
            <button
              type="button"
              :disabled="index === 0"
              class="text-gray-400 hover:text-gray-600 disabled:opacity-30"
              @click="moveField(index, -1)"
            >
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7" />
              </svg>
            </button>
            <button
              type="button"
              :disabled="index === fields.length - 1"
              class="text-gray-400 hover:text-gray-600 disabled:opacity-30"
              @click="moveField(index, 1)"
            >
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
              </svg>
            </button>
          </div>

          <div class="flex-1 grid grid-cols-2 sm:grid-cols-4 gap-2">
            <div>
              <input
                v-model="field.key"
                type="text"
                placeholder="key"
                class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
              />
              <p v-if="errors[`field_${index}_key`]" class="mt-0.5 text-xs text-red-600">必填</p>
            </div>
            <div>
              <input
                v-model="field.label"
                type="text"
                placeholder="標籤"
                class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
              />
              <p v-if="errors[`field_${index}_label`]" class="mt-0.5 text-xs text-red-600">必填</p>
            </div>
            <div>
              <select
                v-model="field.type"
                class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
              >
                <option v-for="t in fieldTypes" :key="t" :value="t">{{ t }}</option>
              </select>
            </div>
            <div class="flex items-center gap-2">
              <input
                v-model="field.required"
                type="checkbox"
                class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
              />
              <span class="text-sm text-gray-600">必填</span>
            </div>
          </div>

          <button
            type="button"
            class="text-red-400 hover:text-red-600 pt-1"
            @click="removeField(index)"
          >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
            </svg>
          </button>
        </div>
      </div>
    </div>

    <!-- Actions -->
    <div class="flex justify-end gap-3 border-t pt-4">
      <button
        type="button"
        class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50"
        @click="emit('cancel')"
      >
        取消
      </button>
      <button
        type="button"
        class="px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-md hover:bg-indigo-700"
        @click="handleSave"
      >
        儲存
      </button>
    </div>
  </div>
</template>
