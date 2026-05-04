<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref, watch, onUnmounted } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import DataTable from '@/Components/DataTable.vue';
import { useConfirm } from '@/Composables/useConfirm';
import { useErrorHandler } from '@/Composables/useErrorHandler';
import { usePermission } from '@/Composables/usePermission';
import { useToast } from '@/Composables/useToast';
import {
    ArchiveBoxIcon,
    ArrowPathIcon,
    ExclamationTriangleIcon,
    TrashIcon
} from '@heroicons/vue/24/outline';

const props = defineProps({
    tickets: Object,
    filters: Object,
    retention: Object,
});

const { confirm } = useConfirm();
const { post, destroy } = useErrorHandler();
const { hasPermission } = usePermission();
const { showError } = useToast();

const search = ref(props.filters?.search || '');
const perPage = ref(props.filters?.per_page || props.tickets?.per_page || 10);
const isLoading = ref(false);
let searchTimer = null;

const rows = computed(() => props.tickets?.data || []);
const canRestore = computed(() => hasPermission('tickets.edit'));
const canPurge = computed(() => hasPermission('settings.edit') && hasPermission('tickets.delete'));
const selectedIds = ref([]);
const selectedTickets = computed(() => rows.value.filter(ticket => selectedIds.value.includes(ticket.id)));
const allSelected = computed(() => rows.value.length > 0 && rows.value.every(ticket => selectedIds.value.includes(ticket.id)));

const toggleAll = () => {
    selectedIds.value = allSelected.value ? [] : rows.value.map(ticket => ticket.id);
};

const clearSelection = () => {
    selectedIds.value = [];
};

const showingText = computed(() => {
    if (!props.tickets || props.tickets.total === 0) {
        return 'Showing 0 archived tickets';
    }

    return `Showing ${props.tickets.from} to ${props.tickets.to} of ${props.tickets.total} archived tickets`;
});

const reload = (overrides = {}) => {
    isLoading.value = true;
    router.get(route('ticket-archive.index'), {
        search: search.value,
        per_page: perPage.value,
        ...overrides,
    }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onFinish: () => {
            isLoading.value = false;
        },
    });
};

watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => reload({ page: 1 }), 350);
});

watch(rows, () => {
    selectedIds.value = [];
});

onUnmounted(() => clearTimeout(searchTimer));

const goToPage = (page) => {
    if (page < 1 || page > props.tickets.last_page) return;
    reload({ page });
};

const changePerPage = (value) => {
    perPage.value = value;
    reload({ page: 1 });
};

const restoreTicket = async (ticket) => {
    if (!canRestore.value) return;

    const confirmed = await confirm({
        title: 'Restore Ticket',
        message: `Restore ${ticket.ticket_key}? It will return to the active ticket list.`,
        confirmLabel: 'Restore',
        cancelLabel: 'Cancel',
        variant: 'info',
    });

    if (!confirmed) return;

    post(route('ticket-archive.restore', ticket.id), {}, {
        preserveScroll: true,
        onError: (errors) => showError(Object.values(errors).flat().join(', ') || 'Cannot restore ticket'),
    });
};

const purgeBlockedReason = (ticket) => {
    if (ticket.active_children_count > 0) {
        return `Cannot purge ${ticket.ticket_key} because it still has ${ticket.active_children_count} active child ticket(s). Archive the child ticket(s) first.`;
    }

    if (!ticket.purge_eligible) {
        return `Cannot purge ${ticket.ticket_key} yet. It is retained for ${props.retention.label} and will be available for purge on ${ticket.purge_available_at}.`;
    }

    return null;
};

const selectedPurgeBlockedReasons = computed(() =>
    selectedTickets.value.map(ticket => purgeBlockedReason(ticket)).filter(Boolean)
);

