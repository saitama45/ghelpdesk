<script setup>
import { computed, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import CanvasHeader from '@/Components/SharedCanvas/CanvasHeader.vue';
import WhiteboardWorkspace from '@/Components/Whiteboard/WhiteboardWorkspace.vue';
import WhiteboardElements from '@/Components/Whiteboard/WhiteboardElements.vue';
import { contentBounds, createWhiteboardDocument } from '@/Components/Whiteboard/whiteboard';
import { useSceneSync } from '@/Composables/useSceneSync';
import { useToast } from '@/Composables/useToast';
import { ArrowDownTrayIcon, PlayIcon } from '@heroicons/vue/24/outline';

// Standalone whiteboard (/whiteboards/{id}): shared by visibility, no board cards.
const props = defineProps({
    whiteboard: { type: Object, required: true },
    can: { type: Object, default: () => ({}) },
});

const { showError } = useToast();

const doc = createWhiteboardDocument(props.whiteboard.scene);
const editable = ref(!!props.can.edit);
const title = ref(props.whiteboard.title);
const visibility = ref(props.whiteboard.visibility);

// ── List-card preview (page 1, thinned so it stays small) ─────────────────────

const previewHost = ref(null);
const previewElements = computed(() => (doc.scene.pages[0]?.elements || []).map((element) => {
    if (element.type !== 'pen' || element.points.length <= 80) return element;
    const step = Math.ceil(element.points.length / 80 / 2) * 2;
    const points = element.points.filter((_, index) => index % step < 2).map((value) => Math.round(value));
    return { ...element, points: [...points, ...element.points.slice(-2).map((value) => Math.round(value))] };
}));
const previewBox = computed(() => {
    const box = contentBounds(previewElements.value);
    if (!box) return '0 0 320 200';
    const pad = Math.max(box.w, box.h) * 0.06 + 10;
    return `${box.x - pad} ${box.y - pad} ${box.w + pad * 2} ${box.h + pad * 2}`;
});

const buildPreview = () => {
    const svg = previewHost.value?.querySelector('svg');
    if (!svg) return null;
    let markup = svg.outerHTML.replace(/<!--[\s\S]*?-->/g, '').replace(/ data-[a-z-]+="[^"]*"/g, '');
    if (markup.length > 58000) markup = markup.replace(/<text[\s\S]*?<\/text>/g, '');
    return markup.length > 58000 ? null : markup;
};

// ── Saving & live updates ────────────────────────────────────────────────────

const { saveState, saveError, lastSavedAt, lastSavedBy } = useSceneSync({
    doc,
    editable,
    initialRevision: props.whiteboard.revision || 0,
    initialSavedAt: props.whiteboard.updated_at,
    initialSavedBy: props.whiteboard.editor,
    saveUrl: () => route('whiteboards.scene', props.whiteboard.id),
    stateUrl: () => route('whiteboards.state', props.whiteboard.id),
    savePayload: () => ({ preview: buildPreview() }),
    onPoll: (data) => {
        if (data.title) title.value = data.title;
        if (data.visibility) visibility.value = data.visibility;
    },
    onError: (message) => showError(message),
});

const formatDate = (value) => {
    if (!value) return '';
    const date = new Date(String(value).replace(' ', 'T'));
    return Number.isNaN(date.getTime()) ? '' : date.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
};

const saveLabel = computed(() => {
    if (!editable.value) return saveError.value || 'View only';
    if (saveState.value === 'saving') return 'Saving…';
    if (saveState.value === 'pending') return 'Unsaved changes';
    if (saveState.value === 'error') return saveError.value;
    if (!lastSavedAt.value) return 'All changes saved';
    return `Saved ${formatDate(lastSavedAt.value)}${lastSavedBy.value ? ` · ${lastSavedBy.value}` : ''}`;
});
</script>

<template>
    <Head :title="`${title} · Whiteboard`" />

    <AppLayout content-class="max-w-none px-0 sm:px-0 lg:px-0">
        <template #header>Whiteboards</template>

        <WhiteboardWorkspace :doc="doc" :editable="editable" :export-name="title">
            <template #header="{ startPresenting, exportPng, exporting }">
                <CanvasHeader
                    v-model:title="title"
                    v-model:visibility="visibility"
                    :record-id="whiteboard.id"
                    routes="whiteboards"
                    noun="whiteboard"
                    :creator="whiteboard.creator"
                    :can="can"
                    :editable="editable"
                    :save-state="saveState"
                    :save-label="saveLabel"
                    test-prefix="whiteboard"
                >
                    <template #actions>
                        <button type="button" class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-semibold text-gray-700 hover:bg-gray-100 disabled:opacity-50 dark:text-gray-200 dark:hover:bg-gray-800" :disabled="exporting" data-testid="whiteboard-export" @click="exportPng">
                            <ArrowDownTrayIcon class="h-4 w-4" />
                            Export PNG
                        </button>
                        <button type="button" class="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-3 py-1.5 text-sm font-bold text-white hover:bg-blue-700" data-testid="whiteboard-present" @click="startPresenting">
                            <PlayIcon class="h-4 w-4" />
                            Present
                        </button>
                    </template>
                </CanvasHeader>
            </template>
        </WhiteboardWorkspace>

        <!-- Page 1 rendered off-screen: its markup becomes the list-card preview on save. -->
        <div ref="previewHost" class="hidden" aria-hidden="true">
            <svg xmlns="http://www.w3.org/2000/svg" width="320" height="200" :viewBox="previewBox" preserveAspectRatio="xMidYMid meet">
                <rect x="-100000" y="-100000" width="200000" height="200000" fill="#ffffff" />
                <WhiteboardElements :elements="previewElements" />
            </svg>
        </div>
    </AppLayout>
</template>
