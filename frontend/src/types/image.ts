export interface ImageVersions {
  original?: string
  social?: string
  thumbnail?: string
  [key: string]: string | undefined
}

export interface ImageSearchResult {
  id: string
  url: string
  thumbnail_url: string | null
  caption: string | null
  source_provider: string
  versions: ImageVersions | null
}
