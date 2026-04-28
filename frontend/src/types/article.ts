export type ArticleStatus =
  | 'pending'
  | 'candidate'
  | 'filtered'
  | 'generated'
  | 'reviewing'
  | 'approved'
  | 'rejected'
  | 'published'
  | 'failed'
  | 'scheduled'

export type ContentType = 'article' | 'video'
export type TitleStyle = 'news' | 'social' | 'seo'

export interface TrendingArticle {
  id: number
  uuid: string
  title: string
  original_title: string
  original_url: string
  source_name: string
  content_type: ContentType
  summary: string | null
  selected_title: string | null
  status: ArticleStatus
  relevance_score: number
  quality_score: number | null
  is_auto_published: boolean
  trend_keywords: string[]
  published_at: string | null
  created_at: string
  updated_at: string
  generated_titles?: GeneratedTitle[]
  image?: ArticleImage | null
  subtitle?: ArticleSubtitle | null
  seo?: ArticleSeo | null
  quality_report?: QualityReport | null
  publish_records?: PublishRecord[]
  template?: ArticleTemplate | null
}

export interface GeneratedTitle {
  id: number
  article_id: number
  text: string
  style: TitleStyle
  is_selected: boolean
}

export interface ArticleImage {
  id: number
  article_id: number
  url: string
  thumbnail_url: string | null
  source_provider: string | null
  caption: string | null
  versions: Record<string, unknown> | null
  needs_manual: boolean
}

export interface SpellingIssue {
  word: string
  suggestion: string
  position: number
}

export interface TerminologyIssue {
  term: string
  variants: string[]
  suggested: string
}

export interface SensitiveTerm {
  term: string
  reason: string
  suggestion: string
}

export interface FactReference {
  claim: string
  source_passage: string
  confidence: number
}

export interface SeoSuggestion {
  field: string
  issue: string
  suggestion: string
}

export interface QualityReport {
  id: number
  article_id: number
  spelling_issues: SpellingIssue[]
  terminology_issues: TerminologyIssue[]
  sensitive_terms: SensitiveTerm[]
  fact_references: FactReference[]
  seo_suggestions: SeoSuggestion[]
  aio_checklist: Record<string, boolean>
  overall_score: number
}

export type PublishPlatform = 'inkmagine' | 'wordpress'
export type PublishStatus = 'pending' | 'publishing' | 'published' | 'failed' | 'draft'

export interface PublishRecord {
  id: number
  article_id: number
  platform: PublishPlatform
  status: PublishStatus
  external_id: string | null
  external_url: string | null
  error_message: string | null
  published_at: string | null
}

// Re-export related types used in article context
export type { ArticleSubtitle } from './subtitle'
export type { ArticleSeo } from './seo'
export type { ArticleTemplate } from './template'
