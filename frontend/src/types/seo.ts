export interface OgData {
  title: string
  description: string
  image: string
  type: string
  url: string
  site_name: string
  locale: string
}

export interface TwitterData {
  card: string
  title: string
  description: string
  image: string
}

export interface FaqItem {
  question: string
  answer: string
}

export interface ArticleSeo {
  id: number
  article_id: number
  meta_title: string
  meta_description: string
  slug: string
  canonical_url: string | null
  og_data: OgData
  twitter_data: TwitterData
  json_ld: Record<string, unknown>
  focus_keyword: string
  secondary_keywords: string[]
  direct_answer_block: string | null
  faq_items: FaqItem[] | null
  faq_schema: Record<string, unknown> | null
  aio_checklist: Record<string, boolean> | null
  created_at: string
  updated_at: string
}
