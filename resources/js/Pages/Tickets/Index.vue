<script setup>
import { Head, Link, useForm, usePage, router } from '@inertiajs/vue3';
import { ref, reactive, onMounted, watch, computed } from 'vue';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import DataTable from '@/Components/DataTable.vue';
import Dropdown from '@/Components/Dropdown.vue';
import Autocomplete from '@/Components/Autocomplete.vue';
import MultiAutocomplete from '@/Components/MultiAutocomplete.vue';
import HierarchySelector from '@/Components/HierarchySelector.vue';
import { useConfirm } from '@/Composables/useConfirm';
import { useErrorHandler } from '@/Composables/useErrorHandler';
import { useToast } from '@/Composables/useToast';
import { usePagination } from '@/Composables/usePagination';
import { usePermission } from '@/Composables/usePermission';
import { useDateFormatter } from '@/Composables/useDateFormatter';

const props = defineProps({
    tickets: Object,
    staff: Array,
    companies: Array,
    stores: Array,
    subCategories: { type: Array, default: () => [] },
    vendors: Array,
    cannedMessages: Array,
    filters: Object,
    departments: Array,
    departmentReferences: { type: Array, default: () => [] },
    hierarchicalDepartments: Array,
    summaryStats: Object,
    summaryStatsByDept: { type: Object, default: () => ({}) },
    entityFilter: { type: Object, default: () => ({ enabled: false, options: [], selected: [] }) },
    ticketKeyOptions: { type: Array, default: () => [] },
    requesterOptions: { type: Array, default: () => [] },
});

const page = usePage();
const showCreateModal = ref(false);
const showAcceptModal = ref(false);
const localTodayStr = () => {
    const d = new Date();
    return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
};

const showExportModal = ref(false);
const exportItems = ref([]);
const exportFieldMode = ref('sub_category'); // 'sub_category' | 'item'
const exportFilterItemIds = ref([]);
const exportFilterSubCategoryIds = ref([]);
const exportFilterRequester = ref('');
const exportFilterPriority = ref([]);
const exportFilterConcernType = ref('');
const exportFrom = ref('');
const exportTo = ref('');

// Subcategories derived from the loaded items (each item carries its sub_category relation)
const exportSubCategories = computed(() => {
    const seen = new Map();
    exportItems.value.forEach((item) => {
        const sub = item.sub_category;
        if (sub && sub.id != null && !seen.has(sub.id)) {
            seen.set(sub.id, { id: sub.id, name: sub.name });
        }
    });
    return Array.from(seen.values()).sort((a, b) => String(a.name).localeCompare(String(b.name)));
});

const openExportModal = async () => {
    showExportModal.value = true;
    exportFieldMode.value = 'sub_category';
    exportFrom.value = localTodayStr();
    exportTo.value = localTodayStr();
    if (!exportItems.value.length) {
        try {
            const res = await axios.get(route('tickets.data.items', undefined, false));
            exportItems.value = res.data;
        } catch (e) {
            console.error('Failed to load items for export filter', e);
        }
    }
};
const acceptingTicket = ref(null);
const fileInput = ref(null);
const isSubmitting = ref(false);
const { confirm } = useConfirm();
const { post, put, destroy } = useErrorHandler();
const { showSuccess, showError } = useToast();
const { hasPermission } = usePermission();
const { formatDate } = useDateFormatter();

// Real-time clock for SLA calculations
const currentTime = ref(new Date());
let timer;

// Column configuration
const tableColumns = ref([
    { key: 'ticket', label: 'Ticket', visible: true, locked: true },
    { key: 'assignee', label: 'Assignee', visible: true, locked: false },
    { key: 'queue_detail', label: 'Location / Item', visible: true, locked: false },
    { key: 'sla_health', label: 'SLA Health', visible: true, locked: false },
    { key: 'created', label: 'Created', visible: true, locked: false },
    { key: 'sla_timer', label: 'SLA Timer', visible: false, locked: false },
    { key: 'responded_time', label: 'Responded Time', visible: false, locked: false },
    { key: 'resolved_date', label: 'Resolved Date', visible: false, locked: false },
    { key: 'feedback', label: 'Feedback Received', visible: false, locked: false },
    { key: 'rating', label: 'Feedback Rating', visible: false, locked: false },
]);

const toggleColumn = (col) => {
    if (col.locked) return;
    col.visible = !col.visible;
    const settings = {};
    tableColumns.value.forEach(c => settings[c.key] = c.visible);
    localStorage.setItem('ghelpdesk_ticket_columns', JSON.stringify(settings));
};

const isColumnVisible = (key) => {
    const col = tableColumns.value.find(c => c.key === key);
    return col ? col.visible : true;
};

onMounted(() => {
    if (!window.location.search) {
        const savedFiltersStr = localStorage.getItem('ghelpdesk_ticket_filters');
        if (savedFiltersStr) {
            try {
                const savedFilters = JSON.parse(savedFiltersStr);
                const { entity_ids, ticket_keys, ...currentParams } = ticketFilterParams();
                
                // Entity selection is never restored from storage (resets each visit).
                delete savedFilters.entity_ids;
                // Ticket deep links and the removed general search are never restored.
                delete savedFilters.ticket_keys;
                delete savedFilters.search;
                if (JSON.stringify(savedFilters) !== JSON.stringify(currentParams)) {
                    if (savedFilters.status !== undefined) filterStatus.value = savedFilters.status;
                    if (savedFilters.department_node_id !== undefined) filterNodeId.value = savedFilters.department_node_id;
                    if (savedFilters.assignee_id !== undefined) filterAssignee.value = savedFilters.assignee_id;
                    if (savedFilters.store_id !== undefined) filterStore.value = savedFilters.store_id;
                    if (savedFilters.vendor_id !== undefined) filterVendor.value = normalizeFilterValues(savedFilters.vendor_id, [], normalizeVendorFilterValue);
                    if (savedFilters.sub_category_id !== undefined) filterSubCategory.value = savedFilters.sub_category_id;
                    if (savedFilters.start_date !== undefined) filterStartDate.value = savedFilters.start_date;
                    if (savedFilters.end_date !== undefined) filterEndDate.value = savedFilters.end_date;
                    if (savedFilters.dashboard_filter !== undefined) activeDashboardFilter.value = savedFilters.dashboard_filter;
                    if (savedFilters.assigned_department_only !== undefined) assignedDepartmentOnly.value = Boolean(savedFilters.assigned_department_only);
                    if (savedFilters.requester_keys !== undefined) filterRequesterKeys.value = normalizeFilterValues(savedFilters.requester_keys, [], normalizeStringFilterValue);
                    if (savedFilters.ticket_scope !== undefined) {
                        savedFilters.ticket_scope = normalizeTicketScope(savedFilters.ticket_scope);
                        filterTicketScope.value = savedFilters.ticket_scope;
                    }
                    router.get(route('tickets.index'), savedFilters, { replace: true, preserveState: true });
                    return;
                }
            } catch (e) {}
        }
    } else {
        const params = ticketFilterParams();
        if (!filterTicketKeys.value.length) {
            const { entity_ids, ticket_keys, ...persistedParams } = params;
            localStorage.setItem('ghelpdesk_ticket_filters', JSON.stringify(persistedParams));
        }
    }

    const savedCols = localStorage.getItem('ghelpdesk_ticket_columns');
    if (savedCols) {
        try {
            const parsed = JSON.parse(savedCols);
            tableColumns.value.forEach(col => {
                if (!col.locked && parsed[col.key] !== undefined) {
                    col.visible = parsed[col.key];
                }
            });
        } catch(e) {}
    }

    pagination.updateData(props.tickets);
    timer = setInterval(() => {
        currentTime.value = new Date();
    }, 60000); // Update every minute

    const scrollEl = getScrollContainer() || window;
    scrollEl.addEventListener('scroll', handleScroll, { passive: true });
    handleScroll();
});

import { onUnmounted } from 'vue';
onUnmounted(() => {
    if (timer) clearInterval(timer);
    const scrollEl = getScrollContainer() || window;
    scrollEl.removeEventListener('scroll', handleScroll);
});

const isNearlyDue = (targetAt) => {
    if (!targetAt) return false;
    const target = new Date(targetAt);
    const diff = target - currentTime.value;
    // Nearly due = less than 1 hour (3600000 ms) and not yet past
    return diff > 0 && diff < 3600000;
};

const isUserRole = computed(() => {
    const user = page.props.auth.user;
    return user?.roles?.some(role => role.name === 'User') ?? false;
});

// Computed property for available companies based on user roles
const availableCompanies = computed(() => {
    const user = page.props.auth.user;
    if (!user || !user.roles) return [];

    // If Admin, show all companies
    if (user.roles.some(role => role.name === 'Admin')) {
        return props.companies;
    }

    // Get all company IDs from user's roles
    const allowedCompanyIds = user.roles.reduce((ids, role) => {
        if (role.companies) {
            role.companies.forEach(company => ids.add(company.id));
        }
        return ids;
    }, new Set());

    // Also include direct company assignment
    if (user.company_id) {
        allowedCompanyIds.add(user.company_id);
    }

    // Filter available companies
    return props.companies.filter(company => allowedCompanyIds.has(company.id));
});

// Determine default company ID
const defaultCompanyId = computed(() => {
    const user = page.props.auth.user;
    if (!user) return '';

    // If user has a direct company_id and it's in the available list, use it
    if (user.company_id && availableCompanies.value.some(c => c.id === user.company_id)) {
        return user.company_id;
    }

    // Otherwise, use the first available company
    return availableCompanies.value.length > 0 ? availableCompanies.value[0].id : '';
});

const isBlankFilterValue = (value) => value === null || value === undefined || value === '';
const normalizeStringFilterValue = (value) => String(value).trim();
const normalizeFilterValues = (value, fallback = [], mapper = (item) => item) => {
    const values = (Array.isArray(value) ? value : [value])
        .filter(value => !isBlankFilterValue(value))
        .map(mapper)
        .filter(value => !isBlankFilterValue(value));

    return values.length ? values : [...fallback];
};
const defaultStatusFilters = () => [isUserRole.value ? 'all' : 'open'];
const normalizeAssigneeFilterValue = (value) => {
    const matchingStaff = props.staff?.find(staff => String(staff.id) === String(value));
    return matchingStaff?.id ?? value;
};

const normalizeStoreFilterValue = (value) => {
    const matchingStore = props.stores?.find(store => String(store.id) === String(value));
    return matchingStore?.id ?? value;
};

const normalizeVendorFilterValue = (value) => {
    const matchingVendor = props.vendors?.find(vendor => String(vendor.id) === String(value));
    return matchingVendor?.id ?? value;
};

const defaultTicketScope = 'all';
const ticketScopeOptions = [
    { value: 'parents', label: 'Parent Tickets' },
    { value: 'children', label: 'Child Tickets' },
    { value: 'all', label: 'All Tickets' },
];

const normalizeTicketScope = (scope) => {
    return ticketScopeOptions.some(option => option.value === scope) ? scope : defaultTicketScope;
};

const getTicketScopeLabel = (scope) => {
    return ticketScopeOptions.find(option => option.value === scope)?.label || 'All Tickets';
};

const filterStatus = ref(normalizeFilterValues(props.filters?.status, defaultStatusFilters(), normalizeStringFilterValue));
const filterNodeId = ref(
    props.filters?.department_node_id
        ? props.filters.department_node_id
        : (props.filters?.department_id ? `dept-${props.filters.department_id}` : '')
)
const filterAssignee = ref(normalizeFilterValues(props.filters?.assignee_id, [], normalizeAssigneeFilterValue));
const filterStore = ref(normalizeFilterValues(props.filters?.store_id, [], normalizeStoreFilterValue));
const filterVendor = ref(normalizeFilterValues(props.filters?.vendor_id, [], normalizeVendorFilterValue));
const filterSubCategory = ref(props.filters?.sub_category_id ?? '');
const filterStartDate = ref(props.filters?.start_date || '');
const filterEndDate = ref(props.filters?.end_date || '');
const assignedDepartmentOnly = ref(Boolean(props.filters?.assigned_department_only));
const filterTicketScope = ref(normalizeTicketScope(props.filters?.ticket_scope || defaultTicketScope));
const filterTicketKeys = ref(normalizeFilterValues(props.filters?.ticket_keys, [], normalizeStringFilterValue));
const filterRequesterKeys = ref(normalizeFilterValues(props.filters?.requester_keys, [], normalizeStringFilterValue));
// Entity/Company filter — defaults to the active sidebar entity (server-seeded).
const entityFilterEnabled = computed(() => !!props.entityFilter?.enabled);
const entityFilterOptions = computed(() => props.entityFilter?.options || []);
// "All" is a sentinel meaning every accessible entity; expanded to real ids on send.
const entityFilterOptionsWithAll = computed(() => [{ id: 'all', name: 'All Entities' }, ...entityFilterOptions.value]);
const filterEntities = ref([...(props.entityFilter?.selected || [])]);
const resolvedEntityIds = () => filterEntities.value.includes('all')
    ? entityFilterOptions.value.map(o => o.id)
    : filterEntities.value;
const ticketKeyFilterOptions = computed(() => props.ticketKeyOptions || []);
const ticketKeyCompanyIdByValue = computed(() => new Map(
    ticketKeyFilterOptions.value.map(option => [String(option.value), Number(option.company_id)])
));

const filterOptions = [
    { value: 'all', label: 'All' },
    { value: 'my_tickets', label: 'My Tickets' },
    { value: 'open', label: 'Open' },
    { value: 'for_schedule', label: 'For Schedule' },
    { value: 'in_progress', label: 'In Progress' },
    { value: 'resolved', label: 'Resolved' },
    { value: 'waiting_service_provider', label: 'Waiting for service provider' },
    { value: 'waiting_client_feedback', label: 'Waiting for Client\'s Feedback' },
    { value: 'closed', label: 'Closed' },
    { value: 'unassigned', label: 'Unassigned' },
];

const statusOptions = computed(() => {
    return filterOptions.map(opt => ({ id: opt.value, name: opt.label }));
});

// Department filter is restricted to the department level only (no sub-unit
// drill-down) — same behaviour as Tickets/Edit.vue. Hence children are dropped
// so every option is a directly selectable department.
const hierarchicalOptions = computed(() =>
    (props.hierarchicalDepartments || []).map(dept => ({
        ...dept,
        id: `dept-${dept.id}`,
        children: []
    }))
)

const bulkDepartmentNodes = computed(() =>
    (props.hierarchicalDepartments || [])
        .filter(dept => dept?.name && dept?.is_active !== false)
        .map(dept => ({
            id: dept.name,
            name: dept.name,
            code: dept.code,
        }))
)

const assigneeSectorByNodeId = computed(() => {
    const sectors = new Map()

    const mapSectorToNodeTree = (node, label) => {
        sectors.set(Number(node.id), label)
        ;(node.children || []).forEach(child => mapSectorToNodeTree(child, label))
    }

    const visitNode = (node) => {
        const sectorMatch = String(node.name || '').match(/^Sector\s+(\d+)$/i)

        if (sectorMatch) {
            mapSectorToNodeTree(node, `Sector ${sectorMatch[1]}`)
            return
        }

        ;(node.children || []).forEach(visitNode)
    }

    ;(props.hierarchicalDepartments || []).forEach(dept => {
        ;(dept.nodes || []).forEach(visitNode)
    })

    return sectors
})

const assigneeSectorLabel = (assignee) => {
    if (!assignee?.department_node_id) return ''
    return assigneeSectorByNodeId.value.get(Number(assignee.department_node_id)) || ''
}

const deptFilterParams = computed(() => {
    const nodeId = filterNodeId.value
    if (!nodeId) return {}
    if (typeof nodeId === 'string' && nodeId.startsWith('dept-')) {
        return { department_id: nodeId.replace('dept-', '') }
    }
    return { department_node_id: nodeId }
})

const assigneeOptions = computed(() => {
    return (props.staff || []).map(s => ({ id: s.id, name: s.name }));
});

const vendorFilterOptions = computed(() =>
    (props.vendors || []).filter(vendor => vendor.id !== null && vendor.id !== '')
);

const subCategoryOptions = computed(() => [
    { id: '', name: 'All SubCategories' },
    ...(props.subCategories || []),
]);

const ticketFilterParams = () => ({
    status: filterStatus.value,
    ...deptFilterParams.value,
    assignee_id: filterAssignee.value,
    store_id: filterStore.value,
    vendor_id: filterVendor.value,
    sub_category_id: filterSubCategory.value,
    start_date: filterStartDate.value,
    end_date: filterEndDate.value,
    dashboard_filter: activeDashboardFilter.value,
    assigned_department_only: assignedDepartmentOnly.value ? 1 : undefined,
    ticket_scope: filterTicketScope.value,
    ticket_keys: filterTicketKeys.value.length ? filterTicketKeys.value : undefined,
    requester_keys: filterRequesterKeys.value.length ? filterRequesterKeys.value : undefined,
    entity_ids: entityFilterEnabled.value ? resolvedEntityIds() : undefined,
});

const pagination = usePagination(props.tickets, 'tickets.index', ticketFilterParams);
// Preserve legacy ?search= links even though the general search control is no longer shown.
pagination.search.value = props.filters?.search || '';

// --- Infinite scroll accumulation ---
// Rows are accumulated client-side across pages. The watcher on props.tickets
// (below) replaces the buffer on any filter/search change (current_page <= 1)
// and appends, deduped, when a "load more" page arrives (current_page > 1).
const accumulatedTickets = ref([...(props.tickets?.data || [])]);
const ticketsMeta = ref({
    current_page: props.tickets?.current_page || 1,
    last_page: props.tickets?.last_page || 1,
    total: props.tickets?.total || 0,
});
const loadingMoreTickets = ref(false);

