import { ref } from 'vue'
import { defineStore } from 'pinia'
import type { ArticleTemplate } from '@/types'
import {
  getTemplates,
  getTemplate,
  createTemplate as apiCreateTemplate,
  updateTemplate as apiUpdateTemplate,
  deleteTemplate as apiDeleteTemplate,
  type CreateTemplatePayload,
  type UpdateTemplatePayload,
} from '@/api/templates'

export const useTemplateStore = defineStore('template', () => {
  // State
  const templates = ref<ArticleTemplate[]>([])
  const currentTemplate = ref<ArticleTemplate | null>(null)
  const loading = ref(false)

  // Actions
  async function fetchTemplates() {
    loading.value = true
    try {
      const { data: response } = await getTemplates()
      templates.value = response.data
    } finally {
      loading.value = false
    }
  }

  async function fetchTemplate(id: number) {
    loading.value = true
    try {
      const { data: response } = await getTemplate(id)
      currentTemplate.value = response.data
    } finally {
      loading.value = false
    }
  }

  async function createTemplate(data: CreateTemplatePayload) {
    const { data: response } = await apiCreateTemplate(data)
    templates.value.push(response.data)
    return response.data
  }

  async function updateTemplate(id: number, data: UpdateTemplatePayload) {
    const { data: response } = await apiUpdateTemplate(id, data)
    currentTemplate.value = response.data
    const index = templates.value.findIndex((t) => t.id === id)
    if (index !== -1) {
      templates.value[index] = response.data
    }
    return response.data
  }

  async function deleteTemplate(id: number) {
    await apiDeleteTemplate(id)
    templates.value = templates.value.filter((t) => t.id !== id)
    if (currentTemplate.value?.id === id) {
      currentTemplate.value = null
    }
  }

  return {
    // State
    templates,
    currentTemplate,
    loading,
    // Actions
    fetchTemplates,
    fetchTemplate,
    createTemplate,
    updateTemplate,
    deleteTemplate,
  }
})
