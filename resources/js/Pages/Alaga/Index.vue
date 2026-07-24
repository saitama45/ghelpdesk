<script setup>
import { ref, computed } from 'vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Autocomplete from '@/Components/Autocomplete.vue';
import { useToast } from '@/Composables/useToast.js';
import {
    ClipboardDocumentCheckIcon, PlusIcon, XMarkIcon, EyeIcon,
    ShieldCheckIcon, ExclamationTriangleIcon, CheckBadgeIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    assessments: { type: Array, default: () => [] },
    average: { type: Number, default: 0 },
    assessedCount: { type: Number, default: 0 },
    canCreate: { type: Boolean, default: false },
    stores: { type: Array, default: () => [] },
    inspectors: { type: Array, default: () => [] },
    checklist: { type: Array, default: () => [] },
});

const page = usePage();
const { showError } = useToast();
const accent = computed(() => page.props.departmentContext?.accent || '#0b948c');

/* ---- score helpers (scale /4.0) ---- */
function statusForScore(score) {
    if (score >= 3.5) return 'Excellent';
    if (score >= 3) return 'Good';
    return 'Fair';
}
function statusClasses(status) {
    switch (status) {
        case 'Excellent': return 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/30';
        case 'Good': return 'bg-blue-50 text-blue-700 ring-blue-200 dark:bg-blue-500/10 dark:text-blue-300 dark:ring-blue-500/30';
        default: return 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/30';
    }
}
function barColor(score) {
    if (score >= 3.5) return '#10b981';
    if (score >= 3) return '#3b82f6';
    return '#f59e0b';
}
const pct = (score) => `${Math.max(0, Math.min(100, (Number(score) / 4) * 100)).toFixed(0)}%`;

/* ---- distribution ---- */
const distribution = computed(() => {
    const d = { Excellent: 0, Good: 0, Fair: 0 };
    for (const a of props.assessments) {
        d[a.status] = (d[a.status] || 0) + 1;
    }
    return d;
});

/* ---- detail modal ---- */
const detail = ref(null);
function openDetail(a) { detail.value = a; }
function closeDetail() { detail.value = null; }

/* ---- create modal ---- */
const showCreate = ref(false);
const today = new Date().toISOString().slice(0, 10);

const form = useForm({
    store_id: '',
    inspector_id: '',
    assessment_date: today,
    next_review: '',
    asset_scores: [],
    checklist: [],
    observations: '',
    recommendations: '',
});

/** Autocomplete option lists (single-select — never a native <select>). */
const storeOptions = computed(() =>
    props.stores.map((s) => ({ value: s.id, label: s.name + (s.brand ? ' · ' + s.brand : '') + (s.code ? ' (' + s.code + ')' : '') }))
);
const inspectorOptions = computed(() => [
    { value: '', label: 'Me (default)' },
    ...props.inspectors.map((i) => ({ value: i.id, label: i.name })),
]);

function openCreate() {
    form.reset();
    form.assessment_date = today;
    form.asset_scores = [];
    form.checklist = props.checklist.map((c) => ({ ...c, finding: '', score: 3 }));
    assetSource.value = 'none';
    showCreate.value = true;
}
function closeCreate() { showCreate.value = false; }

/**
 * Equipment rows come ONLY from the store's real inventory (Assets + Stock In
 * units posted to that store code). A store with no recorded stock lists no
 * equipment at all and cannot be assessed — we never substitute a generic list,
 * since that would score equipment inventory has no record of.
 */
const assetSource = ref('none');   // none | inventory | empty | error
const loadingAssets = ref(false);

async function loadStoreAssets(storeId) {
    form.asset_scores = [];
    if (!storeId) { assetSource.value = 'none'; return; }
    loadingAssets.value = true;
    try {
        const { data } = await window.axios.get(route('alaga.store-assets', storeId));
        if (data.assets && data.assets.length) {
            form.asset_scores = data.assets.map((a) => ({ ...a, score: 3 }));
            assetSource.value = 'inventory';
        } else {
            assetSource.value = 'empty';
        }
    } catch (e) {
        assetSource.value = 'error';
        showError('Could not load this store\'s inventory.');
    } finally {
        loadingAssets.value = false;
    }
}

