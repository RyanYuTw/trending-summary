<script setup lang="ts">
import { ref, computed } from 'vue'
import type { ArticleSeo, FaqItem } from '@/types'
import { updateSeo, regenerateSeo } from '@/api/seo'

const props = defineProps<{
  articleId: number
  seo: ArticleSeo
}>()

const emit = defineEmits<{
  (e: 'update', seo: ArticleSeo): void
}>()

// ── Editable fields ──
const metaTitle = ref(props.seo.meta_title)
const metaDescription = ref(props.seo.meta_description)
const slug = ref(props.seo.slug)
const focusKeyword = ref(props.seo.focus_keyword)
const secondaryKeywords = ref(props.seo.secondary_keywords.join(', '))
const faqItems = ref<FaqItem[]>(props.seo.faq_items ? [...props.seo.faq_items] : [])

const showJsonLd = ref(false)
const saving = ref(false)
const regenerating = ref(false)

// ── Char counters ──
const META_TITLE_MAX = 60
const META_DESC_MAX = 160

const metaTitleCount = computed(() => metaTitle.value.length)
const metaDescCount = computed(() => metaDescription.value.length)

// ── Save ──
async function save() {
  saving.value = true
  try {
    const { data: response } = await updateSeo(props.articleId, {
      meta_title: metaTitle.value,
      meta_description: metaDescription.value,
      slug: slug.value,
      focus_keyword: focusKeyword.value,
      secondary_keywords: secondaryKeywords.value.split(',').map((s) => s.trim()).filter(Boolean),
      faq_items: faqItems.value.length > 0 ? faqItems.value : null,
    })
    emit('update', response.data)
  } finally {
    saving.value = false
  }
}

async function doRegenerate() {
  regenerating.value = true
  try {
    const { data: response } = await regenerateSeo(props.articleId)
    // Sync local state
    metaTitle.value = response.data.meta_title
    metaDescription.value = response.data.meta_description
    slug.value = response.data.slug
    focusKeyword.value = response.data.focus_keyword
    secondaryKeywords.value = response.data.secondary_keywords.join(', ')
    faqItems.value = response.data.faq_items ? [...response.data.faq_items] : []
    emit('update', response.data)
  } finally {
    regenerating.value = false
  }
}

// ── FAQ editor ──
function addFaq() {
  faqItems.value.push({ question: '', answer: '' })
}

function removeFaq(index: number) {
  faqItems.value.splice(index, 1)
}
</script>

