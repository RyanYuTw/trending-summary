export type SubtitleSourceFormat = 'srt' | 'vtt' | 'manual'
export type SubtitleTranslationStatus = 'pending' | 'translating' | 'translated' | 'reviewed' | 'failed'

export interface SubtitleCue {
  index: number
  start_time: string
  end_time: string
  text: string
}

export interface ArticleSubtitle {
  id: number
  article_id: number
  original_language: string
  source_format: SubtitleSourceFormat
  original_cues: SubtitleCue[]
  translated_cues: SubtitleCue[] | null
  translation_status: SubtitleTranslationStatus
  srt_path: string | null
  vtt_path: string | null
  created_at: string
  updated_at: string
}