const purgeTicket = async (ticket) => {
    if (!canPurge.value) return;

    const blockedReason = purgeBlockedReason(ticket);
    if (blockedReason) {
        showError(blockedReason);
        return;
    }

    const confirmed = await confirm({
        title: 'Permanently Purge Ticket',
        message: `Purge ${ticket.ticket_key} permanently? This deletes the database record and attachment files and cannot be undone.`,
        confirmLabel: 'Purge Permanently',
        cancelLabel: 'Keep Archived',
        variant: 'danger',
    });

    if (!confirmed) return;

    destroy(route('ticket-archive.purge', ticket.id), {
        preserveScroll: true,
        onError: (errors) => showError(Object.values(errors).flat().join(', ') || 'Cannot purge ticket'),
    });
};

const restoreSelected = async () => {
    if (!canRestore.value || selectedIds.value.length === 0) return;

    const count = selectedIds.value.length;
    const confirmed = await confirm({
        title: 'Restore Selected Tickets',
        message: `Restore ${count} selected archived ticket(s)? Parent tickets and archived child tickets may be restored together when linked.`,
        confirmLabel: 'Restore Selected',
        cancelLabel: 'Cancel',
        variant: 'info',
    });

    if (!confirmed) return;

    post(route('ticket-archive.bulk-restore'), { ticket_ids: selectedIds.value }, {
        preserveScroll: true,
        onSuccess: clearSelection,
        onError: (errors) => showError(Object.values(errors).flat().join(', ') || 'Cannot restore selected tickets'),
    });
};

const purgeSelected = async () => {
    if (!canPurge.value || selectedIds.value.length === 0) return;

    const blockedReasons = selectedPurgeBlockedReasons.value;
    if (blockedReasons.length > 0) {
        const suffix = blockedReasons.length > 1 ? ` ${blockedReasons.length - 1} other selected ticket(s) are also blocked.` : '';
        showError(`${blockedReasons[0]}${suffix}`);
        return;
    }

    const count = selectedIds.value.length;
    const confirmed = await confirm({
        title: 'Permanently Purge Selected Tickets',
        message: `Purge ${count} selected archived ticket(s) permanently? This deletes database records and attachment files and cannot be undone.`,
        confirmLabel: 'Purge Selected',
        cancelLabel: 'Keep Archived',
        variant: 'danger',
    });

    if (!confirmed) return;

    destroy(route('ticket-archive.bulk-purge'), {
        data: { ticket_ids: selectedIds.value },
        preserveScroll: true,
        onSuccess: clearSelection,
        onError: (errors) => showError(Object.values(errors).flat().join(', ') || 'Cannot purge selected tickets'),
    });
};

const statusLabel = (status) => {
    return String(status || '-').replace(/_/g, ' ');
};

const priorityLabel = (priority) => {
    return String(priority || '-').replace(/_/g, ' ');
};
</script>

