<script setup lang="ts">
defineProps<{
  selectionCount: number
  loading?: boolean
}>()

const emit = defineEmits<{
  (e: 'approve'): void
  (e: 'reject'): void
  (e: 'skip'): void
  (e: 'clear'): void
}>()
</script>

<template>
  <div
    v-if="selectionCount > 0"
    class="flex items-center gap-3 bg-indigo-50 border border-indigo-200 rounded-lg px-4 py-3"
  >
    <span class="text-sm font-medium text-indigo-700">
      已選取 {{ selectionCount }} 篇
    </span>
    <div class="flex gap-2">
      <button
        :disabled="loading"
        class="px-3 py-1.5 text-sm font-medium rounded-md bg-green-600 text-white hover:bg-green-700 disabled:opacity-50"
        @click="emit('approve')"
      >
        批次通過
      </button>
      <button
        :disabled="loading"
        class="px-3 py-1.5 text-sm font-medium rounded-md bg-red-600 text-white hover:bg-red-700 disabled:opacity-50"
        @click="emit('reject')"
      >
        批次退回
      </button>
      <button
        :disabled="loading"
        class="px-3 py-1.5 text-sm font-medium rounded-md bg-gray-600 text-white hover:bg-gray-700 disabled:opacity-50"
        @click="emit('skip')"
      >
        批次略過
      </button>
      <button
        class="px-3 py-1.5 text-sm font-medium rounded-md text-gray-600 hover:text-gray-800"
        @click="emit('clear')"
      >
        取消選取
      </button>
    </div>
  </div>
</template>
