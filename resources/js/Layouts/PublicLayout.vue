<script setup>
import { onMounted, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import ThemeToggle from '@/Components/ThemeToggle.vue';
import Toast from '@/Components/Toast.vue';
import { useToast } from '@/Composables/useToast.js';

const page = usePage();
const { success, error, warning, info } = useToast();

const checkFlashMessages = () => {
    const flash = page.props.flash || {};
    if (flash.success) success(flash.success);
    if (flash.error) error(flash.error);
    if (flash.warning) warning(flash.warning);
    if (flash.info) info(flash.info);
};

onMounted(() => {
    checkFlashMessages();
});

watch(() => page.props.flash, () => {
    checkFlashMessages();
}, { deep: true });
</script>

<template>
    <div class="min-h-screen bg-gray-50 dark:bg-gray-950 flex flex-col relative overflow-x-hidden">
        <div class="fixed right-4 top-4 z-50">
            <ThemeToggle />
        </div>

        <!-- Optional: Simple Header/Nav can go here -->
        
        <!-- Main Content -->
        <main class="flex-1">
            <slot />
        </main>

        <!-- Footer -->
        <footer class="py-12 text-center text-gray-400 text-sm dark:text-gray-400">
            &copy; 2026 TAS Support. All rights reserved.
        </footer>

        <!-- Toast Notifications -->
        <Toast />
    </div>
</template>