<template>
    <Head title="Ticket Archive" />

    <AppLayout content-class="w-full max-w-none px-2 sm:px-4 lg:px-6">
        <template #header>
            <div class="flex items-center gap-2">
                <ArchiveBoxIcon class="h-5 w-5 text-gray-500" />
                <span>Ticket Archive</span>
            </div>
        </template>

        <div class="space-y-6">
            <section class="rounded-xl border border-red-100 bg-white p-5 shadow-sm">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <div class="flex items-center gap-2 text-[11px] font-black uppercase tracking-[0.22em] text-red-600">
                            <ExclamationTriangleIcon class="h-4 w-4" />
                            Manual Purge Retention
                        </div>
                        <h2 class="mt-2 text-xl font-black text-gray-900">Archived tickets become purge-eligible after {{ retention.label }}.</h2>
                        <p class="mt-1 text-sm text-gray-600">
                            Current cutoff: archived on or before {{ retention.cutoff }}.
                        </p>
                    </div>
                    <Link
                        :href="route('settings.index', { tab: 'ticket_retention' })"
                        class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-bold text-gray-700 transition-colors hover:bg-gray-50"
                    >
                        Retention Settings
                    </Link>
                </div>
            </section>

            <Transition
                enter-active-class="transition ease-out duration-200"
                enter-from-class="opacity-0 -translate-y-2"
                enter-to-class="opacity-100 translate-y-0"
                leave-active-class="transition ease-in duration-150"
                leave-from-class="opacity-100 translate-y-0"
                leave-to-class="opacity-0 -translate-y-2"
            >
                <div
                    v-if="selectedIds.length > 0"
                    class="rounded-xl border border-blue-200 bg-blue-50 p-4 shadow-sm"
                >
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <div class="text-[10px] font-black uppercase tracking-[0.22em] text-blue-500">Selected Archived Tickets</div>
                            <div class="mt-1 text-xl font-black text-blue-900">{{ selectedIds.length }}</div>
                            <div class="mt-1 text-xs text-blue-700">Restore or purge selected archive records in one action.</div>
                        </div>
                        <div class="flex flex-col gap-2 sm:flex-row">
                            <button
                                v-if="canRestore"
                                type="button"
                                @click="restoreSelected"
                                class="inline-flex min-h-[40px] items-center justify-center gap-2 rounded-lg border border-blue-300 bg-white px-4 py-2 text-sm font-bold text-blue-700 transition-colors hover:bg-blue-50"
                            >
                                <ArrowPathIcon class="h-4 w-4" />
                                Restore Selected
                            </button>
                            <button
                                v-if="canPurge"
                                type="button"
                                @click="purgeSelected"
                                :title="selectedPurgeBlockedReasons[0] || 'Purge selected tickets permanently'"
                                :aria-disabled="selectedPurgeBlockedReasons.length > 0"
                                class="inline-flex min-h-[40px] items-center justify-center gap-2 rounded-lg border border-red-300 bg-white px-4 py-2 text-sm font-bold text-red-700 transition-colors hover:bg-red-50"
                                :class="selectedPurgeBlockedReasons.length ? 'cursor-help opacity-60' : ''"
                            >
                                <TrashIcon class="h-4 w-4" />
                                Purge Selected
                            </button>
                            <button
                                type="button"
                                @click="clearSelection"
                                class="inline-flex min-h-[40px] items-center justify-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-bold text-slate-600 transition-colors hover:bg-slate-50"
                            >
                                Clear
                            </button>
                        </div>
                    </div>
                </div>
            </Transition>

            <DataTable
                title="Ticket Archive"
                subtitle="Archived tickets can be restored or permanently purged when retention is reached."
                search-placeholder="Search ticket key, title, requester, or assignee..."
                empty-message="No archived tickets match the current filters."
                :search="search"
                :data="rows"
                :current-page="tickets.current_page"
                :last-page="tickets.last_page"
                :per-page="Number(perPage)"
                :showing-text="showingText"
                :is-loading="isLoading"
                @update:search="search = $event"
                @go-to-page="goToPage"
                @change-per-page="changePerPage"
            >
                <template #header>
                    <tr>
                        <th class="px-4 py-3 w-10">
                            <input
                                type="checkbox"
                                :checked="allSelected"
                                @change="toggleAll"
                                class="cursor-pointer rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                            >
                        </th>
                        <th class="px-4 py-3 text-left text-[11px] font-black uppercase tracking-[0.18em] text-slate-500">Ticket</th>
                        <th class="px-4 py-3 text-left text-[11px] font-black uppercase tracking-[0.18em] text-slate-500">People</th>
                        <th class="px-4 py-3 text-left text-[11px] font-black uppercase tracking-[0.18em] text-slate-500">Archived</th>
                        <th class="px-4 py-3 text-left text-[11px] font-black uppercase tracking-[0.18em] text-slate-500">Purge Status</th>
                        <th class="px-4 py-3 text-right text-[11px] font-black uppercase tracking-[0.18em] text-slate-500">Actions</th>
                    </tr>
                </template>

                <template #body="{ data }">
                    <tr
                        v-for="ticket in data"
                        :key="ticket.id"
                        class="align-top hover:bg-gray-50"
                        :class="selectedIds.includes(ticket.id) ? 'ring-1 ring-inset ring-blue-300' : ''"
                    >
                        <td class="px-4 py-4 w-10 align-top">
                            <input
                                type="checkbox"
                                :value="ticket.id"
                                v-model="selectedIds"
                                class="mt-1 cursor-pointer rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                            >
                        </td>
                        <td class="px-4 py-4">
                            <div class="min-w-[260px] max-w-[420px] space-y-2">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="rounded-md border border-slate-300 bg-white px-2.5 py-1 text-[11px] font-black text-black">
                                        {{ ticket.ticket_key }}
                                    </span>
                                    <span class="rounded-full border border-slate-300 bg-white px-2.5 py-1 text-[11px] font-bold capitalize text-black">
                                        {{ priorityLabel(ticket.priority) }}
                                    </span>
                                    <span class="rounded-full border border-blue-300 bg-blue-50 px-2.5 py-1 text-[11px] font-bold capitalize text-blue-700">
                                        {{ statusLabel(ticket.status) }}
                                    </span>
                                </div>
                                <div class="break-words text-sm font-bold text-gray-900">{{ ticket.title }}</div>
                                <div v-if="ticket.parent" class="text-xs text-gray-500">
                                    Child of {{ ticket.parent.ticket_key }}
                                </div>
                                <div v-if="ticket.archived_children_count > 0" class="text-xs font-semibold text-blue-700">
                                    Includes {{ ticket.archived_children_count }} archived child ticket(s)
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-4">
                            <div class="min-w-[180px] space-y-2 text-sm">
                                <div>
                                    <div class="text-[10px] font-black uppercase tracking-[0.2em] text-slate-400">Requester</div>
                                    <div class="font-semibold text-gray-900">{{ ticket.reporter?.name || 'External requester' }}</div>
                                </div>
                                <div>
                                    <div class="text-[10px] font-black uppercase tracking-[0.2em] text-slate-400">Assignee</div>
                                    <div class="font-semibold text-gray-900">{{ ticket.assignee?.name || 'Unassigned' }}</div>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-4 text-sm text-gray-900">
                            <div class="min-w-[150px]">
                                <div class="font-semibold">{{ ticket.deleted_at }}</div>
                                <div class="mt-1 text-xs text-gray-500">Created {{ ticket.created_at }}</div>
                            </div>
                        </td>
                        <td class="px-4 py-4">
                            <div class="min-w-[190px]">
                                <span
                                    class="inline-flex rounded-full border px-3 py-1 text-xs font-bold"
                                    :class="ticket.purge_eligible && ticket.active_children_count === 0
                                        ? 'border-red-300 bg-red-50 text-red-700'
                                        : 'border-amber-300 bg-amber-50 text-amber-700'"
                                >
                                    {{ ticket.purge_eligible && ticket.active_children_count === 0 ? 'Eligible' : 'Retained' }}
                                </span>
                                <div class="mt-2 text-xs text-gray-500">
                                    <span v-if="ticket.active_children_count > 0">Archive child tickets before purge.</span>
                                    <span v-else-if="!ticket.purge_eligible">Available {{ ticket.purge_available_at }}</span>
                                    <span v-else>Permanent purge is available.</span>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-4">
                            <div class="flex min-w-[190px] justify-end gap-2">
                                <button
                                    v-if="canRestore"
                                    type="button"
                                    @click="restoreTicket(ticket)"
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-blue-300 bg-white px-3 py-2 text-xs font-bold text-blue-700 transition-colors hover:bg-blue-50"
                                >
                                    <ArrowPathIcon class="h-4 w-4" />
                                    Restore
                                </button>
                                <button
                                    v-if="canPurge"
                                    type="button"
                                    @click="purgeTicket(ticket)"
                                    :title="purgeBlockedReason(ticket) || 'Purge this ticket permanently'"
                                    :aria-disabled="Boolean(purgeBlockedReason(ticket))"
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-red-300 bg-white px-3 py-2 text-xs font-bold text-red-700 transition-colors hover:bg-red-50"
                                    :class="purgeBlockedReason(ticket) ? 'cursor-help opacity-60' : ''"
                                >
                                    <TrashIcon class="h-4 w-4" />
                                    Purge
                                </button>
                            </div>
                        </td>
                    </tr>
                </template>
            </DataTable>
        </div>
    </AppLayout>
</template>
