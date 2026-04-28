<script setup lang="ts">
import { ref, computed } from 'vue'
import type { PublishPlatform, PublishStatus, PublishRecord } from '@/types'
import { publishArticle, getPublishStatus } from '@/api/articles'

const props = defineProps<{
  articleId: number
  publishRecords?: PublishRecord[]
}>()

const emit = defineEmits<{
  (e: 'published', records: PublishRecord[]): void
}>()

const selectedPlatforms = ref<PublishPlatform[]>([])
const publishStatus = ref<PublishStatus>('published')
const publishing = ref(false)
const records = ref<PublishRecord[]>(props.publishRecords ?? [])

const platforms: { value: PublishPlatform; label: string }[] = [
  { value: 'inkmagine', label: 'InkMagine' },
  { value: 'wordpress', label: 'WordPress' },
]

const canPublish = computed(() => selectedPlatforms.value.length > 0 && !publishing.value)

const failedRecords = computed(() => records.value.filter((r) => r.status === 'failed'))

function togglePlatform(platform: PublishPlatform) {
  const idx = selectedPlatforms.value.indexOf(platform)
  if (idx === -1) {
    selectedPlatforms.value.push(platform)
  } else {
    selectedPlatforms.value.splice(idx, 1)
  }
}

function statusBadge(status: PublishStatus): { class: string; label: string } {
  const map: Record<PublishStatus, { class: string; label: string }> = {
    pending: { class: 'bg-gray-100 text-gray-700', label: '待發佈' },
    publishing: { class: 'bg-blue-100 text-blue-700', label: '發佈中' },
    published: { class: 'bg-green-100 text-green-700', label: '已發佈' },
    failed: { class: 'bg-red-100 text-red-700', label: '失敗' },
    draft: { class: 'bg-yellow-100 text-yellow-700', label: '草稿' },
  }
  return map[status]
}

async function doPublish() {
  if (!canPublish.value) return
  publishing.value = true
  try {
    const { data: response } = await publishArticle(props.articleId, {
      platforms: selectedPlatforms.value,
      status: publishStatus.value,
    })
    records.value = response.data
    emit('published', response.data)
    selectedPlatforms.value = []
  } finally {
    publishing.value = false
  }
}

async function retryFailed(platform: PublishPlatform) {
  publishing.value = true
  try {
    const { data: response } = await publishArticle(props.articleId, {
      platforms: [platform],
      status: publishStatus.value,
    })
    // Merge updated records
    for (const rec of response.data) {
      const idx = records.value.findIndex((r) => r.platform === rec.platform)
      if (idx !== -1) {
        records.value[idx] = rec
      } else {
        records.value.push(rec)
      }
    }
    emit('published', records.value)
  } finally {
    publishing.value = false
  }
}

async function refreshStatus() {
  const { data: response } = await getPublishStatus(props.articleId)
  records.value = response.data
}
</script>

<template>
  <div class="space-y-4">
    <h3 class="text-sm font-semibold text-gray-900">發佈目標</h3>

    <!-- Platform checkboxes -->
    <div class="space-y-2">
      <label
        v-for="p in platforms"
        :key="p.value"
        class="flex items-center gap-2 cursor-pointer"
      >
        <input
          type="checkbox"
          :checked="selectedPlatforms.includes(p.value)"
          class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
          @change="togglePlatform(p.value)"
        />
        <span class="text-sm text-gray-700">{{ p.label }}</span>
      </label>
    </div>

    <!-- Publish status selection -->
    <div>
      <label class="block text-xs font-medium text-gray-500 mb-1">發佈狀態</label>
      <select
        v-model="publishStatus"
        class="rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
      >
        <option value="published">正式發佈</option>
        <option value="draft">草稿</option>
      </select>
    </div>

    <!-- Publish button -->
    <button
      :disabled="!canPublish"
      class="w-full px-4 py-2 text-sm font-medium rounded-md bg-indigo-600 text-white hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed"
      @click="doPublish"
    >
      {{ publishing ? '發佈中...' : '發佈' }}
    </button>

    <!-- Publish records / progress -->
    <div v-if="records.length > 0" class="space-y-2">
      <div class="flex items-center justify-between">
        <h4 class="text-xs font-medium text-gray-500">發佈記錄</h4>
        <button class="text-xs text-indigo-600 hover:text-indigo-800" @click="refreshStatus">
          重新整理
        </button>
      </div>
      <div
        v-for="rec in records"
        :key="rec.id"
        class="flex items-center justify-between p-3 rounded-md border border-gray-200"
      >
        <div class="flex items-center gap-3">
          <span class="text-sm font-medium text-gray-700 capitalize">{{ rec.platform }}</span>
          <span
            class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium"
            :class="statusBadge(rec.status).class"
          >
            {{ statusBadge(rec.status).label }}
          </span>
        </div>
        <div class="flex items-center gap-2">
          <a
            v-if="rec.external_url"
            :href="rec.external_url"
            target="_blank"
            rel="noopener noreferrer"
            class="text-xs text-indigo-600 hover:text-indigo-800"
          >
            查看 ↗
          </a>
          <button
            v-if="rec.status === 'failed'"
            class="text-xs text-red-600 hover:text-red-800"
            @click="retryFailed(rec.platform)"
          >
            重試
          </button>
        </div>
      </div>
      <!-- Error messages for failed -->
      <div v-for="rec in failedRecords" :key="'err-' + rec.id" class="text-xs text-red-500 px-1">
        {{ rec.platform }}: {{ rec.error_message }}
      </div>
    </div>
  </div>
</template>
