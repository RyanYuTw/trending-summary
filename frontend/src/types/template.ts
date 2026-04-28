export interface OutputField {
  key: string
  label: string
  type: 'text' | 'paragraph' | 'list' | 'table' | 'quote'
  required: boolean
}

export interface TemplateSettings {
  tone: string
  use_emoji: boolean
  max_paragraphs: number
  max_total_chars: number
}

export interface ArticleTemplate {
  id: number
  slug: string
  name: string
  description: string
  is_builtin: boolean
  output_structure: OutputField[]
  settings: TemplateSettings
  created_at: string
  updated_at: string
}
