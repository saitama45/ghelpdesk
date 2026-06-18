<script setup>
import { onMounted } from 'vue';
import { MoonIcon, SunIcon } from '@heroicons/vue/24/outline';
import { useTheme } from '@/Composables/useTheme.js';

const { isDark, init, toggle } = useTheme();

onMounted(() => {
    init();
});
</script>

<template>
    <div class="relative">
        <button
            type="button"
            class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-gray-200 bg-white text-gray-600 shadow-sm transition-colors hover:bg-gray-50 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800 dark:hover:text-white"
            :title="isDark ? 'Switch to Light Mode' : 'Switch to Dark Mode'"
            :aria-label="isDark ? 'Switch to Light Mode' : 'Switch to Dark Mode'"
            @click="toggle"
        >
            <SunIcon v-if="isDark" class="h-5 w-5" />
            <MoonIcon v-else class="h-5 w-5" />
        </button>
    </div>
</template>
