<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import CanvasIndex from '@/Components/SharedCanvas/CanvasIndex.vue';
import WhiteboardElements from '@/Components/Whiteboard/WhiteboardElements.vue';
import { WHITEBOARD_TEMPLATES, whiteboardTemplateScene } from '@/Components/Whiteboard/templates';
import { contentBounds } from '@/Components/Whiteboard/whiteboard';
import { PaintBrushIcon } from '@heroicons/vue/24/outline';

defineProps({
    whiteboards: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    can: { type: Object, default: () => ({}) },
});

const templatePreviews = WHITEBOARD_TEMPLATES.map((template) => {
    const elements = whiteboardTemplateScene(template.id).pages[0].elements;
    const box = contentBounds(elements);
    return {
        ...template,
        elements,
        viewBox: box ? `${box.x - 20} ${box.y - 20} ${box.w + 40} ${box.h + 40}` : '0 0 400 260',
    };
});
</script>

<template>
    <Head title="Whiteboards" />

    <AppLayout content-class="w-full max-w-none px-2 sm:px-4 lg:px-6">
        <template #header>Whiteboards</template>

        <CanvasIndex
            :records="whiteboards"
            :filters="filters"
            :can="can"
            routes="whiteboards"
            noun="whiteboard"
            title="Whiteboards"
            description="Sketch, brainstorm and run retros together — ink, shapes and sticky notes, saved automatically and ready to present."
            :icon="PaintBrushIcon"
            :templates="templatePreviews"
            :build-scene="whiteboardTemplateScene"
            test-prefix="whiteboard"
        >
            <template #template-preview="{ template }">
                <svg :viewBox="template.viewBox" class="h-24 w-full bg-white" preserveAspectRatio="xMidYMid meet">
                    <WhiteboardElements :elements="template.elements" />
                </svg>
            </template>
        </CanvasIndex>
    </AppLayout>
</template>
