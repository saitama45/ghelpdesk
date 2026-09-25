<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref, watch, onUnmounted } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import DataTable from '@/Components/DataTable.vue';
import { useConfirm } from '@/Composables/useConfirm';
import { useErrorHandler } from '@/Composables/useErrorHandler';
import { useToast } from '@/Composables/useToast';
import {
    ArchiveBoxIcon,
    ArrowPathIcon,
    ExclamationTriangleIcon,
    LinkIcon,
    TrashIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    tab: String,
    records: Object,
    counts: Object,
    filters: Object,
    retention: Object,
    can: Object,
});

const { confirm } = useConfirm();
const { post, destroy } = useErrorHandler();
const { showError } = useToast();

const search = ref(props.filters?.search || '');
const status = ref(props.filters?.status || 'all');
const perPage = ref(props.filters?.per_page || props.records?.per_page || 10);
const isLoading = ref(false);
const selectedIds = ref([]);
let searchTimer = null;

const rows = computed(() => props.records?.data || []);
const isCustomers = computed(() => props.tab === 'customers');

// Loyalty Customers mixes still-active members with a pending deletion request
// and archived ones, so the tab-level rights below are narrowed per row by
// `isPending()`. Users rows are always archived.
const isPending = (record) => record.state === 'pending';

const canArchive = computed(() => isCustomers.value && props.can?.archive_requests);
const canRestore = computed(() => isCustomers.value ? props.can?.restore_customers : props.can?.restore_users);
const canPurge = computed(() => isCustomers.value ? props.can?.purge_customers : props.can?.purge_users);

const tabs = computed(() => [
    { id: 'customers', label: 'Loyalty Customers', count: props.counts?.customers || 0 },
    { id: 'users', label: 'Users', count: props.counts?.users || 0 },
]);

const statusOptions = computed(() => [
    { id: 'all', label: 'All', count: props.counts?.customers || 0 },
    { id: 'pending', label: 'Pending request', count: props.counts?.pending || 0 },
    { id: 'archived', label: 'Archived', count: props.counts?.archived || 0 },
]);

const selectedRows = computed(() => rows.value.filter(r => selectedIds.value.includes(r.id)));
const selectedPendingIds = computed(() => selectedRows.value.filter(isPending).map(r => r.id));
const selectedArchivedIds = computed(() => selectedRows.value.filter(r => !isPending(r)).map(r => r.id));
const allSelected = computed(() => rows.value.length > 0 && rows.value.every(r => selectedIds.value.includes(r.id)));

const toggleAll = () => {
    selectedIds.value = allSelected.value ? [] : rows.value.map(r => r.id);
};

const clearSelection = () => {
    selectedIds.value = [];
};

const showingText = computed(() => {
    const noun = isCustomers.value ? 'accounts' : 'archived accounts';

    if (!props.records || props.records.total === 0) {
        return `Showing 0 ${noun}`;
    }

    return `Showing ${props.records.from} to ${props.records.to} of ${props.records.total} ${noun}`;
});

const emptyMessage = computed(() => {
    if (!isCustomers.value) return 'No archived accounts match the current filters.';
    if (status.value === 'pending') return 'No pending deletion requests.';
    if (status.value === 'archived') return 'No archived loyalty customers match the current filters.';
    return 'No pending requests or archived loyalty customers match the current filters.';
});

// Tab lives in the URL so a restore or purge redirect comes back to the tab the
// person was working in, never bounced to the first one.
const reload = (overrides = {}) => {
    isLoading.value = true;
    router.get(route('account-archive.index'), {
        tab: props.tab,
        status: status.value,
        search: search.value,
        per_page: perPage.value,
        ...overrides,
    }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onFinish: () => { isLoading.value = false; },
    });
};

const switchTab = (tab) => {
    if (tab === props.tab) return;
    selectedIds.value = [];
    search.value = '';
    status.value = 'all';
    reload({ tab, status: 'all', search: '', page: 1 });
};

const setStatus = (value) => {
    if (value === status.value) return;
    selectedIds.value = [];
    status.value = value;
    reload({ status: value, page: 1 });
};

watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => reload({ page: 1 }), 350);
});

watch(rows, () => { selectedIds.value = []; });

onUnmounted(() => clearTimeout(searchTimer));

