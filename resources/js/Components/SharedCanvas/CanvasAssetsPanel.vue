<script setup>
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import { useConfirm } from '@/Composables/useConfirm';
import { useToast } from '@/Composables/useToast';
import { CANVAS_ICONS, ICON_GROUPS } from './canvasIcons';
import { ASSET_DRAG_TYPE, RASTER_IMAGE, uploadCanvasAsset } from './assets';
import { ArrowUpTrayIcon, MagnifyingGlassIcon, TrashIcon } from '@heroicons/vue/24/outline';

/**
 * Canva-style "Elements" panel for whiteboards: built-in icons (available immediately) and a
 * picture library people add to (e.g. photos of a specific CCTV model). Drag an item onto
 * the board, or click it to drop it in the middle of the view. Emits `add` with
 * { kind: 'icon', key } or { kind: 'image', url, width, height }.
 */
const props = defineProps({
    canUpload: { type: Boolean, default: false },
});

const emit = defineEmits(['add']);

const { confirm } = useConfirm();
const { showError } = useToast();

const readTab = () => {
    try {
        return localStorage.getItem('ghelpdesk.elements.tab') === 'images' ? 'images' : 'icons';
    } catch {
        return 'icons';
    }
};
const tab = ref(readTab());
watch(tab, (value) => {
    try {
        localStorage.setItem('ghelpdesk.elements.tab', value);
    } catch {
        // Convenience only.
    }
});

const search = ref('');
const query = computed(() => search.value.trim().toLowerCase());

// ── Icons ────────────────────────────────────────────────────────────────────

const iconGroups = computed(() => ICON_GROUPS.map((group) => ({
    ...group,
    icons: Object.entries(CANVAS_ICONS)
        .filter(([, icon]) => icon.group === group.id)
        .filter(([key, icon]) => !query.value || `${icon.label} ${icon.keywords} ${key}`.toLowerCase().includes(query.value))
        .map(([key, icon]) => ({ key, ...icon })),
})).filter((group) => group.icons.length));

// ── Pictures ─────────────────────────────────────────────────────────────────

const assets = ref([]);
const loaded = ref(false);
const loading = ref(false);
const scope = ref('all');
const shareUploads = ref(true);
const uploading = ref(0);
const fileInput = ref(null);

const load = async () => {
    if (loading.value) return;
    loading.value = true;
    try {
        const { data } = await axios.get(route('canvas-assets.index'));
        assets.value = data.assets;
        loaded.value = true;
    } catch {
        showError('Could not load the picture library.');
    } finally {
        loading.value = false;
    }
};

watch(tab, (value) => {
    if (value === 'images' && !loaded.value) load();
}, { immediate: true });

const visibleAssets = computed(() => assets.value
    .filter((asset) => scope.value !== 'mine' || asset.is_mine)
    .filter((asset) => !query.value || `${asset.name} ${asset.category || ''}`.toLowerCase().includes(query.value)));

const onFilesPicked = async (event) => {
    const files = [...(event.target.files || [])];
    event.target.value = '';
    const pictures = files.filter((file) => RASTER_IMAGE.test(file.type));
    if (files.length && !pictures.length) {
        showError('Only PNG, JPG, GIF or WebP pictures can be added.');
        return;
    }
    uploading.value += pictures.length;
    for (const file of pictures) {
        try {
            const asset = await uploadCanvasAsset(file, { shared: shareUploads.value });
            assets.value = [asset, ...assets.value];
        } catch (error) {
            showError(error?.response?.data?.message || `Could not upload ${file.name}.`);
        } finally {
            uploading.value--;
        }
    }
};

const removeAsset = async (asset) => {
    const ok = await confirm({
        title: 'Remove from library',
        message: `Remove "${asset.name}" from the picture library? Boards that already use it keep showing it.`,
        confirmLabel: 'Remove',
        variant: 'danger',
    });
    if (!ok) return;
    try {
        await axios.delete(route('canvas-assets.destroy', asset.id));
        assets.value = assets.value.filter((item) => item.id !== asset.id);
    } catch (error) {
        showError(error?.response?.data?.message || 'Could not remove the picture.');
    }
};

