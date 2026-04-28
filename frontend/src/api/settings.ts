import client from './client'

export interface SystemSettings {
  mode: 'review' | 'auto'
  auto_publish_threshold: number
  schedule: {
    enabled: boolean
    frequency: string
  }
  feeds: {
    sources: Array<{
      name: string
      url: string
      content_type: string
    }>
  }
  ai: {
    embedding: { driver: string; model: string; threshold: number }
    llm: { driver: string; model: string }
    translation: { driver: string; model: string }
  }
  images: {
    cna_domains: string[]
    inkmagine_enabled: boolean
  }
  publishing: {
    default_targets: string[]
    default_status: string
    inkmagine_enabled: boolean
    wordpress_enabled: boolean
  }
  subtitle: {
    enabled: boolean
    supported_formats: string[]
    translation: {
      max_chars_per_line: number
      batch_size: number
      context_window: number
    }
    output_formats: string[]
  }
  seo: {
    enabled: boolean
    site_name: string
    aio_enabled: boolean
  }
}

export function getSettings() {
  return client.get<{ data: SystemSettings }>('/settings')
}

export function updateSettings(data: { mode?: string; auto_publish_threshold?: number }) {
  return client.put<{ message: string; updated: Record<string, unknown> }>('/settings', data)
}
