<script setup lang="ts">
defineProps<{
  originalText: string
  summaryText: string
}>()
</script>

<template>
  <div class="space-y-3">
    <h3 class="text-sm font-semibold text-gray-900">原文 vs 摘要對照</h3>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <!-- Original -->
      <div class="border border-gray-200 rounded-lg overflow-hidden">
        <div class="bg-gray-50 px-3 py-2 border-b border-gray-200">
          <span class="text-xs font-medium text-gray-500 uppercase">原文</span>
        </div>
        <div class="p-4 max-h-96 overflow-y-auto">
          <p class="text-sm text-gray-700 whitespace-pre-wrap leading-relaxed">{{ originalText }}</p>
        </div>
      </div>
      <!-- Summary -->
      <div class="border border-indigo-200 rounded-lg overflow-hidden">
        <div class="bg-indigo-50 px-3 py-2 border-b border-indigo-200">
          <span class="text-xs font-medium text-indigo-600 uppercase">生成摘要</span>
        </div>
        <div class="p-4 max-h-96 overflow-y-auto">
          <p class="text-sm text-gray-700 whitespace-pre-wrap leading-relaxed">{{ summaryText }}</p>
        </div>
      </div>
    </div>
  </div>
</template>