const mergeTicketPage = (payload) => {
    if (!payload) return;
    const incoming = payload.data || [];
    if ((payload.current_page || 1) <= 1) {
        accumulatedTickets.value = [...incoming];
    } else {
        const seen = new Set(accumulatedTickets.value.map(t => t.id));
        accumulatedTickets.value = [
            ...accumulatedTickets.value,
            ...incoming.filter(t => !seen.has(t.id)),
        ];
    }
    ticketsMeta.value = {
        current_page: payload.current_page || 1,
        last_page: payload.last_page || 1,
        total: payload.total || 0,
    };
};

const hasMoreTickets = computed(
    () => ticketsMeta.value.current_page < ticketsMeta.value.last_page
);

const ticketsShowingText = computed(() => {
    const total = ticketsMeta.value.total || 0;
    if (total === 0) return 'No records found';
    return `Showing ${accumulatedTickets.value.length} of ${total} records`;
});

const loadMoreTickets = () => {
    if (loadingMoreTickets.value || !hasMoreTickets.value) return;
    loadingMoreTickets.value = true;
    const nextPage = ticketsMeta.value.current_page + 1;
    router.reload({
        only: ['tickets'],
        data: {
            ...ticketFilterParams(),
            search: pagination.search.value,
            per_page: pagination.perPage.value,
            page: nextPage,
        },
        preserveScroll: true,
        preserveState: true,
        onFinish: () => {
            loadingMoreTickets.value = false;
        },
    });
};

// --- Scroll to top ---
// The page scrolls inside AppLayout's `<main scroll-region>` element, not the
// window, so we attach to that container.
const showScrollTop = ref(false);
const getScrollContainer = () => document.querySelector('main[scroll-region]');
const handleScroll = () => {
    const el = getScrollContainer();
    showScrollTop.value = (el ? el.scrollTop : window.scrollY) > 400;
};
const scrollToTop = () => {
    const el = getScrollContainer();
    (el || window).scrollTo({ top: 0, behavior: 'smooth' });
};

const applyFilter = () => {
    pagination.currentPage.value = 1;
    const params = {
        ...ticketFilterParams(),
        search: pagination.search.value
    };

    // Entity/Company selection is intentionally NOT persisted — each visit
    // resets to the active sidebar entity (server-seeded default).
    if (!filterTicketKeys.value.length) {
        const { entity_ids, ticket_keys, search, ...persistedParams } = params;
        localStorage.setItem('ghelpdesk_ticket_filters', JSON.stringify(persistedParams));
    }

    router.get(route('tickets.index'), params, {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
            pagination.updateData(page.props.tickets);
        }
    });
};

const handleStatusFilterChange = (value) => {
    const selectedValues = normalizeFilterValues(value, [], normalizeStringFilterValue);

    if (selectedValues.length === 0) {
        filterStatus.value = ['all'];
    } else if (selectedValues.includes('all')) {
        const statusesWithoutAll = selectedValues.filter(status => status !== 'all');
        filterStatus.value = filterStatus.value.includes('all') && statusesWithoutAll.length
            ? statusesWithoutAll
            : ['all'];
    } else {
        filterStatus.value = selectedValues;
    }

    applyFilter();
};

const handleAssigneeFilterChange = (value) => {
    filterAssignee.value = normalizeFilterValues(value, [], normalizeAssigneeFilterValue);
    applyFilter();
};

const handleTicketKeyFilterChange = (value) => {
    filterTicketKeys.value = normalizeFilterValues(value, [], normalizeStringFilterValue);
    applyFilter();
};

const handleRequesterFilterChange = (value) => {
    filterRequesterKeys.value = normalizeFilterValues(value, [], normalizeStringFilterValue);
    applyFilter();
};

const handleStoreFilterChange = (value) => {
    filterStore.value = normalizeFilterValues(value, [], normalizeStoreFilterValue);
    applyFilter();
};

const handleVendorFilterChange = (value) => {
    filterVendor.value = normalizeFilterValues(value, [], normalizeVendorFilterValue);
    applyFilter();
};

const handleEntityFilterChange = (value) => {
    let next = Array.isArray(value) ? value : [];
    const hadAll = filterEntities.value.includes('all');
    const hasAll = next.includes('all');
    if (hasAll && !hadAll) {
        next = ['all']; // just picked "All" → collapse to it
    } else if (hasAll && next.length > 1) {
        next = next.filter(v => v !== 'all'); // picked a specific entity → drop "All"
    }
    filterEntities.value = next.map(v => (v === 'all' ? 'all' : parseInt(v, 10))).filter(v => v === 'all' || v);

    // Ticket keys are entity-owned. Remove only keys that no longer belong to
    // the selected entities so a hidden, incompatible key cannot empty the list.
    const selectedEntityIds = new Set(resolvedEntityIds().map(Number));
    filterTicketKeys.value = filterTicketKeys.value.filter((key) => {
        const companyId = ticketKeyCompanyIdByValue.value.get(String(key));
        return companyId && selectedEntityIds.has(companyId);
    });

    applyFilter();
};

watch(() => props.entityFilter?.selected, (selected) => {
    const confirmedIds = (selected || []).map(Number).filter(Boolean);
    const currentIds = resolvedEntityIds().map(Number).filter(Boolean);
    const sameSelection = confirmedIds.length === currentIds.length
        && confirmedIds.every(id => currentIds.includes(id));

    if (!sameSelection) {
        filterEntities.value = confirmedIds;
    }
}, { deep: true });

const handleSubCategoryFilterChange = (value) => {
    filterSubCategory.value = value ?? '';
    applyFilter();
};

const handleTicketScopeChange = () => {
    selectedIds.value = [];
    applyFilter();
};

const exportToExcel = () => {
    const params = new URLSearchParams()
    const fp = ticketFilterParams()
    Object.entries(fp).forEach(([k, v]) => {
        if (Array.isArray(v)) v.forEach(i => params.append(`${k}[]`, i))
        else if (v !== null && v !== undefined && v !== '') params.set(k, v)
    })
    if (pagination.search.value) params.set('search', pagination.search.value)
    if (exportFieldMode.value === 'item') {
        exportFilterItemIds.value.forEach(id => params.append('item_id[]', id))
    } else {
        exportFilterSubCategoryIds.value.forEach(id => params.append('sub_category_id[]', id))
    }
    if (exportFilterRequester.value) params.set('requester', exportFilterRequester.value)
    exportFilterPriority.value.forEach(p => params.append('priority[]', p))
    if (exportFilterConcernType.value) params.set('concern_type', exportFilterConcernType.value)
    // Modal date range overrides any inherited list date filters
    params.delete('start_date')
    params.delete('end_date')
    if (exportFrom.value) params.set('start_date', exportFrom.value)
    if (exportTo.value) params.set('end_date', exportTo.value)
    window.location.href = route('tickets.export') + '?' + params.toString()
    showExportModal.value = false
}

const closeExportModal = () => {
    showExportModal.value = false
    exportFilterItemIds.value = []
    exportFilterSubCategoryIds.value = []
    exportFilterRequester.value = ''
    exportFilterPriority.value = []
    exportFilterConcernType.value = ''
    exportFrom.value = ''
    exportTo.value = ''
}

const clearFilters = () => {
    filterStatus.value = defaultStatusFilters();
    filterNodeId.value = '';
    filterAssignee.value = [];
    filterStore.value = [];
    filterVendor.value = [];
    filterSubCategory.value = '';
    filterStartDate.value = '';
    filterEndDate.value = '';
    activeDashboardFilter.value = 'all';
    assignedDepartmentOnly.value = false;
    filterTicketScope.value = defaultTicketScope;
    filterTicketKeys.value = [];
    filterRequesterKeys.value = [];
    pagination.search.value = '';
    applyFilter();
};

watch(() => props.tickets, (newTickets) => {
    pagination.updateData(newTickets);
    mergeTicketPage(newTickets);
}, { deep: true });

const acceptForm = useForm({
    company_id: '',
    store_id: '',
    item_id: '',
    department: '',
});

const isAcceptSubmitting = ref(false);

const acceptDepartmentNodes = computed(() => {
    const references = (props.departmentReferences || [])
        .filter(department => department?.name && department?.is_active !== false)
        .map(department => ({
            id: department.name,
            name: department.name,
            code: department.code,
        }));

    const fallbackDepartments = (props.departments || [])
        .filter(Boolean)
        .map(department => ({
            id: department,
            name: department,
        }));

    const nodes = references.length ? references : fallbackDepartments;
    const currentDepartment = acceptForm.department || acceptingTicket.value?.department || '';
    const hasCurrentDepartment = nodes.some(department => department.id === currentDepartment);

    if (currentDepartment && !hasCurrentDepartment) {
        return [
            {
                id: currentDepartment,
                name: `${currentDepartment} (Legacy)`,
            },
            ...nodes,
        ];
    }

    return nodes;
});

const hasCompleteAcceptForm = computed(() =>
    !!acceptForm.company_id
    && !!acceptForm.store_id
    && !!acceptForm.item_id
    && !!acceptForm.department
);

const canSubmitAcceptTicket = computed(() => hasCompleteAcceptForm.value && !isAcceptSubmitting.value);

const replaceAccumulatedTicket = (updatedTicket) => {
    if (!updatedTicket?.id) return;

    accumulatedTickets.value = accumulatedTickets.value.map(ticket =>
        ticket.id === updatedTicket.id ? updatedTicket : ticket
    );
};

const createForm = useForm({
    company_id: '',
    store_id: '',
    item_id: '',
    vendor_id: null,
    title: '',
    description: '',
    type: 'task',
    priority: 'medium',
    status: 'open',
    severity: 'minor',
    assignee_id: '',
    attachments: [],
    is_self_requester: true,
    sender_name: '',
    sender_email: '',
    department: page.props.auth.user?.department || '',
    notify_requester: true,
});

const createVendorSelection = computed({
    get: () => createForm.vendor_id === null || createForm.vendor_id === ''
        ? []
        : [createForm.vendor_id],
    set: (vendorIds) => {
        const selectedVendorIds = Array.isArray(vendorIds)
            ? vendorIds.filter(vendorId => vendorId !== null && vendorId !== '')
            : [];

        createForm.vendor_id = selectedVendorIds.at(-1) ?? null;
    },
});

const createDepartmentNodes = computed(() => {
    const references = (props.departmentReferences || [])
        .filter(department => department?.name && department?.is_active !== false)
        .map(department => ({
            id: department.name,
            name: department.name,
            code: department.code,
        }));

    const fallbackDepartments = (props.departments || [])
        .filter(Boolean)
        .map(department => ({
            id: department,
            name: department,
        }));

    const nodes = references.length ? references : fallbackDepartments;
    const currentDepartment = createForm.department || '';
    const hasCurrentDepartment = nodes.some(department => department.id === currentDepartment);

    if (currentDepartment && !hasCurrentDepartment) {
        return [
            {
                id: currentDepartment,
                name: `${currentDepartment} (Legacy)`,
            },
            ...nodes,
        ];
    }

    return nodes;
});

const items = ref([]);

const fetchItems = async () => {
    try {
        const response = await axios.get(route('tickets.data.items', undefined, false));
        items.value = response.data;
    } catch (error) {
        console.error('Error fetching items:', error);
    }
};

watch(() => createForm.item_id, (newVal) => {
    if (newVal) {
        const item = items.value.find(i => i.id === newVal);
        if (item) {
            createForm.priority = item.priority.toLowerCase();
        }
    }
});

// Set default company when modal opens or companies load
watch(() => showCreateModal.value, (isOpen) => {
    if (isOpen && !createForm.company_id) {
        createForm.company_id = defaultCompanyId.value;
    }
    if (isOpen && items.value.length === 0) {
        fetchItems();
    }
});

watch(() => showAcceptModal.value, (isOpen) => {
    if (isOpen && items.value.length === 0) {
        fetchItems();
    }
});

// Auto-populate department from auth user when "I am the requester" is toggled
watch(() => createForm.is_self_requester, (isSelf) => {
    createForm.department = isSelf ? (page.props.auth.user?.department || '') : '';
});

// Also watch defaultCompanyId in case it loads later
watch(defaultCompanyId, (newId) => {
    if (!createForm.company_id) {
        createForm.company_id = newId;
    }
}, { immediate: true });

// ── Bulk Selection ────────────────────────────────────────────────────────
const selectedIds = ref([])
const activeDashboardFilter = ref(props.filters?.dashboard_filter || 'all')

// ── SO / CS Dept Stat Tabs ────────────────────────────────────────────────
const DEPT_TAB_CODES = ['SO', 'CS']

const initialStatDeptTab = () => {
    const selectedNodeId = props.filters?.department_node_id;
    const matchingCode = DEPT_TAB_CODES.find(code =>
        String(props.summaryStatsByDept?.[code]?.id ?? '') === String(selectedNodeId ?? '')
    );

    return matchingCode || 'all';
};

const statDeptTab = ref(initialStatDeptTab())

const deptTabs = computed(() => [
    { key: 'all', label: 'All' },
    ...DEPT_TAB_CODES.map(code => ({
        key: code,
        label: props.summaryStatsByDept?.[code]?.name ?? code,
    })),
])

const EMPTY_STATS = { new: 0, open: 0, unassigned: 0, breached: 0, due_soon: 0, in_progress: 0, total: 0, waiting: 0, urgent: 0, closed: 0 }

const activeStats = computed(() =>
    statDeptTab.value === 'all'
        ? (props.summaryStats ?? {})
        : (props.summaryStatsByDept?.[statDeptTab.value]?.stats ?? EMPTY_STATS)
)

const selectDeptTab = (tabKey) => {
    if (tabKey === 'all') {
        router.get(route('tickets.index'), {
            status: ['all'],
            dashboard_filter: 'all',
            skip_default_department: true,
            ticket_scope: filterTicketScope.value,
        }, { preserveScroll: false });
        return;
    }

    const deptId = props.summaryStatsByDept?.[tabKey]?.id;
    if (!deptId) {
        statDeptTab.value = tabKey;
        return;
    }

    router.get(route('tickets.index'), {
        status: ['all'],
        department_node_id: deptId,
        dashboard_filter: 'all',
        skip_default_department: true,
        assigned_department_only: 1,
        ticket_scope: filterTicketScope.value,
    }, { preserveScroll: false });
};

const allSelected = computed(() =>
    displayedTickets.value.length > 0 &&
    displayedTickets.value.every(t => selectedIds.value.includes(t.id))
)

const toggleAll = () => {
    selectedIds.value = allSelected.value ? [] : displayedTickets.value.map(t => t.id)
}

// Clear selection if a legacy search URL changes. Page growth via infinite
// scroll must not clear the current selection.
watch(() => pagination.search.value, () => {
    selectedIds.value = []
})

const storesWithLabel = computed(() =>
    props.stores.map(s => ({ ...s, display_name: `${s.code} - ${s.name}` }))
)

// ── Bulk Form ─────────────────────────────────────────────────────────────
const bulkForm = reactive({
    store_id: '', item_id: '', department: '', assignee_id: '', status: ''
})
const isBulkSubmitting = ref(false)
const isBulkArchiving = ref(false)

const showSplitModal = ref(false);
const showMergeModal = ref(false);
const showBulkChildModal = ref(false);

const bulkChildForm = useForm({
    tickets: [], // Array of individual ticket schedule data
});

const openBulkChildModal = () => {
    if (selectedIds.value.length === 0) return;
    const selectedTickets = getSelectedTickets.value;
    const selectedChildKeys = selectedTickets
        .filter(t => t.parent_id)
        .map(t => t.ticket_key)
        .filter(Boolean);

    if (selectedChildKeys.length > 0) {
        showError(`Child tickets cannot be used as parent tickets: ${selectedChildKeys.join(', ')}`);
        return;
    }

    bulkChildForm.reset();
    
    // Set default times
    const start = new Date();
    start.setHours(7, 0, 0, 0);
    const startTimeStr = formatDateForInput(start);
    
    const end = new Date(start);
    end.setHours(17, 0, 0, 0);
    const endTimeStr = formatDateForInput(end);

    bulkChildForm.tickets = selectedTickets.map(t => ({
        parent_id: t.id,
        ticket_key: t.ticket_key,
        title: t.title,
        user_id: '',
        status: 'On-site',
        start_time: startTimeStr,
        end_time: endTimeStr,
        pickup_start: '',
        pickup_end: '',
        backlogs_start: '',
        backlogs_end: '',
        remarks: '',
    }));
    
    showBulkChildModal.value = true;
};

const submitBulkChild = () => {
    bulkChildForm.post(route('tickets.bulk-child'), {
        onSuccess: () => {
            showBulkChildModal.value = false;
            selectedIds.value = [];
            showSuccess('Bulk child tickets and schedules created successfully');
        },
        onError: (errors) => {
            const errorMessage = Object.values(errors).flat().join(', ') || 'Bulk child creation failed';
            showError(errorMessage);
        }
    });
};

const formatDateForInput = (date) => {
    const d = new Date(date);
    d.setMinutes(d.getMinutes() - d.getTimezoneOffset());
    return d.toISOString().slice(0, 16);
};

const splitForm = useForm({
    original_title: '',
    new_titles: [''],
});

const mergeForm = useForm({
    parent_id: '',
    ticket_ids: [],
});

const openSplitModal = () => {
    if (selectedIds.value.length !== 1) return;
    const ticket = accumulatedTickets.value.find(t => t.id === selectedIds.value[0]);
    if (!ticket) return;
    
    splitForm.original_title = ticket.title;
    splitForm.new_titles = [''];
    showSplitModal.value = true;
};