/** No equipment on record → nothing to score, so the assessment is blocked. */
const canSubmit = computed(() => !!form.store_id && form.asset_scores.length > 0 && !loadingAssets.value);

const projectedOverall = computed(() => {
    if (!form.asset_scores.length) return 0;
    const sum = form.asset_scores.reduce((t, s) => t + Number(s.score || 0), 0);
    return Math.round((sum / form.asset_scores.length) * 100) / 100;
});

function submit() {
    form.post(route('alaga.store'), {
        preserveScroll: true,
        // No success toast here — AppLayout already toasts the controller's
        // flash('success'), and firing one here duplicates it.
        onSuccess: () => { showCreate.value = false; },
        onError: () => showError('Please review the assessment details.'),
    });
}
</script>

<template>
    <Head title="ALAGA Asset Assessment" />
    <AppLayout title="ALAGA Asset Assessment" content-class="w-full max-w-none px-2 sm:px-4 lg:px-6">
        <div class="py-6 space-y-6">

            <!-- Hero -->
            <div class="rounded-2xl p-6 text-white shadow-sm"
                 :style="{ background: `linear-gradient(135deg, ${accent}, #0f172a)` }">
                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                    <div class="flex items-start gap-3">
                        <div class="h-11 w-11 rounded-xl bg-white/15 flex items-center justify-center shrink-0">
                            <ClipboardDocumentCheckIcon class="h-6 w-6" />
                        </div>
                        <div>
                            <h1 class="text-xl font-black tracking-tight">ALAGA Asset Assessment</h1>
                            <p class="text-sm text-white/70 mt-0.5 max-w-xl">
                                TAS-led store IT-asset condition scorecard — equipment scores, inspection
                                checklist, and recommendations on a 4.0 scale.
                            </p>
                        </div>
                    </div>
                    <button v-if="canCreate" @click="openCreate"
                            class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-white/15 hover:bg-white/25 text-sm font-bold transition-colors self-start">
                        <PlusIcon class="h-4 w-4" /> New Assessment
                    </button>
                </div>
            </div>

            <!-- Summary cards -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 dark:bg-gray-800 dark:border-gray-700">
                    <p class="text-[10px] font-black uppercase tracking-wider text-gray-400">Average Score</p>
                    <p class="text-3xl font-black mt-1" :style="{ color: barColor(average) }">
                        {{ Number(average).toFixed(2) }}<span class="text-base text-gray-400 font-bold"> / 4.0</span>
                    </p>
                </div>
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 dark:bg-gray-800 dark:border-gray-700">
                    <p class="text-[10px] font-black uppercase tracking-wider text-gray-400">Stores Assessed</p>
                    <p class="text-3xl font-black mt-1 text-gray-700 dark:text-gray-200">{{ assessedCount }}</p>
                </div>
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 dark:bg-gray-800 dark:border-gray-700">
                    <p class="text-[10px] font-black uppercase tracking-wider text-emerald-500 flex items-center gap-1">
                        <CheckBadgeIcon class="h-3.5 w-3.5" /> Excellent
                    </p>
                    <p class="text-3xl font-black mt-1 text-emerald-600 dark:text-emerald-400">{{ distribution.Excellent }}</p>
                </div>
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 dark:bg-gray-800 dark:border-gray-700">
                    <p class="text-[10px] font-black uppercase tracking-wider text-amber-500 flex items-center gap-1">
                        <ExclamationTriangleIcon class="h-3.5 w-3.5" /> Needs Attention (Fair)
                    </p>
                    <p class="text-3xl font-black mt-1 text-amber-600 dark:text-amber-400">{{ distribution.Fair }}</p>
                </div>
            </div>

            <!-- Assessments table -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 dark:bg-gray-800 dark:border-gray-700 overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center gap-2">
                    <ShieldCheckIcon class="h-5 w-5 text-gray-400" />
                    <h2 class="text-sm font-black uppercase tracking-wider text-gray-600 dark:text-gray-200">Assessment Records</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-[10px] font-black uppercase tracking-wider text-gray-400 border-b border-gray-100 dark:border-gray-700">
                                <th class="px-5 py-3">Store</th>
                                <th class="px-4 py-3">Brand</th>
                                <th class="px-4 py-3">Class</th>
                                <th class="px-4 py-3">Inspector</th>
                                <th class="px-4 py-3">Assessed</th>
                                <th class="px-4 py-3 w-48">Overall</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3">Next Review</th>
                                <th class="px-4 py-3 text-right">View</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50 dark:divide-gray-700/60">
                            <tr v-for="a in assessments" :key="a.id" class="hover:bg-gray-50/60 dark:hover:bg-gray-700/30">
                                <td class="px-5 py-3">
                                    <p class="font-bold text-gray-800 dark:text-gray-100">{{ a.store || '—' }}</p>
                                    <p class="text-[11px] text-gray-400">{{ a.store_code }}</p>
                                </td>
                                <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ a.brand || '—' }}</td>
                                <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ a.class || '—' }}</td>
                                <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ a.inspector || '—' }}</td>
                                <td class="px-4 py-3 text-gray-500 whitespace-nowrap">{{ a.assessment_date }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2">
                                        <div class="flex-1 h-2 rounded-full bg-gray-100 dark:bg-gray-700 overflow-hidden min-w-[80px]">
                                            <div class="h-full rounded-full" :style="{ width: pct(a.overall_score), background: barColor(a.overall_score) }"></div>
                                        </div>
                                        <span class="text-xs font-black tabular-nums" :style="{ color: barColor(a.overall_score) }">{{ a.overall_score.toFixed(2) }}</span>
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex px-2.5 py-1 rounded-full text-[10px] font-black uppercase tracking-wide ring-1 ring-inset" :class="statusClasses(a.status)">{{ a.status }}</span>
                                </td>
                                <td class="px-4 py-3 text-gray-500 whitespace-nowrap">{{ a.next_review || '—' }}</td>
                                <td class="px-4 py-3 text-right">
                                    <button @click="openDetail(a)"
                                            class="inline-flex items-center justify-center h-8 w-8 rounded-full border border-gray-200 text-gray-500 hover:text-gray-800 hover:bg-gray-50 transition-colors dark:border-gray-600 dark:hover:bg-gray-700"
                                            title="View assessment">
                                        <EyeIcon class="h-4 w-4" />
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="!assessments.length">
                                <td colspan="9" class="px-5 py-12 text-center text-sm text-gray-400">
                                    No ALAGA assessments recorded yet.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Detail modal -->
        <transition enter-active-class="ease-out duration-200" enter-from-class="opacity-0" enter-to-class="opacity-100"
                    leave-active-class="ease-in duration-150" leave-from-class="opacity-100" leave-to-class="opacity-0">
            <div v-if="detail" class="fixed inset-0 z-50 flex items-start justify-center p-4 sm:p-8 bg-black/50 overflow-y-auto" @click.self="closeDetail">
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-3xl my-4">
                    <div class="flex items-start justify-between px-6 py-4 border-b border-gray-100 dark:border-gray-700">
                        <div>
                            <h3 class="text-lg font-black text-gray-800 dark:text-gray-100">{{ detail.store }}</h3>
                            <p class="text-xs text-gray-400">{{ detail.store_code }} · {{ detail.brand }} · {{ detail.location }}</p>
                        </div>
                        <button @click="closeDetail" class="h-8 w-8 rounded-full flex items-center justify-center text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700">
                            <XMarkIcon class="h-5 w-5" />
                        </button>
                    </div>
                    <div class="px-6 py-5 space-y-6 max-h-[70vh] overflow-y-auto">
                        <!-- Overall band -->
                        <div class="flex items-center gap-4 p-4 rounded-xl bg-gray-50 dark:bg-gray-700/40">
                            <div class="text-center">
                                <p class="text-4xl font-black" :style="{ color: barColor(detail.overall_score) }">{{ detail.overall_score.toFixed(2) }}</p>
                                <p class="text-[10px] font-bold text-gray-400 uppercase">/ 4.0</p>
                            </div>
                            <div class="flex-1">
                                <span class="inline-flex px-2.5 py-1 rounded-full text-[10px] font-black uppercase tracking-wide ring-1 ring-inset" :class="statusClasses(detail.status)">{{ detail.status }}</span>
                                <div class="grid grid-cols-2 gap-x-6 gap-y-1 mt-3 text-xs text-gray-500">
                                    <p><span class="font-bold text-gray-600 dark:text-gray-300">Inspector:</span> {{ detail.inspector || '—' }}</p>
                                    <p><span class="font-bold text-gray-600 dark:text-gray-300">Assessed:</span> {{ detail.assessment_date }}</p>
                                    <p><span class="font-bold text-gray-600 dark:text-gray-300">Next review:</span> {{ detail.next_review || '—' }}</p>
                                    <p><span class="font-bold text-gray-600 dark:text-gray-300">Class:</span> {{ detail.class || '—' }}</p>
                                </div>
                            </div>
                        </div>

                        <!-- Equipment scores -->
                        <div v-if="detail.asset_scores.length">
                            <h4 class="text-xs font-black uppercase tracking-wider text-gray-500 mb-2">Equipment Scores</h4>
                            <div class="space-y-2">
                                <div v-for="s in detail.asset_scores" :key="s.asset_id || s.category" class="flex items-center gap-3">
                                    <span class="w-56 shrink-0">
                                        <span class="block truncate text-sm text-gray-600 dark:text-gray-300" :title="s.category">{{ s.category }}</span>
                                        <span v-if="s.group || s.units || s.serial_no" class="block truncate text-[10px] text-gray-400" :title="s.serial_no || ''">
                                            {{ s.group }}<template v-if="s.group && s.units"> · </template><template v-if="s.units">{{ s.units }} unit(s)</template><template v-if="s.serial_no"> · SN {{ s.serial_no }}</template>
                                        </span>
                                    </span>
                                    <div class="flex-1 h-2.5 rounded-full bg-gray-100 dark:bg-gray-700 overflow-hidden">
                                        <div class="h-full rounded-full" :style="{ width: pct(s.score), background: barColor(s.score) }"></div>
                                    </div>
                                    <span class="w-10 text-right text-xs font-black tabular-nums" :style="{ color: barColor(s.score) }">{{ Number(s.score).toFixed(1) }}</span>
                                </div>
                            </div>
                        </div>

                        <!-- Checklist -->
                        <div v-if="detail.checklist.length">
                            <h4 class="text-xs font-black uppercase tracking-wider text-gray-500 mb-2">Inspection Checklist</h4>
                            <div class="overflow-x-auto rounded-lg border border-gray-100 dark:border-gray-700">
                                <table class="w-full text-xs">
                                    <thead>
                                        <tr class="text-left text-[10px] font-black uppercase tracking-wider text-gray-400 bg-gray-50 dark:bg-gray-700/40">
                                            <th class="px-3 py-2">Parameter</th>
                                            <th class="px-3 py-2">Standard</th>
                                            <th class="px-3 py-2">Finding</th>
                                            <th class="px-3 py-2 text-right">Score</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-50 dark:divide-gray-700/60">
                                        <tr v-for="(c, i) in detail.checklist" :key="i">
                                            <td class="px-3 py-2 font-bold text-gray-700 dark:text-gray-200">{{ c.parameter }}</td>
                                            <td class="px-3 py-2 text-gray-500">{{ c.standard }}</td>
                                            <td class="px-3 py-2 text-gray-500">{{ c.finding || '—' }}</td>
                                            <td class="px-3 py-2 text-right font-black tabular-nums" :style="{ color: c.score != null ? barColor(c.score) : '#9ca3af' }">
                                                {{ c.score != null ? Number(c.score).toFixed(1) : '—' }}
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Notes -->
                        <div v-if="detail.observations" class="rounded-xl bg-amber-50 dark:bg-amber-500/10 p-4">
                            <h4 class="text-[10px] font-black uppercase tracking-wider text-amber-600 mb-1">Observations</h4>
                            <p class="text-sm text-gray-700 dark:text-gray-200 whitespace-pre-line">{{ detail.observations }}</p>
                        </div>
                        <div v-if="detail.recommendations" class="rounded-xl bg-blue-50 dark:bg-blue-500/10 p-4">
                            <h4 class="text-[10px] font-black uppercase tracking-wider text-blue-600 mb-1">Recommendations</h4>
                            <p class="text-sm text-gray-700 dark:text-gray-200 whitespace-pre-line">{{ detail.recommendations }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </transition>

        <!-- Create modal -->
        <transition enter-active-class="ease-out duration-200" enter-from-class="opacity-0" enter-to-class="opacity-100"
                    leave-active-class="ease-in duration-150" leave-from-class="opacity-100" leave-to-class="opacity-0">
            <div v-if="showCreate" class="fixed inset-0 z-50 flex items-start justify-center p-4 sm:p-8 bg-black/50 overflow-y-auto" @click.self="closeCreate">
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-3xl my-4">
                    <div class="flex items-start justify-between px-6 py-4 border-b border-gray-100 dark:border-gray-700">
                        <div>
                            <h3 class="text-lg font-black text-gray-800 dark:text-gray-100">New ALAGA Assessment</h3>
                            <p class="text-xs text-gray-400">Score each equipment category and inspection parameter on a 4.0 scale.</p>
                        </div>
                        <button @click="closeCreate" class="h-8 w-8 rounded-full flex items-center justify-center text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700">
                            <XMarkIcon class="h-5 w-5" />
                        </button>
                    </div>
                    <form @submit.prevent="submit" class="px-6 py-5 space-y-5 max-h-[72vh] overflow-y-auto">
                        <!-- Meta -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Store</label>
                                <Autocomplete
                                    v-model="form.store_id"
                                    :options="storeOptions"
                                    placeholder="Search store…"
                                    @update:modelValue="loadStoreAssets"
                                />
                                <p v-if="form.errors.store_id" class="text-xs text-red-500 mt-1">{{ form.errors.store_id }}</p>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Inspector</label>
                                <Autocomplete
                                    v-model="form.inspector_id"
                                    :options="inspectorOptions"
                                    placeholder="Search inspector…"
                                />
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Assessment Date</label>
                                <input v-model="form.assessment_date" type="date" class="w-full border-gray-300 rounded-lg text-sm dark:bg-gray-900 dark:border-gray-600" />
                                <p v-if="form.errors.assessment_date" class="text-xs text-red-500 mt-1">{{ form.errors.assessment_date }}</p>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Next Review</label>
                                <input v-model="form.next_review" type="date" class="w-full border-gray-300 rounded-lg text-sm dark:bg-gray-900 dark:border-gray-600" />
                                <p class="text-[11px] text-gray-400 mt-1">Defaults to +4 months if left blank.</p>
                            </div>
                        </div>

                        <!-- Equipment scores (from the store's real inventory) -->
                        <div>
                            <div class="flex items-center justify-between mb-2">
                                <h4 class="text-xs font-black uppercase tracking-wider text-gray-500">Equipment Scores</h4>
                                <span class="text-xs font-bold text-gray-400">Projected: <span :style="{ color: barColor(projectedOverall) }">{{ projectedOverall.toFixed(2) }}</span> / 4.0</span>
                            </div>

                            <p v-if="loadingAssets" class="mb-2 text-xs text-gray-400">Loading this store's deployed equipment…</p>
                            <p v-else-if="assetSource === 'inventory'" class="mb-2 rounded-lg bg-emerald-50 px-3 py-2 text-[11px] font-bold text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">
                                Equipment pulled from Inventory — {{ form.asset_scores.length }} asset(s) posted to this store (Assets + Stock In).
                            </p>
                            <div v-else-if="assetSource === 'empty'" class="mb-2 rounded-lg border border-dashed border-amber-300 bg-amber-50 px-4 py-5 text-center dark:border-amber-500/40 dark:bg-amber-500/10">
                                <p class="text-xs font-black uppercase tracking-wider text-amber-700 dark:text-amber-300">No equipment on record</p>
                                <p class="mt-1 text-[11px] text-amber-700/80 dark:text-amber-300/80">
                                    Inventory has no posted stock for this store, so there is nothing to assess.
                                    Record the equipment in Stock In first.
                                </p>
                            </div>
                            <p v-else-if="assetSource === 'error'" class="mb-2 rounded-lg bg-red-50 px-3 py-2 text-[11px] font-bold text-red-700 dark:bg-red-500/10 dark:text-red-300">
                                Could not load this store's inventory. Try selecting the store again.
                            </p>
                            <p v-else class="mb-2 text-[11px] text-gray-400">Select a store to load its deployed equipment from Inventory.</p>

                            <div v-if="form.asset_scores.length" class="space-y-3">
                                <div v-for="(s, idx) in form.asset_scores" :key="s.asset_id || s.category" class="flex items-center gap-3">
                                    <span class="w-56 shrink-0">
                                        <span class="block truncate text-sm text-gray-600 dark:text-gray-300" :title="s.category">{{ s.category }}</span>
                                        <span v-if="s.group || s.units" class="block text-[10px] text-gray-400">
                                            {{ s.group }}<template v-if="s.group && s.units"> · </template><template v-if="s.units">{{ s.units }} unit(s)</template>
                                        </span>
                                    </span>
                                    <input v-model.number="form.asset_scores[idx].score" type="range" min="0" max="4" step="0.5" class="flex-1 accent-[color:var(--dept-accent,#0b948c)]" />
                                    <span class="w-10 text-right text-xs font-black tabular-nums" :style="{ color: barColor(s.score) }">{{ Number(s.score).toFixed(1) }}</span>
                                </div>
                            </div>
                            <p v-if="form.errors.asset_scores" class="text-xs text-red-500 mt-1">{{ form.errors.asset_scores }}</p>
                        </div>

                        <!-- Checklist -->
                        <div>
                            <h4 class="text-xs font-black uppercase tracking-wider text-gray-500 mb-2">Inspection Checklist</h4>
                            <div class="space-y-3">
                                <div v-for="(c, idx) in form.checklist" :key="idx" class="rounded-lg border border-gray-100 dark:border-gray-700 p-3">
                                    <div class="flex items-center justify-between gap-3">
                                        <div>
                                            <p class="text-sm font-bold text-gray-700 dark:text-gray-200">{{ c.parameter }}</p>
                                            <p class="text-[11px] text-gray-400">{{ c.standard }}</p>
                                        </div>
                                        <div class="flex items-center gap-2 shrink-0">
                                            <input v-model.number="form.checklist[idx].score" type="range" min="0" max="4" step="0.5" class="w-24 accent-[color:var(--dept-accent,#0b948c)]" />
                                            <span class="w-8 text-right text-xs font-black tabular-nums" :style="{ color: barColor(c.score) }">{{ Number(c.score).toFixed(1) }}</span>
                                        </div>
                                    </div>
                                    <input v-model="form.checklist[idx].finding" type="text" placeholder="Finding / note (optional)"
                                           class="mt-2 w-full border-gray-200 rounded-lg text-xs dark:bg-gray-900 dark:border-gray-600" />
                                </div>
                            </div>
                        </div>

                        <!-- Notes -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Observations</label>
                                <textarea v-model="form.observations" rows="3" class="w-full border-gray-300 rounded-lg text-sm dark:bg-gray-900 dark:border-gray-600"></textarea>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Recommendations</label>
                                <textarea v-model="form.recommendations" rows="3" class="w-full border-gray-300 rounded-lg text-sm dark:bg-gray-900 dark:border-gray-600"></textarea>
                            </div>
                        </div>

                        <div class="flex items-center justify-end gap-2 pt-2 border-t border-gray-100 dark:border-gray-700">
                            <button type="button" @click="closeCreate" class="px-4 py-2 text-sm font-bold text-gray-500 hover:text-gray-700 dark:text-gray-300">Cancel</button>
                            <button type="submit" :disabled="form.processing || !canSubmit"
                                    :title="canSubmit ? '' : 'Select a store that has equipment recorded in Inventory'"
                                    class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-white text-sm font-bold transition-colors disabled:cursor-not-allowed disabled:opacity-50"
                                    :style="{ background: accent }">
                                <ClipboardDocumentCheckIcon class="h-4 w-4" /> Record Assessment
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </transition>
    </AppLayout>
</template>