<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between">
      <h3 class="text-sm font-semibold text-gray-900">SEO 設定</h3>
      <div class="flex gap-2">
        <button
          :disabled="regenerating"
          class="px-3 py-1.5 text-sm font-medium rounded-md text-indigo-600 border border-indigo-300 hover:bg-indigo-50 disabled:opacity-50"
          @click="doRegenerate"
        >
          {{ regenerating ? '重新產生中...' : '重新產生' }}
        </button>
        <button
          :disabled="saving"
          class="px-3 py-1.5 text-sm font-medium rounded-md bg-indigo-600 text-white hover:bg-indigo-700 disabled:opacity-50"
          @click="save"
        >
          {{ saving ? '儲存中...' : '儲存' }}
        </button>
      </div>
    </div>

    <!-- Google search result simulation -->
    <div class="border border-gray-200 rounded-lg p-4 bg-white">
      <p class="text-xs text-gray-400 mb-2">Google 搜尋結果預覽</p>
      <div class="space-y-0.5">
        <p class="text-lg text-blue-700 hover:underline cursor-pointer line-clamp-1">
          {{ metaTitle || '標題預覽' }}
        </p>
        <p class="text-sm text-green-700">{{ seo.canonical_url || 'https://example.com/' }}{{ slug }}</p>
        <p class="text-sm text-gray-600 line-clamp-2">{{ metaDescription || '描述預覽' }}</p>
      </div>
    </div>

    <!-- OG social share card simulation -->
    <div class="border border-gray-200 rounded-lg overflow-hidden bg-white">
      <p class="text-xs text-gray-400 px-4 pt-3 mb-2">社群分享卡片預覽</p>
      <div v-if="seo.og_data.image" class="h-40 bg-gray-100">
        <img :src="seo.og_data.image" alt="OG Image" class="w-full h-full object-cover" />
      </div>
      <div class="px-4 py-3 border-t border-gray-100">
        <p class="text-xs text-gray-400 uppercase">{{ seo.og_data.site_name }}</p>
        <p class="text-sm font-semibold text-gray-900 line-clamp-1">{{ seo.og_data.title || metaTitle }}</p>
        <p class="text-xs text-gray-500 line-clamp-2">{{ seo.og_data.description || metaDescription }}</p>
      </div>
    </div>

    <!-- Editable SEO fields -->
    <div class="space-y-4">
      <!-- Meta Title -->
      <div>
        <label class="block text-xs font-medium text-gray-500 mb-1">
          Meta Title
          <span :class="metaTitleCount > META_TITLE_MAX ? 'text-red-500' : 'text-gray-400'">
            ({{ metaTitleCount }}/{{ META_TITLE_MAX }})
          </span>
        </label>
        <input
          v-model="metaTitle"
          type="text"
          class="w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
          :class="{ 'border-red-300': metaTitleCount > META_TITLE_MAX }"
        />
      </div>

      <!-- Meta Description -->
      <div>
        <label class="block text-xs font-medium text-gray-500 mb-1">
          Meta Description
          <span :class="metaDescCount > META_DESC_MAX ? 'text-red-500' : 'text-gray-400'">
            ({{ metaDescCount }}/{{ META_DESC_MAX }})
          </span>
        </label>
        <textarea
          v-model="metaDescription"
          rows="3"
          class="w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 resize-none"
          :class="{ 'border-red-300': metaDescCount > META_DESC_MAX }"
        />
      </div>

      <!-- Slug -->
      <div>
        <label class="block text-xs font-medium text-gray-500 mb-1">Slug</label>
        <input
          v-model="slug"
          type="text"
          class="w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
        />
      </div>

      <!-- Focus Keyword -->
      <div>
        <label class="block text-xs font-medium text-gray-500 mb-1">Focus Keyword</label>
        <input
          v-model="focusKeyword"
          type="text"
          class="w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
        />
      </div>

      <!-- Secondary Keywords -->
      <div>
        <label class="block text-xs font-medium text-gray-500 mb-1">Secondary Keywords（逗號分隔）</label>
        <input
          v-model="secondaryKeywords"
          type="text"
          class="w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
        />
      </div>
    </div>

    <!-- JSON-LD viewer -->
    <div class="border border-gray-200 rounded-lg overflow-hidden">
      <button
        class="w-full flex items-center justify-between px-4 py-2.5 bg-gray-50 text-sm font-medium text-gray-700 hover:bg-gray-100"
        @click="showJsonLd = !showJsonLd"
      >
        <span>JSON-LD 結構化資料</span>
        <span class="text-gray-400">{{ showJsonLd ? '▲' : '▼' }}</span>
      </button>
      <div v-if="showJsonLd" class="p-4 bg-gray-900 overflow-x-auto">
        <pre class="text-xs text-green-400 whitespace-pre-wrap">{{ JSON.stringify(seo.json_ld, null, 2) }}</pre>
      </div>
    </div>

    <!-- FAQ editor -->
    <div class="space-y-3">
      <div class="flex items-center justify-between">
        <h4 class="text-sm font-medium text-gray-700">FAQ 問答</h4>
        <button
          class="text-sm text-indigo-600 hover:text-indigo-800"
          @click="addFaq"
        >
          + 新增問答
        </button>
      </div>
      <div v-for="(faq, idx) in faqItems" :key="idx" class="border border-gray-200 rounded-lg p-3 space-y-2">
        <div class="flex items-start justify-between gap-2">
          <div class="flex-1 space-y-2">
            <input
              v-model="faq.question"
              type="text"
              placeholder="問題"
              class="w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
            />
            <textarea
              v-model="faq.answer"
              rows="2"
              placeholder="答案"
              class="w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 resize-none"
            />
          </div>
          <button
            class="text-red-400 hover:text-red-600 text-sm shrink-0"
            @click="removeFaq(idx)"
          >
            刪除
          </button>
        </div>
      </div>
      <p v-if="faqItems.length === 0" class="text-sm text-gray-500">尚未設定 FAQ</p>
    </div>

    <!-- AIO checklist -->
    <div v-if="seo.aio_checklist" class="space-y-2">
      <h4 class="text-sm font-medium text-gray-700">AIO 檢查清單</h4>
      <div class="space-y-1">
        <div
          v-for="(passed, item) in seo.aio_checklist"
          :key="String(item)"
          class="flex items-center gap-2 text-sm"
        >
          <span :class="passed ? 'text-green-500' : 'text-red-400'">
            {{ passed ? '✓' : '✗' }}
          </span>
          <span :class="passed ? 'text-gray-700' : 'text-gray-500'">{{ item }}</span>
        </div>
      </div>
    </div>
  </div>
</template>