const goToPage = (page) => {
    if (page < 1 || page > props.records.last_page) return;
    reload({ page });
};

const changePerPage = (value) => {
    perPage.value = value;
    reload({ page: 1 });
};

// Sent with every action so the server redirects back to this exact list view.
const listState = () => ({
    status: status.value,
    search: search.value,
    per_page: perPage.value,
    page: props.records?.current_page || 1,
});

const linkedNote = (record) => {
    if (!record.linked) return '';
    return record.linked.archived
        ? `${record.linked.label} "${record.linked.name}" is archived with it and will be restored too.`
        : `${record.linked.label} "${record.linked.name}" is still active.`;
};

/* ------------------------------------------------------------------ *
 | Archive — Stage 1 of a member's deletion request
 * ------------------------------------------------------------------ */

const submitArchive = (ids, message) => {
    post(route('account-archive.archive'), { ids, ...listState() }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => { selectedIds.value = []; },
        onError: (errors) => showError(Object.values(errors).flat().join(', ') || message),
    });
};

const archiveRecord = async (record) => {
    if (!canArchive.value || !isPending(record)) return;

    const confirmed = await confirm({
        title: 'Archive Account',
        message: `Archive "${record.name}"? Their app login and loyalty customer record are closed together: they can no longer sign in and their QR code stops working. It can be restored until it is purged.`,
        confirmLabel: 'Archive',
        cancelLabel: 'Cancel',
        variant: 'danger',
    });

    if (!confirmed) return;

    submitArchive([record.id], 'Cannot archive account');
};

const archiveSelected = async () => {
    if (!canArchive.value || selectedPendingIds.value.length === 0) return;

    const confirmed = await confirm({
        title: 'Archive Selected Accounts',
        message: `Archive ${selectedPendingIds.value.length} account(s) with a pending request? Each app login is closed together with its loyalty customer record.`,
        confirmLabel: 'Archive Selected',
        cancelLabel: 'Cancel',
        variant: 'danger',
    });

    if (!confirmed) return;

    submitArchive([...selectedPendingIds.value], 'Cannot archive accounts');
};

/* ------------------------------------------------------------------ *
 | Restore
 * ------------------------------------------------------------------ */

const submitRestore = (ids, message) => {
    post(route('account-archive.restore'), { type: props.tab, ids, ...listState() }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => { selectedIds.value = []; },
        onError: (errors) => showError(Object.values(errors).flat().join(', ') || message),
    });
};

const restoreRecord = async (record) => {
    if (!canRestore.value || isPending(record)) return;

    const confirmed = await confirm({
        title: 'Restore Account',
        message: `Restore "${record.name}"? It returns to the active list. ${linkedNote(record)}`.trim(),
        confirmLabel: 'Restore',
        cancelLabel: 'Cancel',
        variant: 'info',
    });

    if (!confirmed) return;

    submitRestore([record.id], 'Cannot restore account');
};

const restoreSelected = async () => {
    if (!canRestore.value || selectedArchivedIds.value.length === 0) return;

    const confirmed = await confirm({
        title: 'Restore Selected Accounts',
        message: `Restore ${selectedArchivedIds.value.length} archived account(s)? Any linked user or customer record is restored with them.`,
        confirmLabel: 'Restore Selected',
        cancelLabel: 'Cancel',
        variant: 'info',
    });

    if (!confirmed) return;

    submitRestore([...selectedArchivedIds.value], 'Cannot restore accounts');
};

/* ------------------------------------------------------------------ *
 | Purge — permanent
 * ------------------------------------------------------------------ */

const purgeBlockedReason = (record) => {
    if (record.purge_blocker) return record.purge_blocker;

    if (!record.purge_eligible) {
        return `"${record.name}" is retained for ${props.retention.label} and can be purged from ${record.purge_available_at}.`;
    }

    return null;
};

const selectedPurgeBlockedReasons = computed(() =>
    selectedRows.value.filter(r => !isPending(r)).map(r => purgeBlockedReason(r)).filter(Boolean)
);

const submitPurge = (ids, message) => {
    destroy(route('account-archive.purge'), {
        data: { type: props.tab, ids, ...listState() },
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => { selectedIds.value = []; },
        onError: (errors) => showError(Object.values(errors).flat().join(', ') || message),
    });
};

