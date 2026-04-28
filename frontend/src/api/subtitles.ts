import client from './client'
import type { ArticleSubtitle, SubtitleCue } from '@/types'

export function getSubtitle(articleId: number) {
  return client.get<{ data: ArticleSubtitle }>(`/articles/${articleId}/subtitle`)
}

export function translateSubtitle(articleId: number) {
  return client.post<{ data: ArticleSubtitle }>(`/articles/${articleId}/subtitle/translate`)
}

export function updateSubtitle(articleId: number, cues: SubtitleCue[]) {
  return client.put<{ data: ArticleSubtitle }>(`/articles/${articleId}/subtitle`, { translated_cues: cues })
}

export function downloadSubtitle(articleId: number, format: 'srt' | 'vtt') {
  return client.get(`/articles/${articleId}/subtitle/download`, {
    params: { format },
    responseType: 'blob',
  })
}
