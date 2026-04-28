import client from './client'
import type { ArticleTemplate, TemplateSettings, OutputField } from '@/types'

export interface CreateTemplatePayload {
  slug: string
  name: string
  description: string
  output_structure: OutputField[]
  settings: TemplateSettings
}

export type UpdateTemplatePayload = Partial<CreateTemplatePayload>

export function getTemplates() {
  return client.get<{ data: ArticleTemplate[] }>('/templates')
}

export function getTemplate(id: number) {
  return client.get<{ data: ArticleTemplate }>(`/templates/${id}`)
}

export function createTemplate(data: CreateTemplatePayload) {
  return client.post<{ data: ArticleTemplate }>('/templates', data)
}

export function updateTemplate(id: number, data: UpdateTemplatePayload) {
  return client.put<{ data: ArticleTemplate }>(`/templates/${id}`, data)
}

export function deleteTemplate(id: number) {
  return client.delete(`/templates/${id}`)
}
