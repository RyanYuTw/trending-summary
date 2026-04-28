import client from './client'
import type { ArticleSeo } from '@/types'

export function getSeo(articleId: number) {
  return client.get<{ data: ArticleSeo }>(`/articles/${articleId}/seo`)
}

export function updateSeo(articleId: number, data: Partial<ArticleSeo>) {
  return client.put<{ data: ArticleSeo }>(`/articles/${articleId}/seo`, data)
}

export function regenerateSeo(articleId: number) {
  return client.post<{ data: ArticleSeo }>(`/articles/${articleId}/seo/regenerate`)
}