// ── Drag & click ─────────────────────────────────────────────────────────────

const payload = (item) => (item.url
    ? { kind: 'image', url: item.url, width: item.width, height: item.height }
    : { kind: 'icon', key: item.key });

const onDragStart = (event, item) => {
    event.dataTransfer.setData(ASSET_DRAG_TYPE, JSON.stringify(payload(item)));
    event.dataTransfer.effectAllowed = 'copy';
};

const add = (item) => emit('add', payload(item));

defineExpose({ reload: load });
</script>

<template>
    <div class="flex h-full min-h-0 flex-col" data-testid="elements-panel">
        <div class="space-y-2 border-b border-gray-100 p-3 dark:border-gray-800">
            <div class="flex rounded-lg bg-gray-100 p-0.5 dark:bg-gray-800">
                <button
                    v-for="option in [{ value: 'icons', label: 'Icons' }, { value: 'images', label: 'Images' }]"
                    :key="option.value"
                    type="button"
                    class="flex-1 rounded-md px-3 py-1.5 text-xs font-bold"
                    :class="tab === option.value ? 'bg-white text-gray-900 shadow-sm dark:bg-gray-700 dark:text-gray-100' : 'text-gray-500 hover:text-gray-800 dark:text-gray-400'"
                    :data-testid="`elements-tab-${option.value}`"
                    @click="tab = option.value"
                >{{ option.label }}</button>
            </div>
            <div class="relative">
                <MagnifyingGlassIcon class="pointer-events-none absolute left-2.5 top-2 h-4 w-4 text-gray-400" />
                <input v-model="search" type="search" :placeholder="tab === 'icons' ? 'Search icons (e.g. cctv)…' : 'Search pictures…'" class="h-8 w-full rounded-lg border-gray-300 pl-8 text-sm dark:border-gray-600 dark:bg-gray-800" data-testid="elements-search">
            </div>
        </div>

        <!-- Icons -->
        <div v-if="tab === 'icons'" class="min-h-0 flex-1 space-y-4 overflow-y-auto p-3">
            <p v-if="!iconGroups.length" class="py-6 text-center text-xs text-gray-400">No icons match “{{ search }}”.</p>
            <section v-for="group in iconGroups" :key="group.id">
                <h3 class="mb-1.5 text-[11px] font-black uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ group.label }}</h3>
                <div class="grid grid-cols-4 gap-1">
                    <button
                        v-for="icon in group.icons"
                        :key="icon.key"
                        type="button"
                        draggable="true"
                        :title="`${icon.label} — drag onto the board or click to add`"
                        :aria-label="`Add ${icon.label}`"
                        :data-icon="icon.key"
                        class="flex h-[4.25rem] flex-col items-center justify-center gap-1 rounded-lg border border-transparent px-0.5 text-gray-700 hover:border-blue-200 hover:bg-blue-50 dark:text-gray-200 dark:hover:border-blue-800 dark:hover:bg-blue-900/30"
                        @dragstart="onDragStart($event, icon)"
                        @click="add(icon)"
                    >
                        <svg viewBox="0 0 24 24" class="h-7 w-7">
                            <path v-if="icon.d" :d="icon.d" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
                            <path v-if="icon.fill" :d="icon.fill" fill="currentColor" />
                        </svg>
                        <span class="line-clamp-2 w-full text-center text-[10px] leading-tight text-gray-500 dark:text-gray-400">{{ icon.label }}</span>
                    </button>
                </div>
            </section>
        </div>

        <!-- Pictures -->
        <div v-else class="flex min-h-0 flex-1 flex-col">
            <div v-if="canUpload" class="space-y-2 border-b border-gray-100 p-3 dark:border-gray-800">
                <button
                    type="button"
                    class="flex w-full items-center justify-center gap-1.5 rounded-lg border border-dashed border-blue-300 bg-blue-50 py-2 text-xs font-bold text-blue-700 hover:bg-blue-100 disabled:opacity-60 dark:border-blue-800 dark:bg-blue-900/20 dark:text-blue-300"
                    :disabled="uploading > 0"
                    data-testid="elements-upload"
                    @click="fileInput?.click()"
                >
                    <ArrowUpTrayIcon class="h-4 w-4" />
                    {{ uploading ? `Uploading ${uploading}…` : 'Upload pictures' }}
                </button>
                <input ref="fileInput" type="file" class="hidden" accept="image/png,image/jpeg,image/gif,image/webp" multiple data-testid="elements-upload-input" @change="onFilesPicked">
                <label class="flex items-center gap-2 text-xs text-gray-600 dark:text-gray-300" title="Shared pictures appear in everyone's library; otherwise only you see it">
                    <input v-model="shareUploads" type="checkbox" class="rounded border-gray-300 text-blue-600 dark:border-gray-600" data-testid="elements-share">
                    Share new uploads with everyone
                </label>
            </div>
            <div class="flex items-center gap-1 px-3 pt-3">
                <button
                    v-for="option in [{ value: 'all', label: 'All' }, { value: 'mine', label: 'Mine' }]"
                    :key="option.value"
                    type="button"
                    class="rounded-full px-2.5 py-1 text-[11px] font-bold"
                    :class="scope === option.value ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-300'"
                    @click="scope = option.value"
                >{{ option.label }}</button>
            </div>
            <div class="min-h-0 flex-1 overflow-y-auto p-3">
                <p v-if="loading && !loaded" class="py-6 text-center text-xs text-gray-400">Loading pictures…</p>
                <p v-else-if="!visibleAssets.length" class="py-6 text-center text-xs text-gray-400">
                    {{ assets.length ? 'No pictures match.' : (canUpload ? 'No pictures yet — upload one (a CCTV model, a store front, a device…) and it stays here for every board.' : 'No pictures in the library yet.') }}
                </p>
                <div v-else class="grid grid-cols-2 gap-2">
                    <div
                        v-for="asset in visibleAssets"
                        :key="asset.id"
                        class="group relative overflow-hidden rounded-lg border border-gray-200 bg-white hover:border-blue-300 dark:border-gray-700 dark:bg-gray-800"
                        data-testid="elements-picture"
                    >
                        <button
                            type="button"
                            draggable="true"
                            class="block w-full cursor-grab active:cursor-grabbing"
                            :title="`${asset.name}${asset.creator ? ` · by ${asset.creator}` : ''} — drag onto the board or click to add`"
                            :aria-label="`Add ${asset.name}`"
                            @dragstart="onDragStart($event, asset)"
                            @click="add(asset)"
                        >
                            <span class="flex h-20 items-center justify-center bg-[repeating-conic-gradient(#f1f5f9_0_25%,#ffffff_0_50%)] bg-[length:12px_12px] p-1 dark:bg-gray-900">
                                <img :src="asset.url" :alt="asset.name" class="max-h-full max-w-full object-contain" loading="lazy" draggable="false">
                            </span>
                            <span class="block truncate px-1.5 py-1 text-left text-[11px] font-semibold text-gray-700 dark:text-gray-200">{{ asset.name }}</span>
                        </button>
                        <span v-if="!asset.shared" class="pointer-events-none absolute left-1 top-1 rounded bg-gray-900/70 px-1 text-[9px] font-bold uppercase text-white">Only me</span>
                        <button
                            v-if="asset.can_manage"
                            type="button"
                            title="Remove from library"
                            :aria-label="`Remove ${asset.name} from library`"
                            class="absolute right-1 top-1 hidden rounded-full bg-white/90 p-1.5 text-red-600 shadow hover:bg-red-50 group-hover:block dark:bg-gray-800/90"
                            @click.stop="removeAsset(asset)"
                        >
                            <TrashIcon class="h-3.5 w-3.5" />
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <p class="border-t border-gray-100 px-3 py-2 text-[11px] leading-snug text-gray-400 dark:border-gray-800">
            Drag onto the board, or click to drop it in the middle. You can also paste a picture (Ctrl+V) or drop a file on the board.
        </p>
    </div>
</template>
