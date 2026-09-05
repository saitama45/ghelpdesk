<template>
    <div class="flex flex-col h-full">
        <!-- Header -->
        <div v-if="!compact" class="flex items-center justify-between pb-4 border-b border-gray-100 dark:border-gray-700">
            <div>
                <div class="flex items-center gap-2">
                    <h4 class="text-sm font-bold uppercase tracking-wider text-gray-800 dark:text-gray-100">
                        Vendor Documents
                    </h4>
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300">
                        {{ filteredDocuments.length }}
                    </span>
                </div>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                    Accreditation &amp; compliance files submitted through the vendor portal
                </p>
            </div>

            <span
                v-if="vendor?.has_portal_access"
                class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-300 dark:border-emerald-800/50"
            >
                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                Portal Account
            </span>
        </div>

        <!-- Filter & Search Bar -->
        <div v-if="documents.length && !compact" class="pt-3 pb-2 space-y-2.5">
            <div class="relative">
                <input
                    v-model="searchQuery"
                    type="text"
                    placeholder="Search documents by name or type..."
                    class="block w-full pl-9 pr-3 py-1.5 text-xs rounded-lg border border-gray-200 bg-white placeholder-gray-400 shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 dark:bg-gray-800 dark:border-gray-700 dark:text-gray-200"
                />
                <div class="absolute inset-y-0 left-0 pl-2.5 flex items-center pointer-events-none text-gray-400">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                </div>
                <button
                    v-if="searchQuery"
                    type="button"
                    @click="searchQuery = ''"
                    class="absolute inset-y-0 right-0 pr-2.5 flex items-center text-gray-400 hover:text-gray-600 dark:hover:text-gray-200"
                >
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <div class="flex items-center gap-1 overflow-x-auto pb-1 text-xs">
                <button
                    v-for="tab in filterTabs"
                    :key="tab.value"
                    type="button"
                    @click="activeFilter = tab.value"
                    :class="activeFilter === tab.value
                        ? 'bg-blue-600 text-white font-bold shadow-xs'
                        : 'bg-white text-gray-600 hover:bg-gray-100 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700 border border-gray-200 dark:border-gray-700'"
                    class="px-2.5 py-1 rounded-md transition-colors shrink-0 flex items-center gap-1.5"
                >
                    <span>{{ tab.label }}</span>
                    <span
                        :class="activeFilter === tab.value ? 'bg-white/25 text-white' : 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400'"
                        class="px-1.5 py-0.2 rounded-full text-[10px]"
                    >
                        {{ tab.count }}
                    </span>
                </button>
            </div>
        </div>

        <!-- Document List -->
        <div
            class="flex-1 overflow-y-auto space-y-2.5 pr-1 py-1"
            :class="compact ? 'max-h-[260px]' : 'max-h-[440px] sm:max-h-[520px]'"
        >
            <!-- Loading -->
            <div v-if="loading" class="space-y-2.5">
                <div v-for="n in 3" :key="n" class="rounded-xl border border-gray-200/90 bg-white p-3 dark:bg-gray-800/90 dark:border-gray-700">
                    <div class="flex items-start gap-3 animate-pulse">
                        <div class="w-12 h-12 rounded-lg bg-gray-200 dark:bg-gray-700 shrink-0"></div>
                        <div class="flex-1 space-y-2 pt-1">
                            <div class="h-2.5 w-2/3 rounded bg-gray-200 dark:bg-gray-700"></div>
                            <div class="h-2 w-1/2 rounded bg-gray-100 dark:bg-gray-700/60"></div>
                            <div class="h-2 w-1/3 rounded bg-gray-100 dark:bg-gray-700/60"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Could not load -->
            <div v-else-if="error" class="py-10 px-4 text-center rounded-xl border border-dashed border-red-200 dark:border-red-900/50">
                <svg class="mx-auto w-9 h-9 text-red-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v2m0 4h.01M5.07 19h13.86a2 2 0 001.74-3L13.74 4a2 2 0 00-3.48 0L3.33 16a2 2 0 001.74 3z" />
                </svg>
                <p class="mt-2 text-xs font-semibold text-red-700 dark:text-red-300">{{ error }}</p>
            </div>

            <template v-else>
                <div
                    v-for="doc in filteredDocuments"
                    :key="doc.id"
                    class="group relative rounded-xl border border-gray-200/90 bg-white p-3 shadow-xs hover:border-blue-300 hover:shadow-md transition-all dark:bg-gray-800/90 dark:border-gray-700 dark:hover:border-blue-500/50"
                >
                    <div class="flex items-start gap-3">
                        <!-- Thumbnail / Type Icon -->
                        <div class="shrink-0">
                            <div
                                v-if="doc.file_type === 'image' && doc.file_url"
                                @click="previewImage(doc)"
                                class="relative w-12 h-12 rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden bg-gray-100 dark:bg-gray-900 cursor-pointer group/thumb shadow-xs"
                                title="Click to preview image"
                            >
                                <img :src="doc.file_url" :alt="doc.name" class="w-full h-full object-cover group-hover/thumb:scale-110 transition-transform duration-200" />
                                <div class="absolute inset-0 bg-black/40 opacity-0 group-hover/thumb:opacity-100 flex items-center justify-center transition-opacity text-white">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                    </svg>
                                </div>
                            </div>

                            <div
                                v-else-if="doc.file_type === 'pdf'"
                                @click="openPdf(doc)"
                                class="w-12 h-12 rounded-lg bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-900/40 flex flex-col items-center justify-center text-red-600 dark:text-red-400 cursor-pointer hover:bg-red-100/80 transition-colors shadow-xs"
                                title="Click to open PDF in new tab"
                            >
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                                </svg>
                                <span class="text-[9px] font-black uppercase tracking-wider mt-0.5">PDF</span>
                            </div>

                            <div
                                v-else
                                class="w-12 h-12 rounded-lg bg-blue-50 dark:bg-blue-950/40 border border-blue-200 dark:border-blue-900/40 flex flex-col items-center justify-center text-blue-600 dark:text-blue-400 shadow-xs"
                            >
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                </svg>
                                <span class="text-[9px] font-black uppercase tracking-wider mt-0.5">{{ doc.extension || 'DOC' }}</span>
                            </div>
                        </div>

                        <!-- Details -->
                        <div class="flex-1 min-w-0">
                            <div class="flex items-start justify-between gap-1">
                                <h5
                                    class="text-xs font-bold text-gray-900 dark:text-gray-100 truncate"
                                    :class="doc.file_url ? 'hover:text-blue-600 dark:hover:text-blue-400 cursor-pointer' : ''"
                                    @click="openDefault(doc)"
                                    :title="doc.name"
                                >
                                    {{ doc.name }}
                                </h5>
                                <span :class="statusBadgeClass(doc.status)" class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold shrink-0">
                                    {{ doc.status }}
                                </span>
                            </div>

                            <p class="text-[11px] text-gray-500 dark:text-gray-400 truncate mt-0.5">
                                {{ doc.file_name }}
                            </p>

                            <div class="flex flex-wrap items-center gap-2 mt-1.5 text-[10px] text-gray-400 dark:text-gray-500">
                                <span class="font-medium text-gray-600 dark:text-gray-300">{{ doc.category }}</span>
                                <template v-if="doc.file_size">
                                    <span>•</span><span>{{ doc.file_size }}</span>
                                </template>
                                <template v-if="doc.uploaded_at">
                                    <span>•</span><span>{{ doc.uploaded_at }}</span>
                                </template>
                                <span v-if="doc.version > 1" class="px-1.5 py-0.5 rounded bg-gray-100 font-semibold text-gray-600 dark:bg-gray-700 dark:text-gray-300">
                                    v{{ doc.version }}
                                </span>
                            </div>

                            <!-- Validity, straight from the portal's upload form -->
                            <div v-if="doc.issued_date || doc.expiry_date" class="flex flex-wrap items-center gap-2 mt-1 text-[10px]">
                                <span v-if="doc.issued_date" class="text-gray-400 dark:text-gray-500">
                                    Issued <span class="font-medium text-gray-600 dark:text-gray-300">{{ doc.issued_date }}</span>
                                </span>
                                <span
                                    v-if="doc.expiry_date"
                                    :class="doc.is_expired
                                        ? 'bg-red-100 text-red-800 dark:bg-red-950/50 dark:text-red-300'
                                        : doc.is_expiring_soon
                                            ? 'bg-amber-100 text-amber-800 dark:bg-amber-950/50 dark:text-amber-300'
                                            : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'"
                                    class="px-1.5 py-0.5 rounded font-semibold"
                                >
                                    {{ doc.is_expired ? 'Expired' : 'Valid until' }} {{ doc.expiry_date }}
                                </span>
                            </div>

                            <p v-if="doc.reviewed_by" class="mt-1 text-[10px] text-gray-400 dark:text-gray-500">
                                Reviewed by <span class="font-medium text-gray-600 dark:text-gray-300">{{ doc.reviewed_by }}</span>
                                <span v-if="doc.reviewed_at"> · {{ doc.reviewed_at }}</span>
                            </p>
                            <p v-if="doc.review_remarks" class="mt-1 text-[10px] italic text-gray-500 dark:text-gray-400">
                                “{{ doc.review_remarks }}”
                            </p>
                        </div>
                    </div>

                    <!-- Accreditation decision on THIS document. Separate from
                         the vendor account's approval matrix. -->
                    <div
                        v-if="canReview && isPending(doc)"
                        class="mt-2.5 rounded-lg border border-amber-200 bg-amber-50/70 p-2.5 dark:border-amber-900/50 dark:bg-amber-900/20"
                    >
                        <template v-if="rejectingId !== doc.id">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <p class="text-[11px] font-semibold text-amber-900 dark:text-amber-200">
                                    Awaiting your review
                                </p>
                                <div class="flex items-center gap-1.5">
                                    <button
                                        type="button"
                                        @click="approve(doc)"
                                        :disabled="reviewingId === doc.id"
                                        class="inline-flex items-center gap-1 rounded-md bg-emerald-600 px-2.5 py-1 text-xs font-bold text-white transition-colors hover:bg-emerald-700 disabled:opacity-50"
                                    >
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                        </svg>
                                        <span>{{ reviewingId === doc.id ? 'Saving...' : 'Approve' }}</span>
                                    </button>
                                    <button
                                        type="button"
                                        @click="startReject(doc)"
                                        :disabled="reviewingId === doc.id"
                                        class="inline-flex items-center gap-1 rounded-md border border-red-300 bg-white px-2.5 py-1 text-xs font-bold text-red-700 transition-colors hover:bg-red-50 disabled:opacity-50 dark:bg-transparent dark:border-red-900/60 dark:text-red-300"
                                    >
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                        <span>Reject</span>
                                    </button>
                                </div>
                            </div>
                        </template>

                        <!-- A refusal has to say why: the vendor is shown it. -->
                        <template v-else>
                            <label class="block text-[10px] font-bold uppercase tracking-wider text-amber-900 dark:text-amber-200">
                                Reason for rejection <span class="text-red-500">*</span>
                            </label>
                            <textarea
                                v-model="rejectRemarks"
                                rows="2"
                                maxlength="1000"
                                placeholder="Tell the vendor what to correct and re-upload."
                                class="mt-1 block w-full rounded-lg border-gray-300 text-xs shadow-sm focus:border-red-500 focus:ring-red-500 dark:border-gray-600 dark:bg-gray-800"
                            ></textarea>
                            <div class="mt-2 flex items-center justify-end gap-1.5">
                                <button
                                    type="button"
                                    @click="cancelReject"
                                    class="rounded-md bg-white px-2.5 py-1 text-xs font-semibold text-gray-600 transition-colors hover:bg-gray-100 dark:bg-gray-800 dark:text-gray-300"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="button"
                                    @click="confirmReject(doc)"
                                    :disabled="reviewingId === doc.id"
                                    class="rounded-md bg-red-600 px-2.5 py-1 text-xs font-bold text-white transition-colors hover:bg-red-700 disabled:opacity-50"
                                >
                                    {{ reviewingId === doc.id ? 'Saving...' : 'Confirm rejection' }}
                                </button>
                            </div>
                        </template>
                    </div>

                    <!-- Action Toolbar -->
                    <div class="flex items-center justify-end gap-1.5 mt-2.5 pt-2 border-t border-gray-100 dark:border-gray-700/60">
                        <p v-if="!doc.file_url" class="mr-auto text-[10px] italic text-gray-400">
                            File unavailable from the vendor portal.
                        </p>

                        <template v-if="doc.file_url">
                            <button
                                v-if="doc.file_type === 'image'"
                                type="button"
                                @click="previewImage(doc)"
                                class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-semibold rounded-md bg-blue-50 text-blue-700 hover:bg-blue-100 hover:text-blue-800 transition-colors dark:bg-blue-900/30 dark:text-blue-300 dark:hover:bg-blue-900/50"
                                title="Preview image with zoom & pan"
                            >
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                </svg>
                                <span>Preview</span>
                            </button>

                            <button
                                v-else-if="doc.file_type === 'pdf'"
                                type="button"
                                @click="openPdf(doc)"
                                class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-semibold rounded-md bg-indigo-50 text-indigo-700 hover:bg-indigo-100 transition-colors dark:bg-indigo-900/30 dark:text-indigo-300 dark:hover:bg-indigo-900/50"
                                title="Open PDF in a new browser tab"
                            >
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                                </svg>
                                <span>Open in new tab</span>
                            </button>

                            <a
                                :href="doc.download_url"
                                class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-semibold rounded-md bg-gray-100 text-gray-700 hover:bg-gray-200 transition-colors dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600"
                                title="Download document file"
                            >
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                                </svg>
                                <span>Download</span>
                            </a>
                        </template>
                    </div>
                </div>

                <!-- Nothing uploaded yet -->
                <div
                    v-if="!documents.length"
                    class="py-12 px-4 text-center rounded-xl border border-dashed border-gray-200 dark:border-gray-700"
                >
                    <svg class="mx-auto w-10 h-10 text-gray-300 dark:text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 13h6m-3-3v6m5 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                    <p class="mt-2 text-xs font-semibold text-gray-600 dark:text-gray-300">No documents submitted yet</p>
                    <p class="text-[11px] text-gray-400 mt-0.5">
                        {{ vendor?.has_portal_access
                            ? 'This vendor has not uploaded any accreditation files in the portal.'
                            : 'Only vendors with a portal account can submit documents.' }}
                    </p>
                </div>

                <!-- Filtered everything out -->
                <div
                    v-else-if="!filteredDocuments.length"
                    class="py-12 px-4 text-center rounded-xl border border-dashed border-gray-200 dark:border-gray-700"
                >
                    <p class="text-xs font-semibold text-gray-600 dark:text-gray-300">No documents found</p>
                    <p class="text-[11px] text-gray-400 mt-0.5">Try clearing your search query or switching filters.</p>
                    <button
                        type="button"
                        @click="resetFilters"
                        class="mt-3 inline-flex items-center text-xs font-bold text-blue-600 hover:text-blue-700 dark:text-blue-400"
                    >
                        Reset filters
                    </button>
                </div>
            </template>
        </div>

        <!-- Footer Info -->
        <div v-if="!compact" class="pt-3 border-t border-gray-100 dark:border-gray-700 mt-auto flex items-center justify-between text-[11px] text-gray-400">
            <span>🔒 Stored securely in compliance repository</span>
            <span v-if="vendor?.code" class="font-mono">{{ vendor.code }}</span>
        </div>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue'