const addSplitConcern = () => splitForm.new_titles.push('');
const removeSplitConcern = (index) => splitForm.new_titles.splice(index, 1);

const submitSplit = () => {
    const ticketId = selectedIds.value[0];
    splitForm.transform((data) => ({
        original_title: data.original_title,
        new_titles: data.new_titles.filter(t => t.trim() !== ''),
    })).post(route('tickets.split', ticketId), {
        onSuccess: () => {
            showSplitModal.value = false;
            selectedIds.value = [];
            showSuccess('Ticket split successfully');
        },
        onError: (errors) => showError(Object.values(errors).flat().join(', ') || 'Split failed')
    });
};

const openMergeModal = () => {
    if (selectedIds.value.length < 2) return;
    mergeForm.ticket_ids = [...selectedIds.value];
    mergeForm.parent_id = selectedIds.value[0];
    showMergeModal.value = true;
};

const submitMerge = async () => {
    if (mergeForm.processing) return;

    const selectedTickets = getSelectedTickets.value;
    const parentTicket = selectedTickets.find(ticket => ticket.id === mergeForm.parent_id);
    const childCount = Math.max(selectedIds.value.length - 1, 0);
    const confirmed = await confirm({
        title: 'Confirm Ticket Merge',
        message: `Merge ${selectedIds.value.length} selected ticket(s) with ${parentTicket?.ticket_key || 'the selected parent'} as the parent? ${childCount} ticket(s) will become merged child tickets.`,
        confirmLabel: `Merge ${selectedIds.value.length} Tickets`,
        cancelLabel: 'Cancel',
        variant: 'warning'
    });

    if (!confirmed) return;

    mergeForm.post(route('tickets.merge'), {
        onSuccess: () => {
            showMergeModal.value = false;
            selectedIds.value = [];
            showSuccess('Tickets merged successfully');
        },
        onError: (errors) => showError(Object.values(errors).flat().join(', ') || 'Merge failed')
    });
};

const getSelectedTickets = computed(() => {
    return accumulatedTickets.value.filter(t => selectedIds.value.includes(t.id));
});

const canCreateChildTickets = computed(() =>
    selectedIds.value.length > 0 && getSelectedTickets.value.every(ticket => !ticket.parent_id)
);

const showBulkResponseModal = ref(false);
const showBulkCannedMessages = ref(false);
const bulkResponseFileInput = ref(null);
const bulkResponseForm = useForm({
    ticket_ids: [],
    comment_text: '',
    attachments: [],
});

const selectedClosedTicketKeys = computed(() =>
    getSelectedTickets.value
        .filter(ticket => ticket.status === 'closed')
        .map(ticket => ticket.ticket_key)
        .filter(Boolean)
);

const hasBulkResponseContent = computed(() =>
    Boolean(bulkResponseForm.comment_text.trim() || bulkResponseForm.attachments.length > 0)
);

const applyBulkCannedMessage = (message) => {
    bulkResponseForm.comment_text = bulkResponseForm.comment_text
        ? `${bulkResponseForm.comment_text}\n${message.content}`
        : message.content;
    showBulkCannedMessages.value = false;
};

const openBulkResponseModal = () => {
    if (selectedIds.value.length === 0) return;

    if (selectedClosedTicketKeys.value.length > 0) {
        showError(`Closed tickets cannot receive responses. Please deselect: ${selectedClosedTicketKeys.value.join(', ')}`);
        return;
    }

    bulkResponseForm.reset();
    bulkResponseForm.clearErrors();
    showBulkCannedMessages.value = false;
    showBulkResponseModal.value = true;
};

const closeBulkResponseModal = () => {
    showBulkResponseModal.value = false;
    showBulkCannedMessages.value = false;
    bulkResponseForm.reset();
    bulkResponseForm.clearErrors();
    if (bulkResponseFileInput.value) bulkResponseFileInput.value.value = '';
};

const handleBulkResponseFileSelect = (event) => {
    const files = Array.from(event.target.files);
    const maxSize = 1000 * 1024 * 1024; // 1GB, matching the ticket response composer
    const oversizedFiles = files.filter(file => file.size > maxSize);
    const validFiles = files.filter(file => file.size <= maxSize);

    if (oversizedFiles.length > 0) {
        showError(`The following files exceed the 1GB limit and were not added: ${oversizedFiles.map(file => file.name).join(', ')}`);
    }

    bulkResponseForm.attachments = [...bulkResponseForm.attachments, ...validFiles];
    event.target.value = '';
};

const handleBulkResponsePaste = (event) => {
    const items = event.clipboardData?.items || event.originalEvent?.clipboardData?.items || [];
    const maxSize = 1000 * 1024 * 1024; // 1GB

    for (const item of items) {
        if (item.type.indexOf('image') !== -1 || item.type.indexOf('video') !== -1) {
            const blob = item.getAsFile();
            if (!blob) continue;

            if (blob.size > maxSize) {
                showError('Pasted media exceeds the 1GB limit.');
                continue;
            }

            const ext = item.type.split('/')[1] || 'png';
            bulkResponseForm.attachments.push(new File([blob], `pasted-media-${Date.now()}.${ext}`, { type: blob.type }));
        }
    }
};

const removeBulkResponseAttachment = (index) => {
    bulkResponseForm.attachments.splice(index, 1);
};

const submitBulkResponse = () => {
    if (!hasBulkResponseContent.value || bulkResponseForm.processing) return;

    if (selectedClosedTicketKeys.value.length > 0) {
        showError(`Closed tickets cannot receive responses. Please deselect: ${selectedClosedTicketKeys.value.join(', ')}`);
        return;
    }

    bulkResponseForm.ticket_ids = [...selectedIds.value];
    bulkResponseForm.post(route('tickets.bulk-response'), {
        preserveScroll: true,
        onSuccess: () => {
            showBulkResponseModal.value = false;
            showBulkCannedMessages.value = false;
            bulkResponseForm.reset();
            selectedIds.value = [];
            if (bulkResponseFileInput.value) bulkResponseFileInput.value.value = '';
        },
        onError: (errors) => {
            const errorMessage = Object.values(errors).flat().join(', ') || 'Bulk response failed';
            showError(errorMessage);
        }
    });
};

watch(() => selectedIds.value.length > 0, (visible) => {
    if (visible && items.value.length === 0) {
        fetchItems();
    }
})

const submitBulk = () => {
    if (!selectedIds.value.length || isBulkSubmitting.value) return
    isBulkSubmitting.value = true
    const payload = { ticket_ids: selectedIds.value }
    if (bulkForm.store_id)        payload.store_id        = bulkForm.store_id
    if (bulkForm.item_id)         payload.item_id         = bulkForm.item_id
    if (bulkForm.department)      payload.department      = bulkForm.department
    if (bulkForm.assignee_id)     payload.assignee_id     = bulkForm.assignee_id
    if (bulkForm.status)          payload.status          = bulkForm.status

    post(route('tickets.bulk-update'), payload, {
        onSuccess: () => {
            selectedIds.value = []
            Object.keys(bulkForm).forEach(k => bulkForm[k] = '')
        },
        onError: (errors) => showError(Object.values(errors).flat().join(', ') || 'Bulk update failed'),
        onFinish: () => { isBulkSubmitting.value = false }
    })
}

const submitBulkArchive = async () => {
    if (!selectedIds.value.length || isBulkArchiving.value) return

    const confirmed = await confirm({
        title: 'Archive Selected Tickets',
        message: `Archive ${selectedIds.value.length} selected ticket(s)? Archived tickets can be restored from Ticket Archive.`,
        confirmLabel: 'Archive',
        cancelLabel: 'Cancel',
        variant: 'danger'
    })

    if (!confirmed) return

    isBulkArchiving.value = true

    post(route('tickets.bulk-archive'), { ticket_ids: selectedIds.value }, {
        preserveScroll: true,
        onSuccess: () => {
            selectedIds.value = []
        },
        onError: (errors) => showError(Object.values(errors).flat().join(', ') || 'Bulk archive failed'),
        onFinish: () => { isBulkArchiving.value = false }
    })
}

const priorities = ['low', 'medium', 'high', 'urgent'];
const statuses = ['open', 'for_schedule', 'in_progress', 'resolved', 'closed', 'waiting_service_provider', 'waiting_client_feedback'];

const bulkStatuses = computed(() => {
    return statuses.filter(s => s !== 'resolved' && s !== 'closed');
});

const handleFileSelect = (event) => {
    const files = Array.from(event.target.files);
    const maxSize = 50 * 1024 * 1024; // 50MB
    const oversizedFiles = files.filter(file => file.size > maxSize);

    if (oversizedFiles.length > 0) {
        showError(`The following files exceed the 50MB limit: ${oversizedFiles.map(f => f.name).join(', ')}`);
        event.target.value = '';
        createForm.attachments = [];
        return;
    }

    createForm.attachments = files;
};

const createTicket = () => {
    if (isSubmitting.value) return;
    isSubmitting.value = true;

    const formData = new FormData();
    Object.keys(createForm.data()).forEach(key => {
        if (key === 'attachments') {
            createForm.attachments.forEach((file, index) => {
                formData.append(`attachments[${index}]`, file);
            });
        } else {
            let value = createForm[key];
            if (typeof value === 'boolean') {
                value = value ? 1 : 0;
            }
            if (value === null || value === undefined || value === '') {
                return; // skip — backend treats missing nullable fields as null
            }
            formData.append(key, value);
        }
    });
    
    post(route('tickets.store'), formData, {
        onSuccess: () => {
            showCreateModal.value = false;
            createForm.reset();
            if (fileInput.value) fileInput.value.value = '';
        },
        onError: (errors) => {
            const errorMessage = Object.values(errors).flat().join(', ') || 'An error occurred'
            showError(errorMessage)
        },
        onFinish: () => {
            isSubmitting.value = false;
        }
    });
};

const formatFileSize = (bytes) => {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
};

const editTicket = (ticket) => {
    if (!hasPermission('tickets.edit')) {
        showError('You do not have permission to edit this ticket.');
        return;
    }
    router.visit(route('tickets.edit', ticket.id));
};

const openInNewTab = (ticket) => {
    if (!hasPermission('tickets.edit')) {
        showError('You do not have permission to edit this ticket.');
        return;
    }
    window.open(route('tickets.edit', ticket.id), '_blank');
};

const handleAuxClick = (event, ticket) => {
    if (event.button === 1) { // Middle click
        event.preventDefault();
        openInNewTab(ticket);
    }
};

const acceptTicket = (ticket) => {
    if (!hasPermission('tickets.assign')) {
        showError('You do not have permission to accept tickets.');
        return;
    }
    acceptingTicket.value = ticket;
    acceptForm.company_id = ticket.company_id || '';
    acceptForm.store_id = ticket.store_id || '';
    acceptForm.item_id = ticket.item_id || '';
    acceptForm.department = ticket.department || '';
    showAcceptModal.value = true;
};

const submitAcceptTicket = () => {
    if (!acceptingTicket.value || isAcceptSubmitting.value) return;

    if (!hasCompleteAcceptForm.value) {
        showError('Please complete company, store, item, and department before accepting the ticket.');
        return;
    }

    isAcceptSubmitting.value = true;
    const ticket = acceptingTicket.value;

    axios.post(route('tickets.accept', ticket.id), {
        company_id: acceptForm.company_id,
        store_id: acceptForm.store_id,
        item_id: acceptForm.item_id,
        department: acceptForm.department,
    })
        .then(({ data }) => {
            replaceAccumulatedTicket(data.ticket);
            showAcceptModal.value = false;
            acceptingTicket.value = null;
            acceptForm.reset();
            showSuccess(data.message || 'Ticket accepted successfully.');
        })
        .catch((error) => {
            const errors = error.response?.data?.errors;
            const message = errors
                ? Object.values(errors).flat().join(', ')
                : (error.response?.data?.message || 'Cannot accept ticket');

            showError(message);
        })
        .finally(() => {
            isAcceptSubmitting.value = false;
        });
};



const priorityMap = {
    'urgent': 'P1',
    'high': 'P2',
    'medium': 'P3',
    'low': 'P4'
};

const getPriorityLabel = (priority) => {
    const p = String(priority || '').toLowerCase();
    return priorityMap[p] ? `${priorityMap[p]} ${p}` : p;
};

const getPriorityColor = (priority) => {
    const p = String(priority || '').toLowerCase();
    switch (p) {
        case 'urgent': return 'border-red-500 text-black bg-white dark:bg-slate-900 dark:text-red-100';
        case 'high': return 'border-orange-400 text-black bg-white dark:bg-slate-900 dark:text-orange-100';
        case 'medium': return 'border-yellow-400 text-black bg-white dark:bg-slate-900 dark:text-yellow-100';
        case 'low': return 'border-green-500 text-black bg-white dark:bg-slate-900 dark:text-green-100';
        default: return 'border-slate-300 text-black bg-white dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100';
    }
};

const getPriorityBorder = (priority) => {
    return 'border-l-transparent';
};

const getStatusColor = (status) => {
    switch (status) {
        case 'open': return 'border-blue-500 text-black bg-white dark:bg-slate-900 dark:text-blue-100';
        case 'for_schedule': return 'border-teal-500 text-black bg-white dark:bg-slate-900 dark:text-teal-100';
        case 'in_progress': return 'border-violet-500 text-black bg-white dark:bg-slate-900 dark:text-violet-100';
        case 'resolved': return 'border-green-500 text-black bg-white dark:bg-slate-900 dark:text-green-100';
        case 'closed': return 'border-slate-400 text-black bg-white dark:border-slate-500 dark:bg-slate-900 dark:text-slate-100';
        case 'waiting_service_provider': return 'border-orange-400 text-black bg-white dark:bg-slate-900 dark:text-orange-100';
        case 'waiting_client_feedback': return 'border-sky-500 text-black bg-white dark:bg-slate-900 dark:text-sky-100';
        default: return 'border-slate-300 text-black bg-white dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100';
    }
};

const getStatusLabel = (status) => {
    switch (status) {
        case 'for_schedule': return 'For Schedule';
        case 'waiting_service_provider': return 'Waiting for service provider';
        case 'waiting_client_feedback': return 'Waiting for Client\'s Feedback';
        default: return String(status || '').replace(/_/g, ' ');
    }
};

const getSlaRowClass = (ticket) => {
    if (!ticket.sla_metric) return 'border-l-transparent hover:bg-slate-50 dark:hover:bg-slate-800/70';
    
    const isBreached = ticket.sla_metric.is_response_breached || ticket.sla_metric.is_resolution_breached;
    const isAllMet = ticket.sla_metric.first_response_at && ticket.sla_metric.resolved_at;
    
    if (isBreached) return 'border-l-transparent hover:bg-slate-50 dark:hover:bg-slate-800/70';
    if (isAllMet) return 'border-l-transparent hover:bg-slate-50 dark:hover:bg-slate-800/70';

    const priority = ticket.item?.priority?.toLowerCase() || ticket.priority?.toLowerCase();

    return getPriorityBorder(priority) + ' hover:bg-slate-50 dark:hover:bg-slate-800/70';
};

const formatItemName = (item) => {
    if (!item) return '-';
    const cat = item.category?.name ?? 'N/A';
    const sub = item.sub_category?.name ?? 'N/A';
    return `${cat} | ${sub} | ${item.name}`;
};

const isCctvTicket = (ticket) => {
    return ticket.item?.category?.name === 'CCTV' || ticket.item?.name?.toLowerCase().startsWith('cctv');
};

const getReporterLabel = (ticket) => {
    if (ticket.reporter?.name) return ticket.reporter.name;
    if (ticket.sender_name) return ticket.sender_name;
    if (ticket.sender_email) return ticket.sender_email;
    return 'Unknown';
};

const getTicketResponsibilityLabel = (ticket) => {
    if (ticket?.vendor?.name) return `Vendor - ${ticket.vendor.name}`;
    return ticket?.assignee?.name || 'Unassigned';
};

const hasBreachedSla = (ticket) => {
    return Boolean(ticket.sla_metric && (ticket.sla_metric.is_response_breached || ticket.sla_metric.is_resolution_breached));
};

const isTicketNearlyDue = (ticket) => {
    if (!ticket.sla_metric) return false;

    const responseNearlyDue = Boolean(
        ticket.sla_metric.response_target_at &&
        !ticket.sla_metric.first_response_at &&
        !ticket.sla_metric.is_response_breached &&
        isNearlyDue(ticket.sla_metric.response_target_at)
    );

    const resolutionNearlyDue = Boolean(
        ticket.sla_metric.resolution_target_at &&
        !ticket.sla_metric.resolved_at &&
        !ticket.sla_metric.is_resolution_breached &&
        isNearlyDue(ticket.sla_metric.resolution_target_at)
    );

    return responseNearlyDue || resolutionNearlyDue;
};

const isNewTicket = (ticket) => {
    return ticket?.status === 'open'
        && !ticket?.category_id
        && !ticket?.sub_category_id
        && !ticket?.item_id
        && !ticket?.assignee;
};

