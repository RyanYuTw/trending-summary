<script setup lang="ts">
import { computed } from 'vue'
import type { QualityReport as QualityReportType } from '@/types'

const props = defineProps<{
  report: QualityReportType
}>()

const emit = defineEmits<{
  (e: 'jump', position: number): void
}>()

// Score color
const scoreColor = computed(() => {
  const s = props.report.overall_score
  if (s >= 80) return { bg: 'bg-green-100', text: 'text-green-700', ring: 'ring-green-500' }
  if (s >= 60) return { bg: 'bg-yellow-100', text: 'text-yellow-700', ring: 'ring-yellow-500' }
  return { bg: 'bg-red-100', text: 'text-red-700', ring: 'ring-red-500' }
})

// Issue categories
const categories = computed(() => [
  {
    key: 'spelling',
    label: '拼寫問題',
    icon: '📝',
    items: props.report.spelling_issues.map((i) => ({
      text: `「${i.word}」→ 建議：${i.suggestion}`,
      position: i.position,
    })),
  },
  {
    key: 'terminology',
    label: '用語問題',
    icon: '📖',
    items: props.report.terminology_issues.map((i) => ({
      text: `「${i.term}」→ 建議：${i.suggested}`,
      position: 0,
    })),
  },
  {
    key: 'sensitive',
    label: '敏感用語',
    icon: '⚠️',
    items: props.report.sensitive_terms.map((i) => ({
      text: `「${i.term}」— ${i.reason}（建議：${i.suggestion}）`,
      position: 0,
    })),
  },
  {
    key: 'facts',
    label: '事實查核',
    icon: '🔍',
    items: props.report.fact_references.map((i) => ({
      text: `${i.claim}（信心度：${Math.round(i.confidence * 100)}%）`,
      position: 0,
    })),
  },
  {
    key: 'seo',
    label: 'SEO 建議',
    icon: '🔎',
    items: props.report.seo_suggestions.map((i) => ({
      text: `[${i.field}] ${i.issue} → ${i.suggestion}`,
      position: 0,
    })),
  },
])

const totalIssues = computed(() =>
  categories.value.reduce((sum, cat) => sum + cat.items.length, 0)
)
</script>

<template>
  <div class="space-y-4">
    <div class="flex items-center justify-between">
      <h3 class="text-sm font-semibold text-gray-900">品質報告</h3>
      <span class="text-xs text-gray-500">{{ totalIssues }} 個問題</span>
    </div>

    <!-- Overall score -->
    <div class="flex items-center gap-4">
      <div
        class="flex items-center justify-center w-16 h-16 rounded-full ring-4"
        :class="[scoreColor.bg, scoreColor.ring]"
      >
        <span class="text-xl font-bold" :class="scoreColor.text">
          {{ report.overall_score }}
        </span>
      </div>
      <div>
        <p class="text-sm font-medium text-gray-900">
          {{ report.overall_score >= 80 ? '品質良好' : report.overall_score >= 60 ? '品質尚可' : '需要改善' }}
        </p>
        <p class="text-xs text-gray-500">總分 0–100</p>
      </div>
    </div>

    <!-- Issue categories -->
    <div class="space-y-3">
      <div v-for="cat in categories" :key="cat.key">
        <template v-if="cat.items.length > 0">
          <p class="text-xs font-medium text-gray-500 mb-1">
            {{ cat.icon }} {{ cat.label }}（{{ cat.items.length }}）
          </p>
          <div class="space-y-1">
            <div
              v-for="(item, idx) in cat.items"
              :key="idx"
              class="text-sm text-gray-700 px-3 py-1.5 rounded-md bg-gray-50 hover:bg-gray-100 cursor-pointer transition-colors"
              @click="item.position > 0 && emit('jump', item.position)"
            >
              {{ item.text }}
            </div>
          </div>
        </template>
      </div>

      <p v-if="totalIssues === 0" class="text-sm text-green-600">✓ 沒有發現品質問題</p>
    </div>

    <!-- AIO checklist -->
    <div v-if="report.aio_checklist && Object.keys(report.aio_checklist).length > 0" class="space-y-2">
      <h4 class="text-xs font-medium text-gray-500">AIO 檢查清單</h4>
      <div class="space-y-1">
        <div
          v-for="(passed, item) in report.aio_checklist"
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