const props = defineProps({
    vendor: {
        type: Object,
        default: null,
    },
    // Fed by /vendors/{vendor}/documents — the rows linkportal wrote when the
    // vendor uploaded them at /vendor/documents.
    documents: {
        type: Array,
        default: () => [],
    },
    loading: {
        type: Boolean,
        default: false,
    },
    error: {
        type: String,
        default: '',
    },
    // Holds vendor-documents.approve — the portal's own permission for this
    // decision, which is NOT the vendor account's approval matrix.
    canReview: {
        type: Boolean,
        default: false,
    },
    // Id of the document currently being decided on, so its buttons can wait.
    reviewingId: {
        type: [Number, String],
        default: null,
    },
    // Inside the account approval modal the panel is the evidence for that
    // decision, so it drops its own chrome: no header, no search, no footer.
    compact: {
        type: Boolean,
        default: false,
    },
})

const emit = defineEmits(['preview-image', 'review'])

const searchQuery = ref('')
const activeFilter = ref('all')
const rejectingId = ref(null)
const rejectRemarks = ref('')

const documents = computed(() => props.documents ?? [])

const filterTabs = computed(() => {
    const all = documents.value

    return [
        { label: 'All', value: 'all', count: all.length },
        { label: 'Images', value: 'image', count: all.filter((d) => d.file_type === 'image').length },
        { label: 'PDFs', value: 'pdf', count: all.filter((d) => d.file_type === 'pdf').length },
        { label: 'Other', value: 'other', count: all.filter((d) => d.file_type !== 'image' && d.file_type !== 'pdf').length },
    ]
})