const purgeRecord = async (record) => {
    if (!canPurge.value || isPending(record)) return;

    const blocked = purgeBlockedReason(record);
    if (blocked) {
        showError(blocked);
        return;
    }

    const confirmed = await confirm({
        title: 'Permanently Purge Account',
        message: `Purge "${record.name}" permanently? This erases the record and every reference to it, including any linked ${isCustomers.value ? 'app login' : 'loyalty customer record'}. It cannot be undone.`,
        confirmLabel: 'Purge Permanently',
        cancelLabel: 'Cancel',
        variant: 'danger',
    });

    if (!confirmed) return;

    submitPurge([record.id], 'Cannot purge account');
};

const purgeSelected = async () => {
    if (!canPurge.value || selectedArchivedIds.value.length === 0) return;

    if (selectedPurgeBlockedReasons.value.length > 0) {
        showError(selectedPurgeBlockedReasons.value[0]);
        return;
    }

    const confirmed = await confirm({
        title: 'Permanently Purge Selected Accounts',
        message: `Purge ${selectedArchivedIds.value.length} archived account(s) permanently? This erases each record and every reference to it, and cannot be undone.`,
        confirmLabel: 'Purge Permanently',
        cancelLabel: 'Cancel',
        variant: 'danger',
    });

    if (!confirmed) return;

    submitPurge([...selectedArchivedIds.value], 'Cannot purge accounts');
};
</script>

