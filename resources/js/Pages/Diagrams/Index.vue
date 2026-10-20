<script setup>
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import CanvasIndex from '@/Components/SharedCanvas/CanvasIndex.vue';
import DiagramElements from '@/Components/Diagram/DiagramElements.vue';
import { DIAGRAM_TEMPLATES, templateScene } from '@/Components/Diagram/templates';
import { contentBounds } from '@/Components/Diagram/geometry';
import { importDrawio, importNotes } from '@/Components/Diagram/drawio';
import { readFileText, uploadDiagramImage } from '@/Components/Diagram/upload';
import { useToast } from '@/Composables/useToast';
import { ArrowUpTrayIcon, ShareIcon } from '@heroicons/vue/24/outline';

defineProps({
    diagrams: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    can: { type: Object, default: () => ({}) },
});

const { showError, showSuccess } = useToast();

const templatePreviews = DIAGRAM_TEMPLATES.map((template) => {
    const elements = templateScene(template.id).pages[0].elements;
    const box = contentBounds(elements);
    return {
        ...template,
        elements,
        viewBox: box ? `${box.x - 20} ${box.y - 20} ${box.w + 40} ${box.h + 40}` : '0 0 400 260',
    };
});

// ── Import a .drawio file as a new diagram ───────────────────────────────────

const importInput = ref(null);
const importing = ref(false);

const onImportPicked = async (event) => {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file || importing.value) return;
    importing.value = true;
    try {
        const { pages, warnings } = await importDrawio(await readFileText(file), { uploadImage: uploadDiagramImage });
        const notes = importNotes(warnings);
        if (pages.length > 30) notes.push(`${pages.length - 30} page(s) over the 30-page limit were left out`);
        if (notes.length) showSuccess(`Imported ${file.name} — ${notes.join('; ')}.`);
        router.post(route('diagrams.store'), {
            title: file.name.replace(/\.(drawio|xml)$/i, '').slice(0, 255) || 'Imported diagram',
            visibility: 'department',
            scene: { version: 1, pages: pages.slice(0, 30) },
        }, {
            onFinish: () => { importing.value = false; },
        });
    } catch (error) {
        showError(error?.message || 'Could not import this file.');
        importing.value = false;
    }
};
</script>

<template>
    <Head title="Diagrams" />

    <AppLayout content-class="w-full max-w-none px-2 sm:px-4 lg:px-6">
        <template #header>Diagrams</template>

        <CanvasIndex
            :records="diagrams"
            :filters="filters"
            :can="can"
            routes="diagrams"
            noun="diagram"
            title="Diagrams"
            description="Flowcharts, process maps and diagrams — drawn together, saved automatically, ready to present."
            :icon="ShareIcon"
            :templates="templatePreviews"
            :build-scene="templateScene"
            test-prefix="diagram"
        >
            <template #actions>
                <button v-if="can.create" type="button" class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-bold text-gray-700 shadow-sm hover:bg-gray-50 disabled:opacity-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200" :disabled="importing" title="Create a diagram from a draw.io / diagrams.net file" data-testid="diagram-import-file" @click="importInput?.click()">
                    <ArrowUpTrayIcon class="h-4 w-4" />
                    {{ importing ? 'Importing…' : 'Import .drawio' }}
                </button>
                <input ref="importInput" type="file" class="hidden" accept=".drawio,.xml,application/xml,text/xml" data-testid="diagram-import-input" @change="onImportPicked">
            </template>
            <template #template-preview="{ template }">
                <svg :viewBox="template.viewBox" class="h-24 w-full bg-white" preserveAspectRatio="xMidYMid meet">
                    <DiagramElements :elements="template.elements" />
                </svg>
            </template>
        </CanvasIndex>
    </AppLayout>
</template>