const filteredDocuments = computed(() => {
    let list = documents.value

    if (activeFilter.value === 'image') {
        list = list.filter((d) => d.file_type === 'image')
    } else if (activeFilter.value === 'pdf') {
        list = list.filter((d) => d.file_type === 'pdf')
    } else if (activeFilter.value === 'other') {
        list = list.filter((d) => d.file_type !== 'image' && d.file_type !== 'pdf')
    }

    const query = searchQuery.value.trim().toLowerCase()

    if (query) {
        list = list.filter((d) => [d.name, d.file_name, d.category]
            .some((field) => (field || '').toLowerCase().includes(query)))
    }

    return list
})

const resetFilters = () => {
    searchQuery.value = ''
    activeFilter.value = 'all'
}

const statusBadgeClass = (status) => {
    switch ((status || '').toLowerCase()) {
        case 'approved':
        case 'verified':
            return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-300'
        case 'pending':
        case 'under review':
            return 'bg-amber-100 text-amber-800 dark:bg-amber-950/50 dark:text-amber-300'
        case 'rejected':
            return 'bg-red-100 text-red-800 dark:bg-red-950/50 dark:text-red-300'
        default:
            return 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-300'
    }
}

const isPending = (doc) => (doc.status || '').toLowerCase() === 'pending'

const approve = (doc) => emit('review', { document: doc, action: 'approved' })

const startReject = (doc) => {
    rejectingId.value = doc.id
    rejectRemarks.value = ''
}

const cancelReject = () => {
    rejectingId.value = null
    rejectRemarks.value = ''
}

const confirmReject = (doc) => {
    if (!rejectRemarks.value.trim()) {
        return
    }

    emit('review', { document: doc, action: 'rejected', remarks: rejectRemarks.value.trim() })
    cancelReject()
}

const previewImage = (doc) => {
    if (!doc.file_url) {
        return
    }

    emit('preview-image', {
        title: doc.name,
        fileName: doc.file_name,
        imageUrl: doc.file_url,
    })
}

const openPdf = (doc) => {
    if (!doc.file_url) {
        return
    }

    window.open(doc.file_url, '_blank', 'noopener,noreferrer')
}

/** Clicking the title does whatever that file type supports. */
const openDefault = (doc) => {
    if (doc.file_type === 'image') {
        previewImage(doc)
    } else if (doc.file_type === 'pdf') {
        openPdf(doc)
    } else if (doc.download_url) {
        window.location.href = doc.download_url
    }
}
</script>
