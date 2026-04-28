<script setup lang="ts">
import { computed } from 'vue'
import type { TrendingArticle } from '@/types'
import { useArticleStore } from '@/stores/articleStore'
import TitleSelector from '@/components/TitleSelector.vue'
import ImagePicker from '@/components/ImagePicker.vue'
import SeoPanel from '@/components/SeoPanel.vue'
import QualityReport from '@/components/QualityReport.vue'
import SubtitleEditor from '@/components/SubtitleEditor.vue'
import SubtitlePreview from '@/components/SubtitlePreview.vue'
import PublishTargetSelector from '@/components/PublishTargetSelector.vue'
import ArticleDiff from '@/components/ArticleDiff.vue'

const props = defineProps<{
  article: TrendingArticle
}>()

const emit = defineEmits<{
  (e: 'action', action: 'approve' | 'reject' | 'skip'): void
}>()

const articleStore = useArticleStore()

const isVideo = computed(() => props.article.content_type === 'video')
const canReview = computed(() =>
  ['generated', 'reviewing'].includes(props.article.status)
)

async function selectTitle(title: string) {
  await articleStore.updateArticle(props.article.id, { selected_title: title })
}

function onImageUpdate() {
  // Refresh article to get updated image
  articleStore.fetchArticle(props.article.id)
}

function onSeoUpdate() {
  articleStore.fetchArticle(props.article.id)
}

function onSubtitleUpdate() {
  articleStore.fetchArticle(props.article.id)
}

function onQualityJump(position: number) {
  // Could scroll to position in the diff view — placeholder for now
  console.log('Jump to position:', position)
}
</script>

<template>
  <div class="space-y-6">
    <!-- Header -->
    <div class="flex items-start justify-between gap-4">
      <div>
        <h2 class="text-lg font-semibold text-gray-900">
          {{ article.selected_title || article.title }}
        </h2>
        <div class="flex items-center gap-3 mt-1 text-sm text-gray-500">
          <span>{{ article.source_name }}</span>
          <span v-if="isVideo" class="text-purple-600">🎬 影片</span>
          <span>{{ new Date(article.created_at).toLocaleDateString() }}</span>
          <a
            :href="article.original_url"
            target="_blank"
            rel="noopener noreferrer"
            class="text-indigo-600 hover:text-indigo-800"
          >
            原文 ↗
          </a>
        </div>
      </div>

      <!-- Action buttons -->
      <div v-if="canReview" class="flex gap-2 shrink-0">
        <button
          class="px-4 py-2 text-sm font-medium rounded-md bg-green-600 text-white hover:bg-green-700"
          @click="emit('action', 'approve')"
        >
          通過
        </button>
        <button
          class="px-4 py-2 text-sm font-medium rounded-md bg-red-600 text-white hover:bg-red-700"
          @click="emit('action', 'reject')"
        >
          退回
        </button>
        <button
          class="px-4 py-2 text-sm font-medium rounded-md bg-gray-500 text-white hover:bg-gray-600"
          @click="emit('action', 'skip')"
        >
          略過
        </button>
      </div>
    </div>

    <!-- Article Diff: original vs summary -->
    <ArticleDiff
      v-if="article.summary"
      :original-text="article.content_type === 'article' ? (article as any).content_body ?? '' : ''"
      :summary-text="article.summary"
    />

    <!-- Title Selector -->
    <div v-if="article.generated_titles && article.generated_titles.length > 0" class="bg-white rounded-lg shadow p-4">
      <TitleSelector
        :titles="article.generated_titles"
        :selected-title="article.selected_title"
        @select="selectTitle"
      />
    </div>

    <!-- Image Picker -->
    <div class="bg-white rounded-lg shadow p-4">
      <ImagePicker
        :article-id="article.id"
        :image="article.image"
        @update="onImageUpdate"
      />
    </div>

    <!-- Subtitle Editor (video only) -->
    <template v-if="isVideo && article.subtitle">
      <div class="bg-white rounded-lg shadow p-4">
        <SubtitlePreview
          :subtitle="article.subtitle"
          :video-url="article.original_url"
        />
      </div>
      <div class="bg-white rounded-lg shadow p-4">
        <SubtitleEditor
          :article-id="article.id"
          :subtitle="article.subtitle"
          @update="onSubtitleUpdate"
        />
      </div>
    </template>

    <!-- SEO Panel -->
    <div v-if="article.seo" class="bg-white rounded-lg shadow p-4">
      <SeoPanel
        :article-id="article.id"
        :seo="article.seo"
        @update="onSeoUpdate"
      />
    </div>

    <!-- Quality Report -->
    <div v-if="article.quality_report" class="bg-white rounded-lg shadow p-4">
      <QualityReport
        :report="article.quality_report"
        @jump="onQualityJump"
      />
    </div>

    <!-- Publish Target Selector -->
    <div v-if="article.status === 'approved' || article.status === 'published'" class="bg-white rounded-lg shadow p-4">
      <PublishTargetSelector
        :article-id="article.id"
        :publish-records="article.publish_records"
        @published="articleStore.fetchArticle(article.id)"
      />
    </div>
  </div>
</template>
