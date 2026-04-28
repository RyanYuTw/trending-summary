import client from './client'
import type { TrendingArticle, PublishRecord, PublishPlatform, PublishStatus } from '@/types'

export interface ArticleListParams {
  status?: string
  source?: string
  date_from?: string
  date_to?: string
  sort_by?: 'relevance_score' | 'created_at'
  sort_order?: 'asc' | 'desc'
  page?: number
  per_page?: number
}

export interface ArticleListResponse {
  data: TrendingArticle[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}

export interface PublishPayload {
  platforms: PublishPlatform[]
  status: PublishStatus
}

export function getArticles(params: ArticleListParams = {}) {
  return client.get<ArticleListResponse>('/articles', { params })
}

export function getArticle(id: number) {
  return client.get<{ data: TrendingArticle }>(`/articles/${id}`)
}

export function updateArticle(id: number, data: Partial<TrendingArticle>) {
  return client.put<{ data: TrendingArticle }>(`/articles/${id}`, data)
}

export function regenerateArticle(id: number) {
  return client.post<{ data: TrendingArticle }>(`/articles/${id}/regenerate`)
}

export function publishArticle(id: number, payload: PublishPayload) {
  return client.post<{ data: PublishRecord[] }>(`/articles/${id}/publish`, payload)
}

export function getPublishStatus(id: number) {
  return client.get<{ data: PublishRecord[] }>(`/articles/${id}/publish-status`)
}

export function batchAction(ids: number[], action: 'approve' | 'reject' | 'skip') {
  return client.post<{ data: TrendingArticle[] }>('/articles/batch', { ids, action })
}
