<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useTemplateStore } from '@/stores/templateStore'
import type { ArticleTemplate, OutputField, TemplateSettings } from '@/types'
import TemplateEditor from '@/components/TemplateEditor.vue'

const store = useTemplateStore()

const showEditor = ref(false)
const editingTemplate = ref<ArticleTemplate | null>(null)
const deleteConfirmId = ref<number | null>(null)
const saving = ref(false)

onMounted(() => {
  store.fetchTemplates()
})

function openNew() {
  editingTemplate.value = null
  showEditor.value = true
}

function openEdit(template: ArticleTemplate) {
  editingTemplate.value = template
  showEditor.value = true
}

function closeEditor() {
  showEditor.value = false
  editingTemplate.value = null
}

async function handleSave(data: {
  slug: string
  name: string
  description: string
  output_structure: OutputField[]
  settings: TemplateSettings
}) {
  saving.value = true
  try {
    if (editingTemplate.value) {
      await store.updateTemplate(editingTemplate.value.id, data)
    } else {
      await store.createTemplate(data)
    }
    closeEditor()
  } finally {
    saving.value = false
  }
}

function confirmDelete(id: number) {
  deleteConfirmId.value = id
}

async function executeDelete() {
  if (deleteConfirmId.value === null) return
  await store.deleteTemplate(deleteConfirmId.value)
  deleteConfirmId.value = null
}
</script>

<template>
  <div class="max-w-7xl mx-auto px-4 py-6 space-y-6">
    <div class="flex items-center justify-between">
      <h1 class="text-2xl font-bold text-gray-900">模板管理</h1>
      <button
        v-if="!showEditor"
        type="button"
        class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-md hover:bg-indigo-700"
        @click="openNew"
      >
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
        </svg>
        新增模板
      </button>
    </div>

    <!-- Editor -->
    <TemplateEditor
      v-if="showEditor"
      :template="editingTemplate"
      @save="handleSave"
      @cancel="closeEditor"
    />

    <!-- Template list -->
    <div v-if="!showEditor" class="space-y-3">
      <div v-if="store.loading" class="text-center py-12 text-gray-500">載入中…</div>

      <div v-else-if="store.templates.length === 0" class="text-center py-12 text-gray-500">
        尚無模板，點擊「新增模板」建立第一個。
      </div>

      <div
        v-for="tpl in store.templates"
        :key="tpl.id"
        class="bg-white rounded-lg shadow p-4 flex items-center justify-between hover:shadow-md transition-shadow"
      >
        <div class="flex-1 min-w-0 cursor-pointer" @click="openEdit(tpl)">
          <div class="flex items-center gap-2">
            <h3 class="text-sm font-semibold text-gray-900 truncate">{{ tpl.name }}</h3>
            <span
              v-if="tpl.is_builtin"
              class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800"
            >
              內建
            </span>
          </div>
          <p class="text-xs text-gray-500 mt-0.5">{{ tpl.slug }}</p>
          <p class="text-sm text-gray-600 mt-1 truncate">{{ tpl.description }}</p>
        </div>

        <div class="flex items-center gap-2 ml-4 shrink-0">
          <button
            type="button"
            class="p-1.5 text-gray-400 hover:text-indigo-600 rounded"
            title="編輯"
            @click="openEdit(tpl)"
          >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
            </svg>
          </button>
          <button
            v-if="!tpl.is_builtin"
            type="button"
            class="p-1.5 text-gray-400 hover:text-red-600 rounded"
            title="刪除"
            @click="confirmDelete(tpl.id)"
          >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
            </svg>
          </button>
        </div>
      </div>
    </div>

    <!-- Delete confirmation modal -->
    <Teleport to="body">
      <div
        v-if="deleteConfirmId !== null"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/40"
        @click.self="deleteConfirmId = null"
      >
        <div class="bg-white rounded-lg shadow-xl p-6 max-w-sm w-full mx-4">
          <h3 class="text-lg font-semibold text-gray-900">確認刪除</h3>
          <p class="mt-2 text-sm text-gray-600">確定要刪除此模板嗎？此操作無法復原。</p>
          <div class="mt-4 flex justify-end gap-3">
            <button
              type="button"
              class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50"
              @click="deleteConfirmId = null"
            >
              取消
            </button>
            <button
              type="button"
              class="px-4 py-2 text-sm font-medium text-white bg-red-600 rounded-md hover:bg-red-700"
              @click="executeDelete"
            >
              刪除
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>