const summaryCards = computed(() => {
    const stats = activeStats.value || {};

    if (statDeptTab.value !== 'all') {
        return [
            {
                key: 'new', filterKey: 'new', label: 'New',
                value: stats.new ?? 0,
                hint: 'Open, uncategorized, and assigned',
                shellClass: 'border-white/10 bg-white/5 hover:bg-white/10',
                valueClass: 'text-white', labelClass: 'text-blue-300', hintClass: 'text-slate-400',
                accentClass: 'bg-blue-400 shadow-[0_0_8px_rgba(96,165,250,0.6)]',
            },
            {
                key: 'open', filterKey: 'open', label: 'Open',
                value: stats.open ?? 0,
                hint: 'Assigned open tickets',
                shellClass: 'border-white/10 bg-white/5 hover:bg-white/10',
                valueClass: 'text-white', labelClass: 'text-emerald-300', hintClass: 'text-slate-400',
                accentClass: 'bg-emerald-400 shadow-[0_0_8px_rgba(52,211,153,0.6)]',
            },
            {
                key: 'waiting', filterKey: 'waiting', label: 'Waiting',
                value: stats.waiting ?? 0,
                hint: 'Awaiting service provider or client feedback',
                shellClass: 'border-white/10 bg-white/5 hover:bg-white/10',
                valueClass: 'text-white', labelClass: 'text-amber-400', hintClass: 'text-slate-400',
                accentClass: 'bg-amber-400 shadow-[0_0_8px_rgba(251,191,36,0.6)]',
            },
            {
                key: 'urgent', filterKey: 'urgent', label: 'Urgent (P1)',
                value: stats.urgent ?? 0,
                hint: 'Critical priority tickets',
                shellClass: 'border-white/10 bg-white/5 hover:bg-white/10',
                valueClass: 'text-white', labelClass: 'text-red-400', hintClass: 'text-slate-400',
                accentClass: 'bg-red-500 shadow-[0_0_8px_rgba(239,68,68,0.6)]',
            },
            {
                key: 'closed', filterKey: 'closed', label: 'Closed',
                value: stats.closed ?? 0,
                hint: 'Resolved and closed tickets',
                shellClass: 'border-white/10 bg-white/5 hover:bg-white/10',
                valueClass: 'text-white', labelClass: 'text-slate-300', hintClass: 'text-slate-400',
                accentClass: 'bg-slate-400',
            },
        ];
    }

    return [
        {
            key: 'new',
            filterKey: 'new',
            label: 'New',
            value: stats.new ?? 0,
            hint: 'Open, uncategorized, and unassigned',
            shellClass: 'border-white/10 bg-white/5 hover:bg-white/10',
            valueClass: 'text-white',
            labelClass: 'text-blue-300',
            hintClass: 'text-slate-400',
            accentClass: 'bg-blue-400 shadow-[0_0_8px_rgba(96,165,250,0.6)]',
        },
        {
            key: 'unassigned',
            filterKey: 'unassigned',
            label: 'Unassigned',
            value: stats.unassigned ?? 0,
            hint: 'Tickets waiting for ownership',
            shellClass: 'border-white/10 bg-white/5 hover:bg-white/10',
            valueClass: 'text-white',
            labelClass: 'text-slate-300',
            hintClass: 'text-slate-400',
            accentClass: 'bg-slate-400',
        },
        {
            key: 'breached',
            filterKey: 'breached',
            label: 'SLA Breached',
            value: stats.breached ?? 0,
            hint: 'Immediate follow-up required',
            shellClass: 'border-white/10 bg-white/5 hover:bg-white/10',
            valueClass: 'text-white',
            labelClass: 'text-red-400',
            hintClass: 'text-slate-400',
            accentClass: 'bg-red-500 shadow-[0_0_8px_rgba(239,68,68,0.6)]',
        },
        {
            key: 'nearly_due',
            filterKey: 'due_soon',
            label: 'Due Soon',
            value: stats.due_soon ?? 0,
            hint: 'Targets due within one hour',
            shellClass: 'border-white/10 bg-white/5 hover:bg-white/10',
            valueClass: 'text-white',
            labelClass: 'text-amber-400',
            hintClass: 'text-slate-400',
            accentClass: 'bg-amber-400 shadow-[0_0_8px_rgba(251,191,36,0.6)]',
        },
        {
            key: 'in_progress',
            filterKey: 'in_progress',
            label: 'In Progress',
            value: stats.in_progress ?? 0,
            hint: 'Actively being worked on',
            shellClass: 'border-white/10 bg-white/5 hover:bg-white/10',
            valueClass: 'text-white',
            labelClass: 'text-emerald-400',
            hintClass: 'text-slate-400',
            accentClass: 'bg-emerald-400 shadow-[0_0_8px_rgba(52,211,153,0.6)]',
        },
    ];
});

// Quick filters are applied server-side before pagination. Filtering a second
// time here caused visible rows to disagree with the server total and could
// hide valid rows because of client/server SLA timing differences.
const displayedTickets = computed(() => accumulatedTickets.value || []);

const getDashboardFilterLabel = (filterKey) => {
    switch (filterKey) {
        case 'new': return 'Quick Filter: New';
        case 'unassigned': return 'Quick Filter: Unassigned';
        case 'breached': return 'Quick Filter: SLA Breached';
        case 'due_soon': return 'Quick Filter: Due Soon';
        case 'in_progress': return 'Quick Filter: In Progress';
        case 'open':        return 'Quick Filter: Open';
        case 'total':       return 'Quick Filter: Total';
        case 'waiting':     return 'Quick Filter: Waiting';
        case 'urgent':      return 'Quick Filter: Urgent (P1)';
        case 'closed':      return 'Quick Filter: Closed';
        default: return '';
    }
};

const DEPT_UNFILTERED_KEYS = ['waiting', 'urgent', 'closed'];

const toggleDashboardFilter = (filterKey) => {
    if (statDeptTab.value !== 'all') {
        const deptId = props.summaryStatsByDept?.[statDeptTab.value]?.id;
        if (deptId) {
            const isActive = activeDashboardFilter.value === filterKey;
            const params = {
                department_node_id: deptId,
                dashboard_filter: isActive ? 'all' : filterKey,
                skip_default_department: true,
                assigned_department_only: 1,
                ticket_scope: filterTicketScope.value,
            };
            // These boxes count against an unfiltered base (no default status=open),
            // so pass status=all to prevent the backend from pre-excluding statuses.
            if (!isActive && DEPT_UNFILTERED_KEYS.includes(filterKey)) {
                params.status = ['all'];
            }
            router.get(route('tickets.index'), params, { preserveScroll: false });
            return;
        }
    }
    const isClearing = activeDashboardFilter.value === filterKey;
    activeDashboardFilter.value = isClearing ? 'all' : filterKey;
    // A quick filter is an alternate status view. Do not intersect e.g.
    // "In Progress" or "Closed" with the normal default status of "Open".
    filterStatus.value = isClearing ? defaultStatusFilters() : ['all'];
    pagination.currentPage.value = 1;
    applyFilter();
};

const activeFilterBadges = computed(() => {
    const badges = [];

    const selectedStatuses = filterStatus.value.filter(status => status !== 'all');
    if (selectedStatuses.length) {
        badges.push(`Status: ${formatFilterBadgeValues(selectedStatuses, getStatusLabel)}`);
    }
    if (filterNodeId.value) {
        const nodeLabel = filterNodeId.value.toString().startsWith('dept-')
            ? (props.hierarchicalDepartments || []).find(d => String(d.id) === filterNodeId.value.replace('dept-', ''))?.name ?? filterNodeId.value
            : (function findName(nodes) {
                for (const n of nodes) {
                    if (String(n.id) === String(filterNodeId.value)) return n.name
                    if (n.nodes?.length) { const f = findName(n.nodes); if (f) return f }
                }
                return filterNodeId.value
              })(props.hierarchicalDepartments || [])
        badges.push(`Department: ${nodeLabel}`)
    }
    if (filterAssignee.value.length) {
        badges.push(`Assignee: ${formatFilterBadgeValues(filterAssignee.value, getAssigneeFilterLabel)}`);
    }
    if (filterRequesterKeys.value.length) {
        badges.push(`Requester: ${formatFilterBadgeValues(filterRequesterKeys.value, getRequesterFilterLabel)}`);
    }
    if (filterStore.value.length) {
        badges.push(`Location: ${formatFilterBadgeValues(filterStore.value, getStoreFilterLabel)}`);
    }
    if (filterSubCategory.value) {
        const sub = (props.subCategories || []).find(s => String(s.id) === String(filterSubCategory.value));
        badges.push(`SubCategory: ${sub?.name ?? filterSubCategory.value}`);
    }
    if (filterStartDate.value) {
        badges.push(`From: ${filterStartDate.value}`);
    }
    if (filterEndDate.value) {
        badges.push(`To: ${filterEndDate.value}`);
    }
    if (filterTicketScope.value !== defaultTicketScope) {
        badges.push(`Ticket Type: ${getTicketScopeLabel(filterTicketScope.value)}`);
    }
    if (pagination.search.value) {
        badges.push(`Search: ${pagination.search.value}`);
    }
    if (activeDashboardFilter.value !== 'all') {
        badges.push(getDashboardFilterLabel(activeDashboardFilter.value));
    }
    if (filterTicketKeys.value.length) {
        badges.push(`Ticket #: ${formatFilterBadgeValues(filterTicketKeys.value)}`);
    }

    return badges;
});

const formatFilterBadgeValues = (values, formatter = (value) => value) => {
    const labels = values.map(formatter).filter(Boolean);

    if (labels.length <= 2) {
        return labels.join(', ');
    }

    return `${labels.slice(0, 2).join(', ')} +${labels.length - 2}`;
};

const getAssigneeFilterLabel = (assigneeId) => {
    const assignee = props.staff?.find(staff => String(staff.id) === String(assigneeId));
    return assignee?.name || assigneeId;
};

const getRequesterFilterLabel = (requesterKey) => {
    const requester = props.requesterOptions.find(option => option.value === requesterKey);
    return requester?.label || requesterKey;
};

const getStoreFilterLabel = (storeId) => {
    const store = props.stores?.find(s => String(s.id) === String(storeId));
    return store?.name || storeId;
};

const hasActiveFilters = computed(() => activeFilterBadges.value.length > 0);

const tableSubtitle = computed(() => {
    const visibleCount = displayedTickets.value?.length || 0;
    if (hasActiveFilters.value) {
        return `Focused monitoring for ${visibleCount} visible ticket${visibleCount === 1 ? '' : 's'}. Click a row to open details.`;
    }

    return 'Monitor queue health, SLA pressure, ownership, and ticket hierarchy. Click a row to open details.';
});

const emptyStateMessage = computed(() => {
    if (hasActiveFilters.value) {
        return 'No tickets match the current filters. Adjust the monitoring controls and try again.';
    }

    return 'No tickets are visible right now. Create a new ticket to start monitoring the queue.';
});

const getSlaState = (ticket, type) => {
    const metric = ticket.sla_metric;
    if (!metric) return null;

    const isResponse = type === 'response';
    const targetAt = isResponse ? metric.response_target_at : metric.resolution_target_at;
    const completedAt = isResponse ? metric.first_response_at : metric.resolved_at;
    const isBreached = isResponse ? metric.is_response_breached : metric.is_resolution_breached;

    if (!targetAt) {
        return {
            label: isResponse ? 'Response' : 'Resolution',
            value: 'No Target',
            toneClass: 'border-gray-300 bg-white text-black dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100',
            dotClass: 'bg-gray-300',
        };
    }

    if (isBreached) {
        return {
            label: isResponse ? 'Response' : 'Resolution',
            value: 'Breached',
            toneClass: 'border-red-500 bg-white text-black dark:bg-slate-900 dark:text-red-100',
            dotClass: 'bg-red-500',
        };
    }

    if (completedAt) {
        return {
            label: isResponse ? 'Response' : 'Resolution',
            value: 'Met',
            toneClass: 'border-emerald-500 bg-white text-black dark:bg-slate-900 dark:text-emerald-100',
            dotClass: 'bg-emerald-500',
        };
    }

    if (isNearlyDue(targetAt)) {
        return {
            label: isResponse ? 'Response' : 'Resolution',
            value: 'Due Soon',
            toneClass: 'border-amber-500 bg-white text-black dark:bg-slate-900 dark:text-amber-100',
            dotClass: 'bg-amber-500',
        };
    }

    return {
        label: isResponse ? 'Response' : 'Resolution',
        value: 'Pending',
        toneClass: 'border-blue-500 bg-white text-black dark:bg-slate-900 dark:text-blue-100',
        dotClass: 'bg-blue-500',
    };
};

watch(activeDashboardFilter, () => {
    selectedIds.value = [];
});

const showRequesterModal = ref(false);
const requesterModalData = ref(null);
const requesterTickets = ref([]);
const isRequesterTicketsLoading = ref(false);
const requesterActiveTab = ref('open');

const openRequesterTicketsModal = async (ticket) => {
    requesterModalData.value = {
        name: ticket.reporter?.name || ticket.sender_name || 'Requester',
        email: ticket.reporter?.email || ticket.sender_email || ''
    };
    requesterTickets.value = [];
    requesterActiveTab.value = 'open';
    showRequesterModal.value = true;
    isRequesterTicketsLoading.value = true;

    try {
        const params = {};
        if (ticket.reporter_id) {
            params.reporter_id = ticket.reporter_id;
        } else if (ticket.sender_email) {
            params.email = ticket.sender_email;
        }
        const response = await axios.get(route('tickets.data.requester'), { params });
        requesterTickets.value = response.data;
    } catch (e) {
        console.error(e);
        showError('Failed to load tickets');
    } finally {
        isRequesterTicketsLoading.value = false;
    }
};

const closeRequesterModal = () => {
    showRequesterModal.value = false;
};

const goToTicket = (ticketId) => {
    router.visit(route('tickets.edit', ticketId));
};

const filteredRequesterTickets = computed(() => {
    if (requesterActiveTab.value === 'all') return requesterTickets.value;
    return requesterTickets.value.filter(t => t.status === requesterActiveTab.value);
});

const requesterTabs = computed(() => {
    const statuses = ['open', 'in_progress', 'pending', 'resolved', 'closed'];
    const counts = {};
    statuses.forEach(s => counts[s] = 0);
    counts['all'] = requesterTickets.value.length;
    
    requesterTickets.value.forEach(t => {
        if (counts[t.status] !== undefined) {
            counts[t.status]++;
        } else {
            counts[t.status] = 1;
        }
    });

    return [
        { id: 'open', name: 'Open', count: counts['open'] || 0 },
        { id: 'in_progress', name: 'In Progress', count: counts['in_progress'] || 0 },
        { id: 'pending', name: 'Pending', count: counts['pending'] || 0 },
        { id: 'resolved', name: 'Resolved', count: counts['resolved'] || 0 },
        { id: 'closed', name: 'Closed', count: counts['closed'] || 0 },
        { id: 'all', name: 'All', count: counts['all'] },
    ];
});
</script>

