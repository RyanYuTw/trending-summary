import client from './client'
import type { TrendKeyword } from '@/types'

export interface TrendStats {
  total_articles: number
  pending_review: number
  published_today: number
  published_total: number
  top_keywords: TrendKeyword[]
}

export function getKeywords() {
  return client.get<{ data: TrendKeyword[] }>('/trends/keywords')
}

export function getStats() {
  return client.get<{ data: TrendStats }>('/trends/stats')
}