<template>
    <Head title="Account Archive" />

    <AppLayout content-class="w-full max-w-none px-2 sm:px-4 lg:px-6">
        <template #header>
            <div class="flex items-center gap-2">
                <ArchiveBoxIcon class="h-5 w-5 text-gray-500 dark:text-gray-300" />
                <span>Account Archive</span>
            </div>
        </template>

        <div class="space-y-6">
            <section class="rounded-xl border border-red-100 bg-white p-5 shadow-sm dark:bg-gray-800">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <div class="flex items-center gap-2 text-[11px] font-black uppercase tracking-[0.22em] text-red-600">
                            <ExclamationTriangleIcon class="h-4 w-4" />
                            Manual Purge Retention
                        </div>
                        <h2 class="mt-2 text-xl font-black text-gray-900 dark:text-gray-100">
                            Archived accounts become purge-eligible after {{ retention.label }}.
                        </h2>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                            Current cutoff: archived on or before {{ retention.cutoff }}. Deleting a user or a loyalty customer archives both sides of the pair, and restoring either side brings both back.
                        </p>
                    </div>
                    <Link
                        :href="route('settings.index', { tab: 'account_retention' })"
                        class="inline-flex items-center justify-center whitespace-nowrap rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-bold text-gray-700 transition-colors hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-300 dark:border-gray-600 dark:hover:bg-gray-700"
                    >
                        Retention Settings
                    </Link>
                </div>
            </section>

            <div class="flex flex-wrap gap-2 border-b border-gray-200 dark:border-gray-700">
                <button
                    v-for="t in tabs"
                    :key="t.id"
                    type="button"
                    @click="switchTab(t.id)"
                    class="inline-flex items-center gap-2 border-b-2 px-4 py-2.5 text-sm font-bold transition-colors"
                    :class="tab === t.id
                        ? 'border-blue-600 text-blue-700 dark:text-blue-300'
                        : 'border-transparent text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200'"
                >
                    {{ t.label }}
                    <span
                        class="rounded-full px-2 py-0.5 text-[11px] font-black"
                        :class="tab === t.id ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'"
                    >
                        {{ t.count }}
                    </span>
                </button>
            </div>

            <Transition
                enter-active-class="transition ease-out duration-200"
                enter-from-class="opacity-0 -translate-y-2"
                enter-to-class="opacity-100 translate-y-0"
                leave-active-class="transition ease-in duration-150"
                leave-from-class="opacity-100 translate-y-0"
                leave-to-class="opacity-0 -translate-y-2"
            >
                <div v-if="selectedIds.length > 0" class="rounded-xl border border-blue-200 bg-blue-50 p-4 shadow-sm">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <div class="text-[10px] font-black uppercase tracking-[0.22em] text-blue-500">Selected Accounts</div>
                            <div class="mt-1 text-xl font-black text-blue-900">{{ selectedIds.length }}</div>
                            <div class="mt-1 text-xs text-blue-700">
                                <template v-if="selectedPendingIds.length && selectedArchivedIds.length">
                                    {{ selectedPendingIds.length }} pending request(s) can be archived; {{ selectedArchivedIds.length }} archived account(s) can be restored or purged.
                                </template>
                                <template v-else-if="selectedPendingIds.length">Archive the selected pending requests in one action.</template>
                                <template v-else>Restore or purge the selected records in one action.</template>
                            </div>
                        </div>
                        <div class="flex flex-col gap-2 sm:flex-row">
                            <button
                                v-if="canArchive && selectedPendingIds.length"
                                type="button"
                                @click="archiveSelected"
                                class="inline-flex min-h-[40px] items-center justify-center gap-2 whitespace-nowrap rounded-lg border border-red-300 bg-white px-4 py-2 text-sm font-bold text-red-700 transition-colors hover:bg-red-50 dark:bg-gray-800"
                            >
                                <ArchiveBoxIcon class="h-4 w-4" />
                                Archive Selected ({{ selectedPendingIds.length }})
                            </button>
                            <button
                                v-if="canRestore && selectedArchivedIds.length"
                                type="button"
                                @click="restoreSelected"
                                class="inline-flex min-h-[40px] items-center justify-center gap-2 whitespace-nowrap rounded-lg border border-blue-300 bg-white px-4 py-2 text-sm font-bold text-blue-700 transition-colors hover:bg-blue-50 dark:bg-gray-800"
                            >
                                <ArrowPathIcon class="h-4 w-4" />
                                Restore Selected ({{ selectedArchivedIds.length }})
                            </button>
                            <button
                                v-if="canPurge && selectedArchivedIds.length"
                                type="button"
                                @click="purgeSelected"
                                :title="selectedPurgeBlockedReasons[0] || 'Purge selected accounts permanently'"
                                :aria-disabled="selectedPurgeBlockedReasons.length > 0"
                                class="inline-flex min-h-[40px] items-center justify-center gap-2 whitespace-nowrap rounded-lg border border-red-300 bg-white px-4 py-2 text-sm font-bold text-red-700 transition-colors hover:bg-red-50 dark:bg-gray-800"
                                :class="selectedPurgeBlockedReasons.length ? 'cursor-help opacity-60' : ''"
                            >
                                <TrashIcon class="h-4 w-4" />
                                Purge Selected ({{ selectedArchivedIds.length }})
                            </button>
                            <button
                                type="button"
                                @click="clearSelection"
                                class="inline-flex min-h-[40px] items-center justify-center whitespace-nowrap rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-bold text-slate-600 transition-colors hover:bg-slate-50 dark:bg-gray-800"
                            >
                                Clear
                            </button>
                        </div>
                    </div>
                </div>
            </Transition>

            <DataTable
                :title="isCustomers ? 'Loyalty Customers' : 'Archived Users'"
                :subtitle="isCustomers
                    ? 'Members who asked to delete their account, and archived loyalty customers with their app login. Archive a pending request; restore or purge an archived account. Each action covers both records.'
                    : 'Staff logins archived from User Management. Mobile-app members are listed under Loyalty Customers.'"
                :search-placeholder="isCustomers ? 'Search name, email, or phone...' : 'Search name, email, or employee ID...'"
                :empty-message="emptyMessage"
                :search="search"
                :data="rows"
                :current-page="records.current_page"
                :last-page="records.last_page"
                :per-page="Number(perPage)"
                :showing-text="showingText"
                :is-loading="isLoading"
                @update:search="search = $event"
                @go-to-page="goToPage"
                @change-per-page="changePerPage"
            >
                <template v-if="isCustomers" #filters>
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="mr-1 text-[10px] font-black uppercase tracking-[0.2em] text-slate-400 dark:text-slate-400">Status</span>
                        <button
                            v-for="option in statusOptions"
                            :key="option.id"
                            type="button"
                            @click="setStatus(option.id)"
                            class="inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs font-bold transition-colors"
                            :class="status === option.id
                                ? 'border-blue-600 bg-blue-600 text-white'
                                : 'border-gray-300 bg-white text-gray-600 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700'"
                        >
                            {{ option.label }}
                            <span
                                class="rounded-full px-1.5 text-[11px] font-black"
                                :class="status === option.id ? 'bg-white/20 text-white' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'"
                            >
                                {{ option.count }}
                            </span>
                        </button>
                    </div>
                </template>

                <template #header>
                    <tr>
                        <th class="px-4 py-3 w-10">
                            <input
                                type="checkbox"
                                :checked="allSelected"
                                @change="toggleAll"
                                class="cursor-pointer rounded border-gray-300 text-blue-600 focus:ring-blue-500 dark:border-gray-600"
                            >
                        </th>
                        <th class="px-4 py-3 text-left text-[11px] font-black uppercase tracking-[0.18em] text-slate-500 dark:text-slate-300">Account</th>
                        <th class="px-4 py-3 text-left text-[11px] font-black uppercase tracking-[0.18em] text-slate-500 dark:text-slate-300">Linked Record</th>
                        <th v-if="isCustomers" class="px-4 py-3 text-left text-[11px] font-black uppercase tracking-[0.18em] text-slate-500 dark:text-slate-300">Request Ticket</th>
                        <th class="px-4 py-3 text-left text-[11px] font-black uppercase tracking-[0.18em] text-slate-500 dark:text-slate-300">{{ isCustomers ? 'Status' : 'Archived' }}</th>
                        <th class="px-4 py-3 text-left text-[11px] font-black uppercase tracking-[0.18em] text-slate-500 dark:text-slate-300">Purge Status</th>
                        <th class="px-4 py-3 text-right text-[11px] font-black uppercase tracking-[0.18em] text-slate-500 dark:text-slate-300">Actions</th>
                    </tr>
                </template>

                <template #body="{ data }">
                    <tr
                        v-for="record in data"
                        :key="record.id"
                        class="align-top hover:bg-gray-50 dark:hover:bg-gray-700"
                        :class="selectedIds.includes(record.id) ? 'ring-1 ring-inset ring-blue-300' : ''"
                    >
                        <td class="px-4 py-4 w-10 align-top">
                            <input
                                type="checkbox"
                                :value="record.id"
                                v-model="selectedIds"
                                class="mt-1 cursor-pointer rounded border-gray-300 text-blue-600 focus:ring-blue-500 dark:border-gray-600"
                            >
                        </td>
                        <td class="px-4 py-4">
                            <div class="min-w-[220px] max-w-[380px] space-y-1">
                                <div class="break-words text-sm font-bold text-gray-900 dark:text-gray-100">{{ record.name }}</div>
                                <div class="break-all text-xs text-gray-600 dark:text-gray-300">{{ record.subtitle }}</div>
                                <div v-if="record.meta.length" class="flex flex-wrap gap-1 pt-1">
                                    <span
                                        v-for="m in record.meta"
                                        :key="m"
                                        class="rounded-full border border-slate-300 bg-white px-2 py-0.5 text-[11px] font-semibold text-slate-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200"
                                    >
                                        {{ m }}
                                    </span>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-4">
                            <div class="min-w-[180px] text-sm">
                                <div v-if="record.linked" class="space-y-1">
                                    <div class="flex items-center gap-1.5 text-[10px] font-black uppercase tracking-[0.2em] text-slate-400 dark:text-slate-300">
                                        <LinkIcon class="h-3.5 w-3.5" />
                                        {{ record.linked.label }}
                                    </div>
                                    <div class="break-all font-semibold text-gray-900 dark:text-gray-100">{{ record.linked.name }}</div>
                                    <span
                                        v-if="!isPending(record)"
                                        class="inline-flex rounded-full border px-2 py-0.5 text-[11px] font-bold"
                                        :class="record.linked.archived
                                            ? 'border-slate-300 bg-slate-50 text-slate-600'
                                            : 'border-amber-300 bg-amber-50 text-amber-700'"
                                    >
                                        {{ record.linked.archived ? 'Archived together' : 'Still active' }}
                                    </span>
                                </div>
                                <span v-else class="text-xs text-gray-400 dark:text-gray-500">None</span>
                            </div>
                        </td>
                        <td v-if="isCustomers" class="px-4 py-4">
                            <div class="min-w-[160px]">
                                <template v-if="record.ticket">
                                    <Link
                                        :href="route('tickets.edit', record.ticket.key)"
                                        class="font-bold text-blue-600 hover:underline dark:text-blue-400"
                                    >
                                        {{ record.ticket.key }}
                                    </Link>
                                    <div class="mt-1 text-xs text-gray-500 dark:text-gray-300">{{ record.ticket.status }}</div>
                                    <div class="mt-1 text-xs text-gray-500 dark:text-gray-300">Requested {{ record.ticket.requested_at }}</div>
                                </template>
                                <span v-else class="text-xs text-gray-400 dark:text-gray-500">No request</span>
                            </div>
                        </td>
                        <td class="px-4 py-4 text-sm text-gray-900 dark:text-gray-100">
                            <div v-if="isPending(record)" class="min-w-[150px]">
                                <span class="inline-flex rounded-full border border-blue-300 bg-blue-50 px-3 py-1 text-xs font-bold text-blue-700">
                                    Pending request
                                </span>
                                <div class="mt-2 text-xs text-gray-500 dark:text-gray-300">Active. Member since {{ record.created_at }}</div>
                            </div>
                            <div v-else class="min-w-[150px]">
                                <span v-if="isCustomers" class="mb-1 inline-flex rounded-full border border-slate-300 bg-slate-50 px-3 py-1 text-xs font-bold text-slate-600">
                                    Archived
                                </span>
                                <div class="font-semibold">{{ record.deleted_at }}</div>
                                <div v-if="record.deleted_by" class="mt-1 text-xs text-gray-500 dark:text-gray-300">by {{ record.deleted_by }}</div>
                                <div class="mt-1 text-xs text-gray-500 dark:text-gray-300">Created {{ record.created_at }}</div>
                            </div>
                        </td>
                        <td class="px-4 py-4">
                            <span v-if="isPending(record)" class="text-xs text-gray-400 dark:text-gray-500">Not archived yet</span>
                            <div v-else class="min-w-[200px]">
                                <span
                                    class="inline-flex rounded-full border px-3 py-1 text-xs font-bold"
                                    :class="!purgeBlockedReason(record)
                                        ? 'border-red-300 bg-red-50 text-red-700'
                                        : 'border-amber-300 bg-amber-50 text-amber-700'"
                                >
                                    {{ !purgeBlockedReason(record) ? 'Eligible' : 'Retained' }}
                                </span>
                                <div class="mt-2 text-xs text-gray-500 dark:text-gray-300">
                                    <span v-if="record.purge_blocker">{{ record.purge_blocker }}</span>
                                    <span v-else-if="!record.purge_eligible">Available {{ record.purge_available_at }}</span>
                                    <span v-else>Permanent purge is available.</span>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-4">
                            <div class="flex min-w-[110px] justify-end space-x-1">
                                <button
                                    v-if="canArchive && isPending(record)"
                                    type="button"
                                    @click="archiveRecord(record)"
                                    title="Archive this account (Stage 1)"
                                    class="rounded-full p-2 text-red-600 transition-colors hover:bg-red-50 hover:text-red-900"
                                >
                                    <ArchiveBoxIcon class="h-5 w-5" />
                                </button>
                                <button
                                    v-if="canRestore && !isPending(record)"
                                    type="button"
                                    @click="restoreRecord(record)"
                                    title="Restore this account"
                                    class="rounded-full p-2 text-blue-600 transition-colors hover:bg-blue-50 hover:text-blue-900"
                                >
                                    <ArrowPathIcon class="h-5 w-5" />
                                </button>
                                <button
                                    v-if="canPurge && !isPending(record)"
                                    type="button"
                                    @click="purgeRecord(record)"
                                    :title="purgeBlockedReason(record) || 'Purge this account permanently'"
                                    :aria-disabled="Boolean(purgeBlockedReason(record))"
                                    class="rounded-full p-2 text-red-600 transition-colors hover:bg-red-50 hover:text-red-900"
                                    :class="purgeBlockedReason(record) ? 'cursor-help opacity-60' : ''"
                                >
                                    <TrashIcon class="h-5 w-5" />
                                </button>
                            </div>
                        </td>
                    </tr>
                </template>
            </DataTable>
        </div>
    </AppLayout>
</template>