<template>
    <Head title="Ticket Monitoring Board" />
    <AppLayout content-class="w-full max-w-none px-2 sm:px-4 lg:px-6 min-w-fit" main-class="overflow-auto">
        <template #header>
            Tickets
        </template>

        <div class="space-y-6 min-w-fit">
            <section class="hidden sm:block relative overflow-hidden rounded-2xl border border-slate-200 bg-gradient-to-br from-slate-950 via-slate-900 to-blue-950 px-4 py-3.5 text-white shadow-lg sm:px-5">
                <div class="absolute inset-0 bg-[radial-gradient(circle_at_top_right,rgba(96,165,250,0.25),transparent_28%),radial-gradient(circle_at_bottom_left,rgba(45,212,191,0.18),transparent_30%)]"></div>
                <div class="relative flex flex-col gap-3">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex items-center gap-3">
                            <span class="inline-flex shrink-0 items-center rounded-full border border-white/15 bg-white/10 px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-[0.2em] text-blue-100">
                                Ticket Monitoring
                            </span>
                            <h2 class="text-base font-bold tracking-tight text-white sm:text-lg">Live queue triage — urgency, ownership &amp; SLA pressure.</h2>
                        </div>
                        <div class="text-[11px] font-medium text-slate-400 sm:text-right">
                            Scope: <span class="text-slate-200">{{ ticketsShowingText }}</span>
                        </div>
                    </div>

                    <!-- Department view selector + stat cards -->
                    <div class="space-y-2.5">
                        <!-- Dept tabs — 3 equal cards -->
                        <div class="grid grid-cols-3 gap-2">
                            <button
                                v-for="tab in deptTabs"
                                :key="tab.key"
                                type="button"
                                @click="selectDeptTab(tab.key)"
                                class="relative flex items-center gap-2 overflow-hidden rounded-xl border px-3 py-2 text-left transition-all duration-300 focus:outline-none"
                                :class="statDeptTab === tab.key
                                    ? 'border-white/20 bg-white/10 shadow-md shadow-black/20 ring-1 ring-white/10'
                                    : 'border-white/5 bg-white/5 hover:bg-white/10 hover:border-white/15'"
                            >
                                <!-- Colored left accent dot -->
                                <span class="h-1.5 w-1.5 shrink-0 rounded-full transition-all"
                                    :class="statDeptTab === tab.key
                                        ? (tab.key === 'CS' ? 'bg-emerald-400 shadow-[0_0_8px_rgba(52,211,153,0.8)]' : 'bg-blue-400 shadow-[0_0_8px_rgba(96,165,250,0.8)]')
                                        : 'bg-white/20'"
                                ></span>
                                <div class="min-w-0">
                                    <div class="text-[9px] font-bold uppercase tracking-widest leading-none"
                                        :class="statDeptTab === tab.key ? 'text-slate-300' : 'text-slate-500'">
                                        {{ tab.key === 'all' ? 'All Departments' : tab.key }}
                                    </div>
                                    <div class="mt-0.5 truncate text-sm font-light leading-tight text-white">
                                        {{ tab.label }}
                                    </div>
                                </div>
                            </button>
                        </div>

                        <!-- Stat cards for the active dept -->
                        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 xl:grid-cols-5">
                            <button
                                v-for="card in summaryCards"
                                :key="card.key"
                                type="button"
                                class="rounded-xl border px-3 py-2.5 text-left shadow-sm backdrop-blur-md transition-all duration-300 hover:-translate-y-0.5 hover:shadow-lg"
                                :class="[
                                    card.shellClass,
                                    activeDashboardFilter === card.filterKey ? 'ring-1 ring-white/30 border-white/30 bg-white/10' : ''
                                ]"
                                @click="toggleDashboardFilter(card.filterKey)"
                            >
                                <div class="flex items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <div class="text-[9px] font-bold uppercase tracking-widest" :class="card.labelClass">{{ card.label }}</div>
                                        <div class="mt-1 text-2xl font-light leading-none tracking-tight" :class="card.valueClass">{{ card.value }}</div>
                                        <div class="mt-1 truncate text-[11px] font-medium" :class="card.hintClass">
                                            {{ activeDashboardFilter === card.filterKey ? 'Showing matches below' : card.hint }}
                                        </div>
                                    </div>
                                    <span class="mt-0.5 h-2 w-2 shrink-0 rounded-full" :class="card.accentClass"></span>
                                </div>
                            </button>
                        </div>
                    </div>
                </div>
            </section>

            <div class="space-y-2 sm:space-y-4 mb-6 relative z-20">
                <div class="rounded-2xl border border-slate-200 bg-white/95 p-2 sm:p-4 shadow-lg shadow-slate-200/60 backdrop-blur supports-[backdrop-filter]:bg-white/85 dark:border-slate-700 dark:bg-slate-900/95 dark:shadow-black/30 dark:supports-[backdrop-filter]:bg-slate-900/85">
                    <div class="flex flex-col gap-2 sm:gap-4 xl:flex-row xl:items-end">
                        <div class="grid flex-1 grid-cols-2 gap-2 sm:gap-4 md:grid-cols-4 xl:grid-cols-4">
                            <div class="flex flex-col gap-1.5">
                                <label class="hidden sm:block text-[10px] font-black uppercase tracking-[0.22em] text-slate-500 dark:text-slate-300">Status</label>
                                <MultiAutocomplete
                                    :model-value="filterStatus"
                                    :options="statusOptions"
                                    label-key="name"
                                    value-key="id"
                                    placeholder="Status..."
                                    :limit="1"
                                    @update:modelValue="handleStatusFilterChange"
                                />
                            </div>

                            <div class="flex flex-col gap-1.5">
                                <label class="hidden sm:block text-[10px] font-black uppercase tracking-[0.22em] text-slate-500 dark:text-slate-300">Ticket Type</label>
                                <select
                                    v-model="filterTicketScope"
                                    @change="handleTicketScopeChange"
                                    class="h-[38px] rounded-lg border-slate-300 bg-white text-sm font-semibold text-slate-700 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                                >
                                    <option v-for="option in ticketScopeOptions" :key="option.value" :value="option.value">
                                        {{ option.label }}
                                    </option>
                                </select>
                            </div>

                            <div class="flex flex-col gap-1.5">
                                <label class="hidden sm:block text-[10px] font-black uppercase tracking-[0.22em] text-slate-500 dark:text-slate-300">Ticket #</label>
                                <MultiAutocomplete
                                    :model-value="filterTicketKeys"
                                    :options="ticketKeyFilterOptions"
                                    label-key="label"
                                    value-key="value"
                                    placeholder="Ticket #..."
                                    :limit="1"
                                    @update:modelValue="handleTicketKeyFilterChange"
                                />
                            </div>

                            <div class="flex flex-col gap-1.5">
                                <label class="hidden sm:block text-[10px] font-black uppercase tracking-[0.22em] text-slate-500 dark:text-slate-300">Requester</label>
                                <MultiAutocomplete
                                    :model-value="filterRequesterKeys"
                                    :options="requesterOptions"
                                    label-key="label"
                                    value-key="value"
                                    placeholder="Requester..."
                                    :limit="1"
                                    @update:modelValue="handleRequesterFilterChange"
                                />
                            </div>

                            <div v-if="hierarchicalOptions.length > 0" class="flex flex-col gap-1.5">
                                <label class="hidden sm:block text-[10px] font-black uppercase tracking-[0.22em] text-slate-500 dark:text-slate-300">Department</label>
                                <HierarchySelector
                                    v-model="filterNodeId"
                                    :nodes="hierarchicalOptions"
                                    placeholder="All Departments / Teams..."
                                    @update:modelValue="applyFilter"
                                />
                            </div>

                            <div class="flex flex-col gap-1.5">
                                <label class="hidden sm:block text-[10px] font-black uppercase tracking-[0.22em] text-slate-500 dark:text-slate-300">Assignee</label>
                                <MultiAutocomplete
                                    :model-value="filterAssignee"
                                    :options="assigneeOptions"
                                    label-key="name"
                                    value-key="id"
                                    placeholder="Assignee..."
                                    :limit="1"
                                    @update:modelValue="handleAssigneeFilterChange"
                                />
                            </div>

                            <div v-if="entityFilterEnabled" class="flex flex-col gap-1.5">
                                <label class="hidden sm:block text-[10px] font-black uppercase tracking-[0.22em] text-slate-500 dark:text-slate-300">Entity/Company</label>
                                <MultiAutocomplete
                                    :model-value="filterEntities"
                                    :options="entityFilterOptionsWithAll"
                                    label-key="name"
                                    value-key="id"
                                    placeholder="Entity/Company..."
                                    :limit="1"
                                    @update:modelValue="handleEntityFilterChange"
                                />
                            </div>

                            <div class="flex flex-col gap-1.5">
                                <label class="hidden sm:block text-[10px] font-black uppercase tracking-[0.22em] text-slate-500 dark:text-slate-300">Location</label>
                                <MultiAutocomplete
                                    :model-value="filterStore"
                                    :options="storesWithLabel"
                                    label-key="display_name"
                                    value-key="id"
                                    placeholder="Location..."
                                    :limit="1"
                                    @update:modelValue="handleStoreFilterChange"
                                />
                            </div>

                            <div class="flex flex-col gap-1.5">
                                <label class="hidden sm:block text-[10px] font-black uppercase tracking-[0.22em] text-slate-500 dark:text-slate-300">Vendor Escalation</label>
                                <MultiAutocomplete
                                    :model-value="filterVendor"
                                    :options="vendorFilterOptions"
                                    label-key="name"
                                    value-key="id"
                                    placeholder="Vendor Escalation..."
                                    :limit="1"
                                    @update:modelValue="handleVendorFilterChange"
                                />
                            </div>

                            <div class="flex flex-col gap-1.5">
                                <label class="hidden sm:block text-[10px] font-black uppercase tracking-[0.22em] text-slate-500 dark:text-slate-300">SubCategory</label>
                                <Autocomplete
                                    :model-value="filterSubCategory"
                                    :options="subCategoryOptions"
                                    label-key="name"
                                    value-key="id"
                                    placeholder="SubCategory..."
                                    size="sm"
                                    @update:modelValue="handleSubCategoryFilterChange"
                                />
                            </div>

                            <div class="flex flex-col gap-1.5">
                                <label class="hidden sm:block text-[10px] font-black uppercase tracking-[0.22em] text-slate-500 dark:text-slate-300">From</label>
                                <input
                                    v-model="filterStartDate"
                                    type="date"
                                    @change="applyFilter"
                                    class="h-[38px] rounded-lg border-slate-300 bg-white text-sm text-slate-700 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                                >
                            </div>

                            <div class="flex flex-col gap-1.5">
                                <label class="hidden sm:block text-[10px] font-black uppercase tracking-[0.22em] text-slate-500 dark:text-slate-300">To</label>
                                <input
                                    v-model="filterEndDate"
                                    type="date"
                                    @change="applyFilter"
                                    class="h-[38px] rounded-lg border-slate-300 bg-white text-sm text-slate-700 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                                >
                            </div>
                        </div>

                        <div class="flex flex-wrap items-center gap-2 xl:justify-end">
                            <button
                                @click="clearFilters"
                                class="flex-1 sm:flex-none inline-flex h-[38px] items-center justify-center rounded-lg border border-slate-200 bg-white px-4 text-sm font-bold text-slate-600 transition-colors hover:bg-slate-50 hover:text-slate-800 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-200 dark:hover:bg-slate-800 dark:hover:text-white"
                            >
                                Reset
                            </button>

                            <button
                                v-if="hasPermission('tickets.create')"
                                @click="showCreateModal = true"
                                class="flex-1 sm:flex-none inline-flex h-[38px] items-center justify-center gap-2 rounded-lg bg-blue-600 px-4 text-sm font-bold text-white shadow-md transition-colors hover:bg-blue-700"
                            >
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                                </svg>
                                <span>New</span>
                            </button>
                        </div>
                    </div>

                    <div class="mt-2 sm:mt-4 flex flex-col gap-2 sm:gap-3 border-t border-slate-100 pt-2 sm:pt-4 lg:flex-row lg:items-center lg:justify-between dark:border-slate-800">
                        <div class="flex flex-wrap gap-1.5 sm:gap-2">
                            <span
                                v-if="!hasActiveFilters"
                                class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-2 py-0.5 sm:px-3 sm:py-1 text-[10px] sm:text-[11px] font-semibold text-slate-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                            >
                                No filters
                            </span>
                            <span
                                v-for="badge in activeFilterBadges"
                                :key="badge"
                                class="inline-flex items-center rounded-full border border-blue-200 bg-blue-50 px-2 py-0.5 sm:px-3 sm:py-1 text-[10px] sm:text-[11px] font-semibold text-blue-700 dark:border-blue-400/30 dark:bg-blue-500/15 dark:text-blue-200"
                            >
                                {{ badge }}
                            </span>
                        </div>
                        <div class="hidden sm:block text-xs font-medium text-slate-500 dark:text-slate-300">
                            Filters apply without changing existing ticket logic or workflow behavior.
                        </div>
                    </div>
                </div>

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
                        class="rounded-2xl border border-blue-200 bg-blue-50/95 p-2 sm:p-4 shadow-lg shadow-blue-100/60 backdrop-blur supports-[backdrop-filter]:bg-blue-50/90"
                    >
                        <div class="grid grid-cols-1 gap-4 xl:grid-cols-[240px_minmax(0,1fr)_auto] xl:items-end">
                            <div class="rounded-2xl border border-blue-200 bg-white/80 px-4 py-3 h-full">
                                <div class="text-[10px] font-black uppercase tracking-[0.22em] text-blue-500">Bulk Selection</div>
                                <div class="mt-2 text-2xl font-black text-blue-900">{{ selectedIds.length }}</div>
                                <div class="mt-1 text-xs text-blue-700">Selected ticket(s) ready for response, update, split, merge, child creation, or archive.</div>
                            </div>

                            <div class="grid grid-cols-1 gap-4 md:grid-cols-5">
                                <div class="flex flex-col gap-1.5">
                                    <label class="text-[10px] font-black uppercase tracking-[0.22em] text-blue-500">Location</label>
                                    <Autocomplete
                                        v-model="bulkForm.store_id"
                                        :options="storesWithLabel"
                                        label-key="display_name"
                                        value-key="id"
                                        placeholder="Unchanged..."
                                    />
                                </div>

                                <div class="flex flex-col gap-1.5">
                                    <label class="text-[10px] font-black uppercase tracking-[0.22em] text-blue-500">Item</label>
                                    <Autocomplete
                                        v-model="bulkForm.item_id"
                                        :options="items"
                                        label-key="display_name"
                                        value-key="id"
                                        placeholder="Unchanged..."
                                        size="sm"
                                    />
                                </div>

                                <div class="flex flex-col gap-1.5">
                                    <label class="text-[10px] font-black uppercase tracking-[0.22em] text-blue-500">Department</label>
                                    <HierarchySelector
                                        v-model="bulkForm.department"
                                        :nodes="bulkDepartmentNodes"
                                        placeholder="Unchanged..."
                                    />
                                </div>

                                <div class="flex flex-col gap-1.5">
                                    <label class="text-[10px] font-black uppercase tracking-[0.22em] text-blue-500">Assignee</label>
                                    <select
                                        v-model="bulkForm.assignee_id"
                                        class="min-w-[140px] rounded-lg border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600"
                                    >
                                        <option value="">-- Unchanged --</option>
                                        <option v-for="p in staff" :key="p.id" :value="p.id">{{ p.name }}</option>
                                    </select>
                                </div>

                                <div class="flex flex-col gap-1.5">
                                    <label class="text-[10px] font-black uppercase tracking-[0.22em] text-blue-500">Status</label>
                                    <select
                                        v-model="bulkForm.status"
                                        class="min-w-[140px] rounded-lg border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500 capitalize dark:border-gray-600"
                                    >
                                        <option value="">-- Unchanged --</option>
                                        <option v-for="s in bulkStatuses" :key="s" :value="s">{{ getStatusLabel(s) }}</option>
                                    </select>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 xl:w-[340px]">
                                <button
                                    v-if="selectedIds.length > 0 && hasPermission('tickets.edit')"
                                    @click="openBulkResponseModal"
                                    class="inline-flex min-h-[42px] items-center justify-center gap-2 rounded-lg border border-emerald-300 bg-white px-3 py-2 text-sm font-semibold text-emerald-700 transition-colors hover:bg-emerald-50 dark:bg-gray-800"
                                >
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
                                    </svg>
                                    Respond
                                </button>
                                <button
                                    v-if="canCreateChildTickets && hasPermission('tickets.edit')"
                                    @click="openBulkChildModal"
                                    class="inline-flex min-h-[42px] items-center justify-center gap-2 rounded-lg border border-teal-300 bg-white px-3 py-2 text-sm font-semibold text-teal-700 transition-colors hover:bg-teal-50 dark:bg-gray-800"
                                >
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                                    </svg>
                                    Create Child Tickets
                                </button>
                                <button
                                    v-if="selectedIds.length === 1 && hasPermission('tickets.edit')"
                                    @click="openSplitModal"
                                    class="inline-flex min-h-[42px] items-center justify-center gap-2 rounded-lg border border-amber-300 bg-white px-3 py-2 text-sm font-semibold text-amber-700 transition-colors hover:bg-amber-50 dark:bg-gray-800"
                                >
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7v8a2 2 0 002 2h6M8 7V5a2 2 0 012-2h4.586a1 1 0 01.707.293l4.414 4.414a1 1 0 01.293.707V15a2 2 0 01-2 2h-2M8 7H6a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2v-2" />
                                    </svg>
                                    Split
                                </button>
                                <button
                                    v-if="selectedIds.length > 1 && hasPermission('tickets.edit')"
                                    @click="openMergeModal"
                                    class="inline-flex min-h-[42px] items-center justify-center gap-2 rounded-lg border border-violet-300 bg-white px-3 py-2 text-sm font-semibold text-violet-700 transition-colors hover:bg-violet-50 dark:bg-gray-800"
                                >
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
                                    </svg>
                                    Merge
                                </button>
                                <button
                                    v-if="hasPermission('tickets.delete')"
                                    @click="submitBulkArchive"
                                    :disabled="isBulkArchiving"
                                    class="inline-flex min-h-[42px] items-center justify-center gap-2 rounded-lg border border-red-300 bg-white px-3 py-2 text-sm font-semibold text-red-700 transition-colors hover:bg-red-50 disabled:opacity-50 dark:bg-gray-800"
                                >
                                    <svg v-if="isBulkArchiving" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                    <svg v-else class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M10 12h4m-7 8h10a2 2 0 002-2V8H5v10a2 2 0 002 2zM8 8V6a2 2 0 012-2h4a2 2 0 012 2v2" />
                                    </svg>
                                    Archive
                                </button>
                                <button
                                    @click="selectedIds = []"
                                    class="inline-flex min-h-[42px] items-center justify-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-600 transition-colors hover:bg-slate-50 dark:bg-gray-800"
                                >
                                    Clear
                                </button>
                                <button
                                    @click="submitBulk"
                                    :disabled="isBulkSubmitting"
                                    class="inline-flex min-h-[42px] items-center justify-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-bold text-white shadow-sm transition-colors hover:bg-blue-700 disabled:opacity-50 sm:col-span-2"
                                >
                                    <svg v-if="isBulkSubmitting" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 22 6.477 22 12h-4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                    Apply to {{ selectedIds.length }}
                                </button>
                            </div>
                        </div>
                    </div>
                </Transition>
            </div>

            <div class="relative z-0">
                <DataTable
                    title="Ticket Monitoring Board"
                    :subtitle="tableSubtitle"
                    freeze-header
                    :show-search="false"
                    :empty-message="emptyStateMessage"
                    :data="displayedTickets"
                    :current-page="pagination.currentPage.value"
                    :last-page="pagination.lastPage.value"
                    :per-page="pagination.perPage.value"
                    :showing-text="ticketsShowingText"
                    :is-loading="pagination.isLoading.value"
                    infinite-scroll
                    :has-more="hasMoreTickets"
                    :loading-more="loadingMoreTickets"
                    @load-more="loadMoreTickets"
                >
                <template #actions>
                    <!-- Columns Dropdown -->
                    <Dropdown align="right" width="48" contentClasses="py-1 bg-white border border-gray-100 shadow-xl">
                        <template #trigger>
                            <button
                                class="bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium transition-colors flex items-center space-x-2 shadow-sm whitespace-nowrap dark:bg-gray-800 dark:text-gray-300 dark:border-gray-600 dark:hover:bg-gray-700"
                                title="Customize Columns"
                            >
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17V7m0 10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h2a2 2 0 012 2m0 10a2 2 0 002 2h2a2 2 0 002-2M9 7a2 2 0 012-2h2a2 2 0 012 2m0 10V7m0 10a2 2 0 002 2h2a2 2 0 002-2V7a2 2 0 00-2-2h-2a2 2 0 00-2 2" />
                                </svg>
                                <span>Columns</span>
                            </button>
                        </template>
                        <template #content>
                            <div class="px-4 py-2 text-xs font-bold text-gray-400 uppercase tracking-widest border-b border-gray-100 dark:text-gray-400 dark:border-gray-700">
                                Visible Columns
                            </div>
                            <div class="p-2 space-y-1">
                                <label
                                    v-for="col in tableColumns"
                                    :key="col.key"
                                    class="flex items-center px-2 py-1.5 rounded hover:bg-gray-50 cursor-pointer dark:hover:bg-gray-700"
                                    :class="col.locked ? 'opacity-50 cursor-not-allowed' : ''"
                                    @click.stop
                                >
                                    <input
                                        type="checkbox"
                                        :checked="col.visible"
                                        :disabled="col.locked"
                                        @change="toggleColumn(col)"
                                        class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 mr-2 dark:border-gray-600"
                                    >
                                    <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">{{ col.label }}</span>
                                </label>
                            </div>
                        </template>
                    </Dropdown>

                    <button
                        @click="openExportModal"
                        class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors flex items-center space-x-2 shadow-sm whitespace-nowrap"
                        title="Export current view to Excel"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                        </svg>
                        <span>Export Excel</span>
                    </button>
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
                        <th v-if="isColumnVisible('ticket')" class="px-4 py-3 text-left text-[11px] font-black uppercase tracking-[0.18em] text-slate-500 dark:text-slate-300">Ticket</th>
                        <th v-if="isColumnVisible('assignee')" class="px-4 py-3 text-left text-[11px] font-black uppercase tracking-[0.18em] text-slate-500 dark:text-slate-300">Assignee</th>
                        <th v-if="isColumnVisible('queue_detail')" class="px-4 py-3 text-left text-[11px] font-black uppercase tracking-[0.18em] text-slate-500 dark:text-slate-300">Location / Item</th>
                        <th v-if="isColumnVisible('sla_health')" class="px-4 py-3 text-left text-[11px] font-black uppercase tracking-[0.18em] text-slate-500 dark:text-slate-300">SLA Health</th>
                        <th v-if="isColumnVisible('created')" class="px-4 py-3 text-left text-[11px] font-black uppercase tracking-[0.18em] text-slate-500 dark:text-slate-300">Created</th>
                        <th v-if="isColumnVisible('sla_timer')" class="px-4 py-3 text-left text-[11px] font-black uppercase tracking-[0.18em] text-slate-500 dark:text-slate-300">SLA Timer</th>
                        <th v-if="isColumnVisible('responded_time')" class="px-4 py-3 text-left text-[11px] font-black uppercase tracking-[0.18em] text-slate-500 dark:text-slate-300">Responded Time</th>
                        <th v-if="isColumnVisible('resolved_date')" class="px-4 py-3 text-left text-[11px] font-black uppercase tracking-[0.18em] text-slate-500 dark:text-slate-300">Resolved Date</th>
                        <th v-if="isColumnVisible('feedback')" class="px-4 py-3 text-left text-[11px] font-black uppercase tracking-[0.18em] text-slate-500 dark:text-slate-300">Feedback</th>
                        <th v-if="isColumnVisible('rating')" class="px-4 py-3 text-left text-[11px] font-black uppercase tracking-[0.18em] text-slate-500 dark:text-slate-300">Rating</th>
                    </tr>
                </template>

                <template #body="{ data }">
                    <tr
                        v-for="ticket in data"
                        :key="ticket.id"
                        @click="editTicket(ticket)"
                        @auxclick.prevent="handleAuxClick($event, ticket)"
                        @mousedown.middle.prevent
                        class="group border-l-4 align-top transition-all"
                        :class="[
                            getSlaRowClass(ticket),
                            hasPermission('tickets.edit') ? 'cursor-pointer' : 'cursor-not-allowed',
                            selectedIds.includes(ticket.id) ? 'ring-1 ring-inset ring-blue-300' : ''
                        ]"
                    >
                        <td class="px-4 py-5 w-10 align-top" @click.stop>
                            <input
                                type="checkbox"
                                :value="ticket.id"
                                v-model="selectedIds"
                                class="mt-1 cursor-pointer rounded border-gray-300 text-blue-600 focus:ring-blue-500 dark:border-gray-600"
                            >
                        </td>
                        <td v-if="isColumnVisible('ticket')" class="px-4 py-5 align-top">
                            <div class="min-w-[240px] max-w-[360px] space-y-3">
                                <div class="flex flex-wrap items-start gap-2">
                                    <span class="inline-flex rounded-md border border-slate-300 bg-white px-2.5 py-1 text-[11px] font-black tracking-wide text-black shadow-sm dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100">
                                        {{ ticket.ticket_key }}
                                    </span>
                                    <span v-if="ticket.parent_id" class="inline-flex rounded-full border border-indigo-300 bg-indigo-50 px-2.5 py-1 text-[11px] font-bold uppercase tracking-wide text-indigo-700">
                                        Child
                                    </span>
                                    <span class="inline-flex rounded-full border px-2.5 py-1 text-[11px] font-bold capitalize shadow-sm" :class="getPriorityColor(ticket.item?.priority || ticket.priority)">
                                        {{ getPriorityLabel(ticket.item?.priority || ticket.priority) }}
                                    </span>
                                    <span class="inline-flex rounded-full border px-2.5 py-1 text-[11px] font-bold capitalize" :class="getStatusColor(ticket.status)">
                                        {{ getStatusLabel(ticket.status) }}
                                    </span>
                                    <a v-if="ticket.queue_track_token && !['resolved','closed'].includes(ticket.status)"
                                       :href="route('public.queue.track', ticket.queue_track_token)" target="_blank" @click.stop
                                       title="Track this ticket's live position in the queue"
                                       class="inline-flex items-center gap-1 rounded-full border border-emerald-300 bg-emerald-50 px-2.5 py-1 text-[11px] font-bold text-emerald-700 hover:bg-emerald-100 dark:border-emerald-400/30 dark:bg-emerald-500/15 dark:text-emerald-300">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 10h16M4 14h10M4 18h10" /></svg>
                                        Queue
                                    </a>
                                </div>

                                <div class="space-y-1.5">
                                    <div class="break-words text-sm font-bold leading-5 text-black dark:text-slate-100">
                                        {{ ticket.title }}
                                    </div>
                                </div>

                                <Link
                                    v-if="ticket.parent"
                                    :href="route('tickets.edit', ticket.parent.id)"
                                    @click.stop
                                    class="block rounded-xl border border-indigo-200 bg-indigo-50 p-3 transition-colors hover:border-indigo-400 hover:bg-indigo-100 dark:border-indigo-400/30 dark:bg-indigo-500/15 dark:hover:bg-indigo-500/25"
                                >
                                    <div class="mb-1 text-[10px] font-black uppercase tracking-[0.22em] text-indigo-700 dark:text-indigo-200">Parent Ticket</div>
                                    <div class="flex items-start justify-between gap-2">
                                        <div class="text-xs font-bold text-indigo-900 dark:text-indigo-100">{{ ticket.parent.ticket_key }}</div>
                                        <span class="shrink-0 rounded-full border px-2 py-0.5 text-[9px] font-bold capitalize" :class="getStatusColor(ticket.parent.status)">
                                            {{ getStatusLabel(ticket.parent.status) }}
                                        </span>
                                    </div>
                                    <div class="mt-1 break-words text-xs leading-5 text-indigo-900 dark:text-indigo-100">{{ ticket.parent.title }}</div>
                                    <div class="mt-1.5 text-[10px] font-semibold text-indigo-700 dark:text-indigo-200">{{ getTicketResponsibilityLabel(ticket.parent) }}</div>
                                </Link>

                                <div @click.stop="openRequesterTicketsModal(ticket)" class="rounded-xl border border-slate-300 bg-white p-3 cursor-pointer hover:bg-slate-50 transition-colors dark:border-slate-700 dark:bg-slate-900 dark:hover:bg-slate-800" title="View Requester's Tickets">
                                    <div class="mb-2 text-[10px] font-black uppercase tracking-[0.22em] text-black flex justify-between items-center dark:text-slate-300">
                                        <span>Requester</span>
                                        <svg class="w-3 h-3 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" /></svg>
                                    </div>
                                    <div v-if="ticket.reporter" class="flex items-center gap-2 text-sm">
                                        <div v-if="ticket.reporter.profile_photo" class="h-7 w-7 overflow-hidden rounded-full border border-slate-200">
                                            <img :src="'/serve-storage/' + ticket.reporter.profile_photo" class="h-full w-full object-cover" :alt="ticket.reporter.name">
                                        </div>
                                        <div v-else class="flex h-7 w-7 items-center justify-center rounded-full border border-slate-300 bg-white text-[10px] font-bold text-black dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
                                            {{ ticket.reporter.name.charAt(0) }}
                                        </div>
                                        <span class="break-words font-semibold text-black dark:text-slate-100">{{ ticket.reporter.name }}</span>
                                    </div>
                                    <div v-else class="break-words text-sm font-semibold text-black dark:text-slate-100">{{ getReporterLabel(ticket) }}</div>
                                    <div v-if="ticket.sender_email" class="mt-1 break-all text-[11px] text-black dark:text-slate-300">{{ ticket.sender_email }}</div>
                                </div>

                                <div v-if="ticket.children?.length" class="rounded-xl border border-blue-300 bg-white p-3 dark:border-blue-400/30 dark:bg-slate-900">
                                    <div class="mb-2 text-[10px] font-black uppercase tracking-[0.22em] text-black dark:text-slate-300">Child Tickets</div>
                                    <div class="space-y-2">
                                        <Link
                                            v-for="child in ticket.children"
                                            :key="child.id"
                                            :href="route('tickets.edit', child.id)"
                                            @click.stop
                                            class="flex items-start justify-between gap-3 rounded-lg p-1.5 text-xs transition-colors hover:bg-blue-50 dark:hover:bg-blue-500/15"
                                        >
                                            <div class="min-w-0">
                                                <div class="font-bold text-black dark:text-slate-100">{{ child.ticket_key }}</div>
                                                <div class="break-words text-black dark:text-slate-200">{{ child.title }}</div>
                                            </div>
                                            <div class="flex shrink-0 flex-col items-end gap-1 text-right text-[10px] text-black dark:text-slate-300">
                                                <span class="rounded-full border px-2 py-0.5 font-bold capitalize" :class="getStatusColor(child.status)">
                                                    {{ getStatusLabel(child.status) }}
                                                </span>
                                                <span>{{ getTicketResponsibilityLabel(child) }}</span>
                                            </div>
                                        </Link>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td v-if="isColumnVisible('assignee')" class="px-4 py-5 align-top">
                            <div class="min-w-[160px] max-w-[220px] space-y-2">
                                <div v-if="ticket.assignee" class="flex items-center gap-2">
                                    <div v-if="ticket.assignee.profile_photo" class="h-7 w-7 overflow-hidden rounded-full border border-slate-200">
                                        <img :src="'/serve-storage/' + ticket.assignee.profile_photo" class="h-full w-full object-cover" :alt="ticket.assignee.name">
                                    </div>
                                    <div v-else class="flex h-7 w-7 items-center justify-center rounded-full bg-slate-200 text-[10px] font-bold text-slate-600">
                                        {{ ticket.assignee.name.charAt(0) }}
                                    </div>
                                    <div>
                                        <div class="font-semibold text-black dark:text-slate-100">{{ ticket.assignee.name }}</div>
                                        <div v-if="assigneeSectorLabel(ticket.assignee)" class="text-[10px] font-black text-slate-400 dark:text-slate-300">
                                            {{ assigneeSectorLabel(ticket.assignee) }}
                                        </div>
                                    </div>
                                </div>
                                <button
                                    v-else-if="hasPermission('tickets.assign')"
                                    type="button"
                                    @click.stop="acceptTicket(ticket)"
                                    class="inline-flex items-center rounded-lg border border-blue-600 bg-white px-3 py-1.5 text-xs font-bold text-blue-600 shadow-sm transition-all hover:bg-blue-600 hover:text-white focus:outline-none dark:bg-slate-900 dark:text-blue-200 dark:hover:bg-blue-600 dark:hover:text-white"
                                >
                                    Accept Ticket
                                </button>
                                <div v-else class="inline-flex rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-black dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200">
                                    Unassigned
                                </div>
                            </div>
                        </td>
                        <td v-if="isColumnVisible('queue_detail')" class="px-4 py-5 align-top">
                            <div class="min-w-[180px] max-w-[240px] space-y-3 text-sm">
                                <div>
                                    <div class="text-[10px] font-black uppercase tracking-[0.2em] text-slate-400 dark:text-slate-300">Store</div>
                                    <div class="mt-1 break-words font-semibold text-black dark:text-slate-100">{{ ticket.store ? ticket.store.name : '-' }}</div>
                                </div>
                                <div>
                                    <div class="text-[10px] font-black uppercase tracking-[0.2em] text-slate-400 dark:text-slate-300">Item</div>
                                    <div class="mt-1 break-words text-xs leading-5 text-black dark:text-slate-200">
                                        {{ formatItemName(ticket.item) }}
                                        <span v-if="isCctvTicket(ticket)"
                                              class="ml-1 inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-black uppercase tracking-wider bg-sky-50 text-sky-700 border border-sky-100 align-middle">
                                            CCTV
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td v-if="isColumnVisible('sla_health')" class="px-4 py-5 align-top">
                            <div v-if="ticket.sla_metric" class="min-w-[160px] max-w-[220px] space-y-2">
                                <div
                                    v-for="sla in [getSlaState(ticket, 'response'), getSlaState(ticket, 'resolution')]"
                                    :key="sla.label"
                                    class="rounded-xl border px-3 py-2"
                                    :class="sla.toneClass"
                                >
                                    <div class="flex items-center justify-between gap-3">
                                        <div class="flex items-center gap-2">
                                            <span class="h-2.5 w-2.5 rounded-full" :class="sla.dotClass"></span>
                                            <span class="text-[10px] font-black uppercase tracking-[0.2em]">{{ sla.label }}</span>
                                        </div>
                                        <span class="text-xs font-bold">{{ sla.value }}</span>
                                    </div>
                                </div>
                            </div>
                            <div v-else class="inline-flex rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-medium text-black dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200">
                                No SLA target
                            </div>
                        </td>
                        <td v-if="isColumnVisible('created')" class="px-4 py-5 align-top text-sm text-black dark:text-slate-200">
                            <div class="min-w-[132px]">
                                <div class="font-medium text-black dark:text-slate-100">{{ formatDate(ticket.created_at) }}</div>
                            </div>
                        </td>
                        <td v-if="isColumnVisible('sla_timer')" class="px-4 py-5 align-top text-sm text-black dark:text-slate-200">
                            <div v-if="ticket.sla_metric" class="space-y-1 min-w-[140px]">
                                <div v-if="ticket.sla_metric.response_target_at" class="whitespace-nowrap"><span class="font-bold text-gray-500 text-xs mr-1 dark:text-gray-300">Res:</span>{{ formatDate(ticket.sla_metric.response_target_at) }}</div>
                                <div v-if="ticket.sla_metric.resolution_target_at" class="whitespace-nowrap"><span class="font-bold text-gray-500 text-xs mr-1 dark:text-gray-300">Sol:</span>{{ formatDate(ticket.sla_metric.resolution_target_at) }}</div>
                            </div>
                            <span v-else class="text-gray-400 italic dark:text-gray-400">No Target</span>
                        </td>
                        <td v-if="isColumnVisible('responded_time')" class="px-4 py-5 align-top text-sm text-black dark:text-slate-200">
                            <span v-if="ticket.sla_metric?.first_response_at" class="whitespace-nowrap">{{ formatDate(ticket.sla_metric.first_response_at) }}</span>
                            <span v-else class="text-gray-400 italic dark:text-gray-400">Not Responded</span>
                        </td>
                        <td v-if="isColumnVisible('resolved_date')" class="px-4 py-5 align-top text-sm text-black dark:text-slate-200">
                            <span v-if="ticket.sla_metric?.resolved_at" class="whitespace-nowrap">{{ formatDate(ticket.sla_metric.resolved_at) }}</span>
                            <span v-else-if="ticket.status === 'closed'" class="whitespace-nowrap">{{ formatDate(ticket.updated_at) }}</span>
                            <span v-else class="text-gray-400 italic dark:text-gray-400">Not Resolved</span>
                        </td>
                        <td v-if="isColumnVisible('feedback')" class="px-4 py-5 align-top text-sm text-black dark:text-slate-200">
                            <div v-if="ticket.survey?.feedback" class="min-w-[200px] max-w-[400px] whitespace-normal break-words">
                                {{ ticket.survey.feedback }}
                            </div>
                            <span v-else class="text-gray-400 italic dark:text-gray-400">None</span>
                        </td>
                        <td v-if="isColumnVisible('rating')" class="px-4 py-5 align-top text-sm text-black dark:text-slate-200">
                            <div v-if="ticket.survey?.rating" class="flex gap-1">
                                <span v-for="i in 4" :key="i" class="text-[10px]" :class="i <= ticket.survey.rating ? 'text-yellow-400' : 'text-gray-200'">⭐</span>
                            </div>
                            <span v-else class="text-gray-400 italic dark:text-gray-400">None</span>
                        </td>
                    </tr>
                </template>
                </DataTable>
            </div>
        </div>
        <!-- Create Ticket Modal -->
        <div v-if="showCreateModal" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex items-center justify-center min-h-screen px-4">
                <div class="fixed inset-0 bg-black/20 backdrop-blur-md" @click="showCreateModal = false"></div>
                <div class="bg-white rounded-xl shadow-2xl max-w-md w-full p-6 relative border border-gray-100 dark:bg-gray-800 dark:border-gray-700">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="text-xl font-bold text-gray-900 dark:text-gray-100">Create New Ticket</h3>
                        
                        <div class="flex flex-col items-end">
                            <label class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1 dark:text-gray-400">Status</label>
                            <select v-model="createForm.status" required class="bg-gray-50 border-none rounded-lg text-xs font-bold capitalize focus:ring-0 cursor-pointer shadow-sm dark:bg-gray-900/50" :class="getStatusColor(createForm.status)">
                                <option v-for="s in statuses" :key="s" :value="s">{{ getStatusLabel(s) }}</option>
                            </select>
                         </div>
                    </div>

                    <form @submit.prevent="createTicket" class="space-y-5">
                        
                        <!-- Requester Configuration -->
                        <div class="bg-gray-50 p-4 rounded-xl border border-gray-100 space-y-4 dark:bg-gray-900/50 dark:border-gray-700">
                            <label v-if="!isUserRole" class="flex items-center space-x-3 cursor-pointer">
                                <div class="relative">
                                    <input type="checkbox" v-model="createForm.is_self_requester" class="sr-only peer">
                                    <div class="w-9 h-5 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-blue-600 dark:bg-gray-700"></div>
                                </div>
                                <span class="text-sm font-bold text-gray-700 dark:text-gray-300">I am the requester</span>
                            </label>

                            <div v-if="!createForm.is_self_requester" class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-2 border-t border-gray-200 dark:border-gray-700">
                                <div>
                                    <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1 dark:text-gray-300">Requester Name</label>
                                    <input v-model="createForm.sender_name" type="text" maxlength="255" required class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1 dark:text-gray-300">Requester Email</label>
                                    <input v-model="createForm.sender_email" type="email" maxlength="255" required class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600">
                                </div>
                            </div>

                            <div class="pt-2 border-t border-gray-200 dark:border-gray-700">
                                <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1 dark:text-gray-300">Department</label>
                                <HierarchySelector
                                    v-model="createForm.department"
                                    :nodes="createDepartmentNodes"
                                    placeholder="Select Department"
                                    :disabled="createForm.is_self_requester"
                                />
                            </div>

                            <div v-if="!isUserRole" class="pt-2">
                                <label class="flex items-center space-x-3 cursor-pointer">
                                    <div class="relative">
                                        <input type="checkbox" v-model="createForm.notify_requester" class="sr-only peer">
                                        <div class="w-9 h-5 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-blue-600 dark:bg-gray-700"></div>
                                    </div>
                                    <span class="text-xs font-medium text-gray-600 dark:text-gray-300">Send email notification to requester</span>
                                </label>
                            </div>
                        </div>

                        <div v-if="availableCompanies.length > 0">
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">Company</label>
                            <select v-model="createForm.company_id" required class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600">
                                <option value="">Select Company</option>
                                <option v-for="company in availableCompanies" :key="company.id" :value="company.id">{{ company.name }}</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">Store</label>
                            <Autocomplete
                                v-model="createForm.store_id"
                                :options="storesWithLabel"
                                label-key="display_name"
                                value-key="id"
                                placeholder="Select store..."
                            />
                        </div>
                        
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">Item</label>
                            <Autocomplete
                                v-model="createForm.item_id"
                                :options="items"
                                label-key="display_name"
                                value-key="id"
                                placeholder="Select item..."
                                size="sm"
                            />
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">Vendor Escalation</label>
                            <MultiAutocomplete
                                v-model="createVendorSelection"
                                :options="vendors"
                                label-key="name"
                                value-key="id"
                                placeholder="None"
                                :limit="1"
                            />
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">Title</label>
                            <input v-model="createForm.title" type="text" maxlength="255" required class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">Description</label>
                            <textarea v-model="createForm.description" rows="4" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600"></textarea>
                        </div>
                        <div v-if="createForm.item_id" class="p-3 bg-gray-50 rounded-lg border border-gray-100 dark:bg-gray-900/50 dark:border-gray-700">
                            <label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">Priority</label>
                            <span class="inline-flex px-2.5 py-0.5 rounded-full text-xs font-bold capitalize shadow-sm" :class="getPriorityColor(createForm.priority)">
                                {{ getPriorityLabel(createForm.priority) }}
                            </span>
                        </div>

                        <div v-if="hasPermission('tickets.assign')">
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">Assign To</label>
                            <select v-model="createForm.assignee_id" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600">
                                <option value="">Unassigned</option>
                                <option v-for="person in staff" :key="person.id" :value="person.id">{{ person.name }}</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">Attachments</label>
                            <input ref="fileInput" type="file" multiple @change="handleFileSelect" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600">
                            <div v-if="createForm.attachments.length > 0" class="mt-2 text-xs text-gray-600 dark:text-gray-300">
                                <p class="font-medium mb-1">Selected files:</p>
                                <div class="space-y-1">
                                    <div v-for="(file, index) in createForm.attachments" :key="index">
                                        {{ file.name }} ({{ formatFileSize(file.size) }})
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="flex justify-end space-x-3 pt-6 border-t mt-6">
                            <button type="button" @click="showCreateModal = false" class="px-4 py-2 text-sm font-semibold text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">Cancel</button>
                            <button type="submit" :disabled="isSubmitting" class="px-6 py-2 bg-blue-600 text-white text-sm font-bold rounded-lg hover:bg-blue-700 shadow-md disabled:opacity-50 transition-all">Create Ticket</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Accept Ticket Modal -->
        <div v-if="showAcceptModal" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex items-center justify-center min-h-screen px-4">
                <div class="fixed inset-0 bg-black/20 backdrop-blur-md" @click="showAcceptModal = false"></div>
                <div class="bg-white rounded-xl shadow-2xl max-w-sm w-full p-6 relative border border-gray-100 dark:bg-gray-800 dark:border-gray-700">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100">Accept Ticket</h3>
                        <button @click="showAcceptModal = false" class="text-gray-400 hover:text-gray-600 dark:text-gray-400">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>

                    <p v-if="acceptingTicket" class="text-xs text-gray-500 mb-5 bg-gray-50 rounded-lg p-3 border border-gray-100 truncate dark:bg-gray-900/50 dark:text-gray-300 dark:border-gray-700">
                        <span class="font-black text-gray-700 dark:text-gray-300">{{ acceptingTicket.ticket_key }}</span>
                        — {{ acceptingTicket.title }}
                    </p>

                    <div class="space-y-4">
                        <div v-if="availableCompanies.length > 0">
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">Company <span class="text-red-500">*</span></label>
                            <select v-model="acceptForm.company_id" required class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600">
                                <option value="">Select Company</option>
                                <option v-for="company in availableCompanies" :key="company.id" :value="company.id">{{ company.name }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">Store <span class="text-red-500">*</span></label>
                            <Autocomplete
                                v-model="acceptForm.store_id"
                                :options="storesWithLabel"
                                label-key="display_name"
                                value-key="id"
                                placeholder="Select store..."
                            />
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">Item <span class="text-red-500">*</span></label>
                            <Autocomplete
                                v-model="acceptForm.item_id"
                                :options="items"
                                label-key="display_name"
                                value-key="id"
                                placeholder="Select item..."
                                size="sm"
                            />
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">Department <span class="text-red-500">*</span></label>
                            <HierarchySelector
                                v-model="acceptForm.department"
                                :nodes="acceptDepartmentNodes"
                                placeholder="Select Department"
                            />
                        </div>
                    </div>

                    <div class="flex justify-end space-x-3 pt-5 border-t mt-5">
                        <button type="button" @click="showAcceptModal = false" :disabled="isAcceptSubmitting" class="px-4 py-2 text-sm font-semibold text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors disabled:opacity-50 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">Cancel</button>
                        <button
                            type="button"
                            @click="submitAcceptTicket"
                            :disabled="!canSubmitAcceptTicket"
                            class="px-6 py-2 bg-blue-600 text-white text-sm font-bold rounded-lg hover:bg-blue-700 shadow-md disabled:opacity-50 transition-all"
                        >
                            {{ isAcceptSubmitting ? 'Accepting...' : 'Accept Ticket' }}
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Bulk Response Modal -->
        <div v-if="showBulkResponseModal" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex min-h-screen items-center justify-center px-4 py-8">
                <div class="fixed inset-0 bg-black/20 backdrop-blur-md" @click="closeBulkResponseModal"></div>
                <div class="relative flex max-h-[90vh] w-full max-w-3xl flex-col rounded-xl border border-gray-100 bg-white p-6 shadow-2xl dark:bg-gray-800 dark:border-gray-700">
                    <div class="mb-6 flex items-start justify-between gap-4">
                        <div>
                            <h3 class="text-xl font-bold uppercase tracking-widest text-gray-900 dark:text-gray-100">Bulk Response</h3>
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                                Send one public response to {{ selectedIds.length }} selected ticket(s).
                            </p>
                        </div>
                        <button @click="closeBulkResponseModal" class="text-gray-400 transition-colors hover:text-gray-600 dark:text-gray-400">
                            <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>

                    <form @submit.prevent="submitBulkResponse" class="flex min-h-0 flex-1 flex-col gap-5">
                        <div class="rounded-lg border border-emerald-100 bg-emerald-50 p-3 text-sm text-emerald-800">
                            This creates the same public response on every selected ticket. Closed tickets are rejected before sending.
                        </div>

                        <div class="min-h-0 flex-1 overflow-y-auto pr-1">
                            <label class="mb-2 block text-[10px] font-black uppercase tracking-[0.22em] text-gray-500 dark:text-gray-300">Response</label>
                            <textarea
                                v-model="bulkResponseForm.comment_text"
                                rows="8"
                                class="block w-full resize-y rounded-xl border-gray-300 text-sm text-gray-700 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:text-gray-300 dark:border-gray-600"
                                placeholder="Write your response..."
                                @paste="handleBulkResponsePaste"
                            ></textarea>

                            <div v-if="bulkResponseForm.attachments.length > 0" class="mt-4 space-y-2">
                                <div class="text-[10px] font-black uppercase tracking-[0.22em] text-gray-500 dark:text-gray-300">Attachments</div>
                                <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                    <div
                                        v-for="(file, index) in bulkResponseForm.attachments"
                                        :key="`${file.name}-${file.size}-${index}`"
                                        class="flex items-center justify-between gap-3 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 dark:bg-gray-900/50 dark:border-gray-700"
                                    >
                                        <div class="min-w-0">
                                            <div class="truncate text-sm font-semibold text-gray-800 dark:text-gray-200">{{ file.name }}</div>
                                            <div class="text-xs text-gray-500 dark:text-gray-300">{{ formatFileSize(file.size) }}</div>
                                        </div>
                                        <button type="button" @click="removeBulkResponseAttachment(index)" class="shrink-0 rounded-full bg-red-500 p-1 text-white shadow-sm transition-colors hover:bg-red-600">
                                            <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                            </svg>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="flex flex-col gap-4 border-t pt-4 sm:flex-row sm:items-center sm:justify-between">
                            <div class="flex items-center gap-2">
                                <input ref="bulkResponseFileInput" type="file" multiple accept="image/*,video/*" class="hidden" @change="handleBulkResponseFileSelect">
                                <button type="button" @click="bulkResponseFileInput.click()" class="inline-flex h-10 w-10 items-center justify-center rounded-lg border border-blue-200 text-blue-600 transition-colors hover:bg-blue-50" title="Attach Media">
                                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13" />
                                    </svg>
                                </button>

                                <div v-if="hasPermission('tickets.canned_messages') || hasPermission('tickets.edit')" class="relative">
                                    <button
                                        type="button"
                                        @click="showBulkCannedMessages = !showBulkCannedMessages"
                                        class="inline-flex h-10 w-10 items-center justify-center rounded-lg border border-orange-200 text-orange-600 transition-colors hover:bg-orange-50"
                                        title="Canned Messages"
                                    >
                                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
                                        </svg>
                                    </button>

                                    <div v-if="showBulkCannedMessages" class="absolute bottom-full left-0 z-50 mb-2 w-72 overflow-hidden rounded-lg border border-gray-200 bg-white shadow-xl dark:bg-gray-800 dark:border-gray-700">
                                        <div class="flex items-center justify-between border-b bg-gray-50 p-2 dark:bg-gray-900/50">
                                            <span class="text-xs font-bold uppercase tracking-wider text-gray-700 dark:text-gray-300">Canned Messages</span>
                                            <button type="button" @click="showBulkCannedMessages = false" class="text-gray-400 hover:text-gray-600 dark:text-gray-400">
                                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                                </svg>
                                            </button>
                                        </div>
                                        <div class="max-h-60 overflow-y-auto">
                                            <template v-if="cannedMessages && cannedMessages.length > 0">
                                                <button
                                                    v-for="message in cannedMessages"
                                                    :key="message.id"
                                                    type="button"
                                                    @click="applyBulkCannedMessage(message)"
                                                    class="w-full border-b border-gray-50 px-4 py-3 text-left transition-colors last:border-0 hover:bg-blue-50"
                                                >
                                                    <div class="mb-1 text-xs font-bold text-blue-700">{{ message.title }}</div>
                                                    <div class="line-clamp-2 text-[10px] text-gray-600 dark:text-gray-300">{{ message.content }}</div>
                                                </button>
                                            </template>
                                            <div v-else class="px-4 py-8 text-center text-xs italic text-gray-500 dark:text-gray-300">
                                                No canned messages found.
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="flex flex-col-reverse gap-3 sm:flex-row sm:items-center">
                                <button type="button" @click="closeBulkResponseModal" class="rounded-lg bg-gray-100 px-4 py-2 text-sm font-semibold text-gray-600 transition-colors hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    :disabled="bulkResponseForm.processing || !hasBulkResponseContent"
                                    class="inline-flex items-center justify-center gap-2 rounded-lg bg-emerald-600 px-6 py-2 text-sm font-black uppercase tracking-widest text-white shadow-md transition-colors hover:bg-emerald-700 disabled:opacity-50"
                                >
                                    <svg v-if="bulkResponseForm.processing" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 6.477 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                    Send to {{ selectedIds.length }}
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Bulk Create Child Tickets Modal -->
        <div v-if="showBulkChildModal" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex items-center justify-center min-h-screen px-4 py-8">
                <div class="fixed inset-0 bg-black/20 backdrop-blur-md" @click="showBulkChildModal = false"></div>
                <div class="bg-white rounded-xl shadow-2xl max-w-4xl w-full p-6 relative border border-gray-100 max-h-[90vh] flex flex-col dark:bg-gray-800 dark:border-gray-700">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="text-xl font-bold text-gray-900 uppercase tracking-widest dark:text-gray-100">Bulk Create Child Tickets</h3>
                        <button @click="showBulkChildModal = false" class="text-gray-400 hover:text-gray-600 dark:text-gray-400">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>

                    <p class="text-sm text-gray-600 bg-teal-50 p-3 rounded-lg border border-teal-100 mb-6 shrink-0 dark:text-gray-300">
                        Set individual schedules for **{{ selectedIds.length }}** parent tickets. Child tickets will be created and both parent and child will be set to "For Schedule".
                    </p>

                    <form @submit.prevent="submitBulkChild" class="space-y-8 overflow-y-auto px-1 custom-scrollbar">
                        <div v-for="(ticketForm, index) in bulkChildForm.tickets" :key="ticketForm.parent_id" 
                             class="p-5 border border-gray-200 rounded-xl space-y-5 bg-gray-50/50 hover:border-teal-200 transition-colors dark:border-gray-700">
                            
                            <!-- Header with Ticket Info -->
                            <div class="flex justify-between items-center border-b border-gray-200 pb-3 dark:border-gray-700">
                                <div class="flex items-center gap-3">
                                    <span class="px-2 py-1 bg-teal-600 text-white text-[10px] font-black rounded uppercase shadow-sm">{{ ticketForm.ticket_key }}</span>
                                    <span class="text-sm font-bold text-gray-900 truncate max-w-[500px] dark:text-gray-100">{{ ticketForm.title }}</span>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5 dark:text-gray-300">Assigned User</label>
                                    <Autocomplete v-model="bulkChildForm.tickets[index].user_id" :options="staff" label-key="name" value-key="id" placeholder="Select user..." size="sm" />
                                </div>
                                <div>
                                    <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5 dark:text-gray-300">Schedule Status</label>
                                    <select v-model="bulkChildForm.tickets[index].status" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm bg-white dark:bg-gray-800 dark:border-gray-600">
                                        <option v-for="status in ['On-site', 'Off-site', 'WFH', 'SL', 'VL', 'Restday', 'Offset', 'Holiday']" :key="status" :value="status">{{ status }}</option>
                                    </select>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5 dark:text-gray-300">Start Time</label>
                                    <input v-model="bulkChildForm.tickets[index].start_time" type="datetime-local" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm bg-white dark:bg-gray-800 dark:border-gray-600">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5 dark:text-gray-300">End Time</label>
                                    <input v-model="bulkChildForm.tickets[index].end_time" type="datetime-local" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm bg-white dark:bg-gray-800 dark:border-gray-600">
                                </div>
                            </div>

                            <!-- Additional Times (Collapsible or always visible) -->
                            <div class="p-4 bg-white rounded-lg border border-gray-100 space-y-4 shadow-sm dark:bg-gray-800 dark:border-gray-700">
                                <h4 class="text-[10px] font-black text-teal-600 uppercase tracking-widest">Additional Activity Windows</h4>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <div class="space-y-2">
                                        <label class="block text-[10px] font-bold text-gray-600 uppercase dark:text-gray-300">Pickup Time</label>
                                        <div class="flex items-center space-x-2">
                                            <input v-model="bulkChildForm.tickets[index].pickup_start" type="time" class="flex-1 px-3 py-1.5 border border-gray-200 rounded-lg text-xs dark:border-gray-700">
                                            <span class="text-gray-400 dark:text-gray-400">-</span>
                                            <input v-model="bulkChildForm.tickets[index].pickup_end" type="time" class="flex-1 px-3 py-1.5 border border-gray-200 rounded-lg text-xs dark:border-gray-700">
                                        </div>
                                    </div>
                                    <div class="space-y-2">
                                        <label class="block text-[10px] font-bold text-gray-600 uppercase dark:text-gray-300">Backlogs Time</label>
                                        <div class="flex items-center space-x-2">
                                            <input v-model="bulkChildForm.tickets[index].backlogs_start" type="time" class="flex-1 px-3 py-1.5 border border-gray-200 rounded-lg text-xs dark:border-gray-700">
                                            <span class="text-gray-400 dark:text-gray-400">-</span>
                                            <input v-model="bulkChildForm.tickets[index].backlogs_end" type="time" class="flex-1 px-3 py-1.5 border border-gray-200 rounded-lg text-xs dark:border-gray-700">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div>
                                <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5 dark:text-gray-300">Remarks</label>
                                <textarea v-model="bulkChildForm.tickets[index].remarks" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm bg-white dark:bg-gray-800 dark:border-gray-600" placeholder="Specific activity details for this child ticket..."></textarea>
                            </div>
                        </div>

                        <div class="flex justify-end space-x-3 pt-6 border-t mt-8 sticky bottom-0 bg-white pb-2 dark:bg-gray-800">
                            <button type="button" @click="showBulkChildModal = false" class="px-6 py-2.5 text-sm font-bold text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">Cancel</button>
                            <button type="submit" :disabled="bulkChildForm.processing" class="px-8 py-2.5 bg-teal-600 text-white text-sm font-black rounded-lg hover:bg-teal-700 shadow-lg disabled:opacity-50 transition-all uppercase tracking-widest">
                                Create {{ selectedIds.length }} Child Tickets
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Split Ticket Modal -->
        <div v-if="showSplitModal" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex items-center justify-center min-h-screen px-4">
                <div class="fixed inset-0 bg-black/20 backdrop-blur-md" @click="showSplitModal = false"></div>
                <div class="bg-white rounded-xl shadow-2xl max-w-lg w-full p-6 relative border border-gray-100 dark:bg-gray-800 dark:border-gray-700">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="text-xl font-bold text-gray-900 dark:text-gray-100">Split Ticket</h3>
                        <button @click="showSplitModal = false" class="text-gray-400 hover:text-gray-600 dark:text-gray-400">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>

                    <form @submit.prevent="submitSplit" class="space-y-6">
                        <p class="text-sm text-gray-600 bg-yellow-50 p-3 rounded-lg border border-yellow-100 dark:text-gray-300">
                            Splitting will update the original ticket's title and create new tickets for each additional concern listed below.
                        </p>

                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2 dark:text-gray-300">Original Ticket Concern (Current)</label>
                            <input v-model="splitForm.original_title" type="text" required class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600">
                        </div>

                        <div class="space-y-4">
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider dark:text-gray-300">Additional Concerns (New Tickets)</label>
                            <div v-for="(title, index) in splitForm.new_titles" :key="index" class="flex gap-2">
                                <input v-model="splitForm.new_titles[index]" type="text" placeholder="Enter new ticket subject..." class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600">
                                <button type="button" @click="removeSplitConcern(index)" class="p-2 text-red-600 hover:bg-red-50 rounded-lg transition-colors">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                    </svg>
                                </button>
                            </div>
                            <button type="button" @click="addSplitConcern" class="text-sm font-bold text-blue-600 hover:text-blue-700 flex items-center gap-2">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                                </svg>
                                Add Another Concern
                            </button>
                        </div>

                        <div class="flex justify-end space-x-3 pt-6 border-t mt-6">
                            <button type="button" @click="showSplitModal = false" class="px-4 py-2 text-sm font-semibold text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">Cancel</button>
                            <button type="submit" :disabled="splitForm.processing" class="px-6 py-2 bg-blue-600 text-white text-sm font-bold rounded-lg hover:bg-blue-700 shadow-md disabled:opacity-50 transition-all">
                                Split into {{ splitForm.new_titles.length + 1 }} Tickets
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Merge Tickets Modal -->
        <div v-if="showMergeModal" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex items-center justify-center min-h-screen px-4">
                <div class="fixed inset-0 bg-black/20 backdrop-blur-md" @click="showMergeModal = false"></div>
                <div class="bg-white rounded-xl shadow-2xl max-w-lg w-full p-6 relative border border-gray-100 dark:bg-gray-800 dark:border-gray-700">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="text-xl font-bold text-gray-900 dark:text-gray-100">Merge Tickets</h3>
                        <button @click="showMergeModal = false" class="text-gray-400 hover:text-gray-600 dark:text-gray-400">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>

                    <form @submit.prevent="submitMerge" class="space-y-6">
                        <p class="text-sm text-gray-600 bg-purple-50 p-3 rounded-lg border border-purple-100 dark:text-gray-300">
                            Select the **Parent Ticket** to retain. All other tickets will be closed and linked to the parent. Requesters will be notified.
                        </p>

                        <div class="space-y-3">
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider dark:text-gray-300">Select Parent Ticket</label>
                            <div v-for="ticket in getSelectedTickets" :key="ticket.id" 
                                 class="flex items-center p-3 rounded-lg border cursor-pointer transition-all"
                                 :class="mergeForm.parent_id === ticket.id ? 'bg-blue-50 border-blue-200 ring-1 ring-blue-200' : 'bg-white border-gray-200 hover:border-gray-300'"
                                 @click="mergeForm.parent_id = ticket.id">
                                <input type="radio" :value="ticket.id" v-model="mergeForm.parent_id" class="w-4 h-4 text-blue-600 focus:ring-blue-500 border-gray-300 cursor-pointer dark:border-gray-600">
                                <div class="ml-3 flex-1">
                                    <div class="text-xs font-bold text-blue-600">{{ ticket.ticket_key }}</div>
                                    <div class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ ticket.title }}</div>
                                </div>
                            </div>
                        </div>

                        <div class="flex justify-end space-x-3 pt-6 border-t mt-6">
                            <button type="button" @click="showMergeModal = false" class="px-4 py-2 text-sm font-semibold text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">Cancel</button>
                            <button type="submit" :disabled="mergeForm.processing" class="px-6 py-2 bg-purple-600 text-white text-sm font-bold rounded-lg hover:bg-purple-700 shadow-md disabled:opacity-50 transition-all">
                                Merge {{ selectedIds.length }} Tickets
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Requester Tickets Modal -->
        <div v-if="showRequesterModal" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex items-center justify-center min-h-screen px-4">
                <div class="fixed inset-0 bg-black/20 backdrop-blur-md" @click="closeRequesterModal"></div>
                <div class="bg-white rounded-xl shadow-2xl max-w-4xl w-full p-6 relative border border-gray-100 flex flex-col max-h-[90vh] dark:bg-gray-800 dark:border-gray-700">
                    <div class="flex justify-between items-center mb-6 pb-4 border-b">
                        <div>
                            <h3 class="text-xl font-bold text-gray-900 dark:text-gray-100">Tickets for {{ requesterModalData?.name }}</h3>
                            <div class="text-sm text-gray-500 dark:text-gray-300">{{ requesterModalData?.email }}</div>
                        </div>
                        <button @click="closeRequesterModal" class="text-gray-400 hover:text-gray-600 transition-colors dark:text-gray-400">
                            <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>

                    <div class="flex flex-col flex-1 overflow-hidden">
                        <!-- Tabs -->
                        <div class="flex space-x-1 overflow-x-auto border-b border-gray-200 mb-4 pb-px dark:border-gray-700">
                            <button
                                v-for="tab in requesterTabs"
                                :key="tab.id"
                                @click="requesterActiveTab = tab.id"
                                class="px-4 py-2 text-sm font-semibold whitespace-nowrap transition-colors"
                                :class="requesterActiveTab === tab.id ? 'border-b-2 border-blue-600 text-blue-600' : 'text-gray-500 hover:text-gray-700'"
                            >
                                {{ tab.name }} <span class="ml-1 px-1.5 py-0.5 rounded-full text-xs" :class="requesterActiveTab === tab.id ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-600'">{{ tab.count }}</span>
                            </button>
                        </div>

                        <!-- Ticket List -->
                        <div class="flex-1 overflow-y-auto">
                            <div v-if="isRequesterTicketsLoading" class="flex justify-center items-center py-12">
                                <svg class="animate-spin h-8 w-8 text-blue-600" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 22 6.477 22 12h-4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                            </div>
                            <div v-else-if="filteredRequesterTickets.length === 0" class="text-center py-12 text-gray-500 dark:text-gray-300">
                                No tickets found for this status.
                            </div>
                            <div v-else class="space-y-3 pr-2">
                                <div v-for="t in filteredRequesterTickets" :key="t.id" @click="goToTicket(t.id)" class="group flex flex-col sm:flex-row sm:items-center justify-between p-4 border rounded-lg hover:border-blue-300 hover:bg-blue-50 cursor-pointer transition-all">
                                    <div>
                                        <div class="flex items-center gap-2 mb-1">
                                            <span class="text-xs font-bold text-blue-600">{{ t.ticket_key }}</span>
                                            <span class="inline-flex px-2 py-0.5 rounded-full text-[10px] font-bold capitalize" :class="getStatusColor(t.status)">
                                                {{ getStatusLabel(t.status) }}
                                            </span>
                                        </div>
                                        <div class="font-semibold text-gray-900 group-hover:text-blue-700 dark:text-gray-100">{{ t.title }}</div>
                                        <div class="text-xs text-gray-500 mt-1 flex items-center gap-3 dark:text-gray-300">
                                            <span>Created: {{ formatDate(t.created_at) }}</span>
                                            <span>Assignee: {{ t.assignee?.name || 'Unassigned' }}</span>
                                        </div>
                                    </div>
                                    <div class="mt-3 sm:mt-0 text-blue-600 opacity-0 group-hover:opacity-100 transition-opacity">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    <!-- Export Options Modal -->
    <div v-if="showExportModal" class="fixed inset-0 z-50 overflow-y-auto">
        <div class="flex items-center justify-center min-h-screen px-4 py-6">
            <div class="fixed inset-0 bg-black/20 backdrop-blur-md" @click="closeExportModal"></div>
            <div class="relative bg-white rounded-xl shadow-2xl w-full max-w-lg p-6 border border-gray-100 dark:bg-gray-800 dark:border-gray-700">
                <div class="flex justify-between items-center mb-5">
                    <div>
                        <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100">Export Options</h3>
                        <p class="text-xs text-gray-500 mt-0.5 dark:text-gray-300">Apply additional filters before exporting to Excel</p>
                    </div>
                    <button @click="closeExportModal" class="text-gray-400 hover:text-gray-600 transition-colors dark:text-gray-400">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="space-y-4">
                    <!-- Date Range -->
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1.5 dark:text-gray-300">Date Range (Created)</label>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <span class="block text-[10px] font-semibold text-gray-400 uppercase tracking-wider mb-1 dark:text-gray-400">From</span>
                                <input type="date" v-model="exportFrom"
                                       class="w-full bg-white border border-gray-300 rounded-lg shadow-sm px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600" />
                            </div>
                            <div>
                                <span class="block text-[10px] font-semibold text-gray-400 uppercase tracking-wider mb-1 dark:text-gray-400">To</span>
                                <input type="date" v-model="exportTo" :min="exportFrom"
                                       class="w-full bg-white border border-gray-300 rounded-lg shadow-sm px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600" />
                            </div>
                        </div>
                    </div>

                    <!-- Item / Concern OR SubCategory -->
                    <div>
                        <div class="flex items-center justify-between mb-1.5">
                            <div class="flex items-center gap-2">
                                <label class="text-xs font-bold text-gray-500 uppercase tracking-wider dark:text-gray-300">Filter By</label>
                                <select v-model="exportFieldMode"
                                        class="bg-white border border-gray-300 rounded-md text-xs font-semibold text-gray-700 pl-2 pr-7 py-1 focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 dark:bg-gray-800 dark:text-gray-300 dark:border-gray-600">
                                    <option value="sub_category">SubCategory</option>
                                    <option value="item">Item / Concern</option>
                                </select>
                            </div>
                            <button v-if="(exportFieldMode === 'item' ? exportFilterItemIds.length : exportFilterSubCategoryIds.length)"
                                    type="button"
                                    @click="exportFieldMode === 'item' ? (exportFilterItemIds = []) : (exportFilterSubCategoryIds = [])"
                                    class="text-xs font-semibold text-red-500 hover:text-red-700">Clear</button>
                        </div>
                        <MultiAutocomplete
                            v-if="exportFieldMode === 'item'"
                            v-model="exportFilterItemIds"
                            :options="exportItems"
                            label-key="display_name"
                            value-key="id"
                            placeholder="All Items"
                        />
                        <MultiAutocomplete
                            v-else
                            v-model="exportFilterSubCategoryIds"
                            :options="exportSubCategories"
                            label-key="name"
                            value-key="id"
                            placeholder="All SubCategories"
                        />
                    </div>

                    <!-- Requester -->
                    <div>
                        <div class="flex items-center justify-between mb-1.5">
                            <label class="text-xs font-bold text-gray-500 uppercase tracking-wider dark:text-gray-300">Requester</label>
                            <button v-if="exportFilterRequester" type="button" @click="exportFilterRequester = ''"
                                    class="text-xs font-semibold text-red-500 hover:text-red-700">Clear</button>
                        </div>
                        <Autocomplete
                            v-model="exportFilterRequester"
                            :options="staff || []"
                            label-key="name"
                            value-key="name"
                            placeholder="Type or search requester name..."
                            :allow-custom="true"
                        />
                    </div>

                    <!-- Priority -->
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2 dark:text-gray-300">Priority Level</label>
                        <div class="flex flex-wrap gap-2">
                            <label v-for="p in ['Low','Medium','High','Urgent']" :key="p"
                                   class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg border cursor-pointer text-xs font-semibold transition-colors"
                                   :class="exportFilterPriority.includes(p)
                                       ? (p === 'Low' ? 'bg-green-100 border-green-400 text-green-800' : p === 'Medium' ? 'bg-yellow-100 border-yellow-400 text-yellow-800' : p === 'High' ? 'bg-orange-100 border-orange-400 text-orange-800' : 'bg-red-100 border-red-400 text-red-800')
                                       : 'bg-gray-50 border-gray-200 text-gray-500 hover:border-gray-300'">
                                <input type="checkbox" :value="p" v-model="exportFilterPriority" class="hidden">
                                {{ p }}
                            </label>
                        </div>
                    </div>

                    <!-- Concern Type -->
                    <div>
                        <div class="flex items-center justify-between mb-1.5">
                            <label class="text-xs font-bold text-gray-500 uppercase tracking-wider dark:text-gray-300">Concern Type</label>
                            <button v-if="exportFilterConcernType" type="button" @click="exportFilterConcernType = ''"
                                    class="text-xs font-semibold text-red-500 hover:text-red-700">Clear</button>
                        </div>
                        <Autocomplete
                            v-model="exportFilterConcernType"
                            :options="[{value:'Incident',label:'Incident'},{value:'Service Request',label:'Service Request'},{value:'Problem',label:'Problem'}]"
                            label-key="label"
                            value-key="value"
                            placeholder="All Types"
                        />
                    </div>
                </div>

                <div class="flex justify-between items-center pt-5 mt-5 border-t border-gray-100 dark:border-gray-700">
                    <button type="button" @click="closeExportModal"
                            class="px-4 py-2 text-sm font-semibold text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">
                        Cancel
                    </button>
                    <button type="button" @click="exportToExcel"
                            class="px-6 py-2 bg-emerald-600 text-white text-sm font-bold rounded-lg hover:bg-emerald-700 shadow-md transition-all flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                        </svg>
                        Export Excel
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Scroll to top -->
    <Transition
        enter-active-class="transition ease-out duration-200"
        enter-from-class="opacity-0 translate-y-2"
        enter-to-class="opacity-100 translate-y-0"
        leave-active-class="transition ease-in duration-150"
        leave-from-class="opacity-100 translate-y-0"
        leave-to-class="opacity-0 translate-y-2"
    >
        <button
            v-show="showScrollTop"
            @click="scrollToTop"
            type="button"
            title="Scroll to top"
            aria-label="Scroll to top"
            class="fixed bottom-6 right-6 z-50 flex h-11 w-11 items-center justify-center rounded-full bg-blue-600 text-white shadow-lg ring-1 ring-blue-700/40 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition-colors dark:ring-blue-400/30"
        >
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 15l7-7 7 7" />
            </svg>
        </button>
    </Transition>

    </AppLayout>
</template>

<style scoped>
@keyframes pulse-red {
  0%, 100% { opacity: 1; transform: scale(1); }
  50% { opacity: 0.7; transform: scale(0.95); background-color: #fee2e2; }
}
@keyframes pulse-yellow {
  0%, 100% { background-color: #fef9c3; border-color: #fde047; }
  50% { background-color: #fef08a; border-color: #facc15; }
}
.animate-pulse-red {
  animation: pulse-red 1.5s cubic-bezier(0.4, 0, 0.6, 1) infinite;
}
.animate-pulse-yellow {
  animation: pulse-yellow 2s ease-in-out infinite;
}
</style>
