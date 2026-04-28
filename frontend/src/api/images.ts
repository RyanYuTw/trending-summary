import client from './client'
import type { ImageSearchResult, ArticleImage } from '@/types'

export interface AttachImagePayload {
  url: string
  thumbnail_url?: string | null
  source_provider?: string | null
  caption?: string | null
  versions?: Record<string, unknown> | null
}

export function searchImages(query: string) {
  return client.get<{ data: ImageSearchResult[] }>('/images/search', { params: { query } })
}

export function attachImage(articleId: number, data: AttachImagePayload) {
  return client.post<{ data: ArticleImage }>(`/articles/${articleId}/image`, data)
}
