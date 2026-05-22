<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import HierarchySelector from '@/Components/HierarchySelector.vue';
import Modal from '@/Components/Modal.vue';
import MultiAutocomplete from '@/Components/MultiAutocomplete.vue';
import { useToast } from '@/Composables/useToast';
import { usePermission } from '@/Composables/usePermission';
import {
    ArchiveBoxIcon,
    CalendarDaysIcon,
    PlusIcon,
    StarIcon,
    XMarkIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    boards: Array,
    users: Array,
    monthlyDepartments: Array,
    hierarchicalDepartments: Array,
    filters: Object,
});

const { showError } = useToast();
const { hasPermission } = usePermission();

const showCreateModal = ref(false);
const showGenerateMonthlyModal = ref(false);
const isSubmitting = ref(false);
const isGeneratingMonthly = ref(false);
const search = ref('');
const showClosed = ref(!!props.filters?.closed);
const now = new Date();
const currentYear = now.getFullYear();

const filterNodeId = ref(
    props.filters?.department_node_id
        ? props.filters.department_node_id
        : (props.filters?.department_id ? `dept-${props.filters.department_id}` : '')
);
const monthlyNodeId = ref('');

const form = reactive({
    title: '',
    description: '',
    background_type: 'color',
    background_value: '#0f766e',
    member_ids: [],
});

const monthlyForm = reactive({
    month: now.getMonth() + 1,
    year: currentYear,
});

const boardFilters = reactive({
    month: '',
    year: '',
});

const backgroundOptions = [
    '#0f766e',
    '#1d4ed8',
    '#7c3aed',
    '#be123c',
    '#b45309',
    '#374151',
];

const monthOptions = [
    { value: 1, label: 'January' },
    { value: 2, label: 'February' },
    { value: 3, label: 'March' },
    { value: 4, label: 'April' },
    { value: 5, label: 'May' },
    { value: 6, label: 'June' },
    { value: 7, label: 'July' },
    { value: 8, label: 'August' },
    { value: 9, label: 'September' },
    { value: 10, label: 'October' },
    { value: 11, label: 'November' },
    { value: 12, label: 'December' },
];

const yearOptions = computed(() => {
    return Array.from({ length: 5 }, (_, index) => currentYear - 1 + index);
});

const normalizeValue = (value) => String(value || '').trim();

const hierarchicalOptions = computed(() =>
    (props.hierarchicalDepartments || []).map((dept) => ({
        ...dept,
        id: `dept-${dept.id}`,
        children: dept.nodes || [],
    }))
);

const deptFilterParams = computed(() => {
    const nodeId = filterNodeId.value;
    if (!nodeId) return {};
    if (typeof nodeId === 'string' && nodeId.startsWith('dept-')) {
        return { department_id: nodeId.replace('dept-', '') };
    }
    return { department_node_id: nodeId };
});

const applyDepartmentFilter = () => {
    router.get(route('task-boards.index'), {
        closed: showClosed.value ? 1 : 0,
        ...deptFilterParams.value,
        ...(filterNodeId.value === '' ? { skip_default_department: 1 } : {}),
    }, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });
};

watch(filterNodeId, applyDepartmentFilter);

const yearFilterOptions = computed(() => {
    return [...new Set([
        currentYear,
        ...(props.boards || [])
            .map((board) => Number(board.board_year))
            .filter(Boolean),
    ])]
        .sort((a, b) => b - a);
});

const hasBoardFilters = computed(() => {
    return !!(search.value.trim() || filterNodeId.value || boardFilters.month || boardFilters.year);
});

const totalBoardCount = computed(() => (props.boards || []).length);

const filteredBoards = computed(() => {
    const term = search.value.trim().toLowerCase();
    const month = Number(boardFilters.month) || null;
    const year = Number(boardFilters.year) || null;

    return (props.boards || []).filter((board) => {
        const matchesSearch = !term ||
            board.title?.toLowerCase().includes(term) ||
            board.description?.toLowerCase().includes(term) ||
            board.department?.toLowerCase().includes(term) ||
            board.sub_unit?.toLowerCase().includes(term);

        const matchesMonth = !month || Number(board.board_month) === month;
        const matchesYear = !year || Number(board.board_year) === year;

        return matchesSearch && matchesMonth && matchesYear;
    });
});

const sortedBoards = computed(() => {
    return [...filteredBoards.value].sort((a, b) => Number(b.starred) - Number(a.starred));
});

const normalizeMatrixKey = (value) => normalizeValue(value).toLowerCase();

const effectiveMatrixNodeId = computed(() => filterNodeId.value || hierarchicalOptions.value[0]?.id || '');

const selectedMatrixNode = computed(() => findNode(hierarchicalOptions.value, effectiveMatrixNodeId.value));

const collectMatrixNodes = (node) => {
    if (!node) return [];

    return [
        node,
        ...(node.children || []).flatMap((child) => collectMatrixNodes(child)),
    ];
};

const makeHierarchyMatrixColumn = (node) => {
    const path = findNodePath(hierarchicalOptions.value, node.id).join(' > ') || node.name;

    return {
        id: `node-${node.id}`,
        label: node.name,
        path,
        matchKeys: [
            normalizeMatrixKey(node.name),
            normalizeMatrixKey(path),
        ].filter(Boolean),
    };
};

const hierarchyMatrixColumns = computed(() => {
    const selected = selectedMatrixNode.value;
    if (!selected) return [];

    return collectMatrixNodes(selected).map((node) => makeHierarchyMatrixColumn(node));
});

const selectedMatrixYear = computed(() => Number(boardFilters.year) || currentYear);

const displayedMonths = computed(() => {
    const month = Number(boardFilters.month) || null;

    return month ? monthOptions.filter((item) => Number(item.value) === month) : monthOptions;
});

const boardTeamKey = (board) => {
    return normalizeMatrixKey(board.sub_unit || board.department || 'No Team');
};

const boardTeamLabel = (board) => {
    return board.sub_unit || board.department || 'No Team';
};

const boardMatchesColumn = (board, column) => {
    const teamKey = boardTeamKey(board);
    if (!teamKey) return false;

    return column.matchKeys.some((key) => teamKey === key || teamKey.startsWith(`${key} > `));
};

const fallbackMatrixColumns = computed(() => {
    const columns = [];
    const seen = new Set(hierarchyMatrixColumns.value.flatMap((column) => column.matchKeys));

    for (const board of sortedBoards.value) {
        if (hierarchyMatrixColumns.value.some((column) => boardMatchesColumn(board, column))) {
            continue;
        }

        const key = boardTeamKey(board);
        if (!key || seen.has(key)) {
            continue;
        }

        seen.add(key);
        columns.push({
            id: `team-${key}`,
            label: boardTeamLabel(board),
            path: boardTeamLabel(board),
            matchKeys: [key],
        });
    }

    return columns;
});

const matrixColumns = computed(() => [
    ...hierarchyMatrixColumns.value,
    ...fallbackMatrixColumns.value,
]);

const boardMatrixColumn = (board) => {
    return matrixColumns.value.find((column) => boardMatchesColumn(board, column)) || null;
};

const matrixBoards = computed(() => {
    const rows = {};

    for (const board of sortedBoards.value) {
        const year = Number(board.board_year);
        const month = Number(board.board_month);
        const column = boardMatrixColumn(board);

        if (!column) continue;

        const rowKey = year === selectedMatrixYear.value && month >= 1 && month <= 12
            ? month
            : 'unscheduled';

        rows[rowKey] ??= {};
        rows[rowKey][column.id] ??= [];
        rows[rowKey][column.id].push(board);
    }

    return rows;
});

const matrixBoardItems = (rowKey) => {
    return matrixColumns.value.flatMap((column) =>
        (matrixBoards.value[rowKey]?.[column.id] || []).map((board) => ({
            board,
            column,
        }))
    );
};

const matrixRows = computed(() => {
    const rows = displayedMonths.value.map((month) => ({
        ...month,
        boardItems: matrixBoardItems(month.value),
    }));

    if (matrixBoards.value.unscheduled) {
        rows.push({
            value: 'unscheduled',
            label: 'No Date / Other Year',
            boardItems: matrixBoardItems('unscheduled'),
        });
    }

    return rows;
});

const boardChipLabel = (board, column) => {
    const year = Number(board.board_year);
    const month = Number(board.board_month);
    const rowKey = year === selectedMatrixYear.value && month >= 1 && month <= 12
        ? month
        : 'unscheduled';
    const duplicates = matrixBoards.value[rowKey]?.[column.id] || [];

    return duplicates.length > 1 ? (board.title || column.label) : column.label;
};

const userOptions = computed(() => {
    return (props.users || []).map((user) => ({
        ...user,
        label: `${user.name}${user.email ? ` - ${user.email}` : ''}`,
    }));
});

const selectedMonthLabel = computed(() => {
    return monthOptions.find((month) => Number(month.value) === Number(monthlyForm.month))?.label || '';
});

const findNode = (nodes, id) => {
    if (id === null || id === undefined || id === '') return null;

    for (const node of nodes || []) {
        if (String(node.id) === String(id)) {
            return node;
        }

        const child = findNode(node.children || [], id);
        if (child) return child;
    }

    return null;
};

const findNodePath = (nodes, id, path = []) => {
    if (id === null || id === undefined || id === '') return [];

    for (const node of nodes || []) {
        const currentPath = [...path, node.name];
        if (String(node.id) === String(id)) {
            return currentPath;
        }

        const childPath = findNodePath(node.children || [], id, currentPath);
        if (childPath.length) return childPath;
    }

    return [];
};

const collectNodeIds = (node) => {
    if (!node) return [];

    return [
        Number(node.id),
        ...(node.children || []).flatMap((child) => collectNodeIds(child)),
    ];
};

const usersForNodeIds = (nodeIds) => {
    const allowedIds = new Set(nodeIds.map((id) => Number(id)));

    return (props.users || []).filter((user) => allowedIds.has(Number(user.department_node_id)));
};

const directUsersForNode = (nodeId) => {
    return (props.users || []).filter((user) => Number(user.department_node_id) === Number(nodeId));
};

const directUsersForDepartment = (departmentId) => {
    return (props.users || []).filter((user) =>
        Number(user.department_id) === Number(departmentId) &&
        !user.department_node_id
    );
};

const monthlySelectionParams = computed(() => {
    const nodeId = monthlyNodeId.value;
    if (!nodeId) return {};
    if (typeof nodeId === 'string' && nodeId.startsWith('dept-')) {
        return { department_id: nodeId.replace('dept-', '') };
    }

    return { department_node_id: nodeId };
});

const monthlyBoardPreview = computed(() => {
    const selected = monthlyNodeId.value ? findNode(hierarchicalOptions.value, monthlyNodeId.value) : null;
    if (!selected) return [];

    const rows = [];
    const isDepartmentRoot = String(selected.id).startsWith('dept-');
    const selectedDepartmentId = isDepartmentRoot
        ? Number(String(selected.id).replace('dept-', ''))
        : Number(selected.department_id);

    const pushTarget = (node, users, pathOverride = null) => {
        if (!users.length) return;

        const path = pathOverride || findNodePath(hierarchicalOptions.value, node.id).join(' > ') || node.name;

        rows.push({
            id: node.id,
            name: node.name,
            path,
            user_count: users.length,
            title: `${node.name} ${selectedMonthLabel.value} ${monthlyForm.year}`,
        });
    };

    if (isDepartmentRoot) {
        pushTarget(selected, directUsersForDepartment(selectedDepartmentId), selected.name);

        for (const child of selected.children || []) {
            pushTarget(child, usersForNodeIds(collectNodeIds(child)));
        }

        return rows;
    }

    pushTarget(selected, directUsersForNode(selected.id));

    for (const child of selected.children || []) {
        pushTarget(child, usersForNodeIds(collectNodeIds(child)));
    }

    return rows;
});

const clearBoardFilters = () => {
    search.value = '';
    boardFilters.month = '';
    boardFilters.year = '';
    filterNodeId.value = '';  // triggers watch → router.get with skip_default_department
};

const openCreateModal = () => {
    form.title = '';
    form.description = '';
    form.background_type = 'color';
    form.background_value = '#0f766e';
    form.member_ids = [];
    showCreateModal.value = true;
};

const openGenerateMonthlyModal = () => {
    monthlyNodeId.value = filterNodeId.value || hierarchicalOptions.value[0]?.id || '';
    monthlyForm.month = now.getMonth() + 1;
    monthlyForm.year = now.getFullYear();
    showGenerateMonthlyModal.value = true;
};

const submitBoard = () => {
    if (isSubmitting.value) return;
    isSubmitting.value = true;

    router.post(route('task-boards.store'), form, {
        onSuccess: () => {
            showCreateModal.value = false;
        },
        onError: (errors) => {
            showError(Object.values(errors).flat().join(', ') || 'Unable to create board');
        },
        onFinish: () => {
            isSubmitting.value = false;
        },
    });
};

const submitMonthlyBoards = () => {
    if (isGeneratingMonthly.value) return;

    if (!monthlyNodeId.value || monthlyBoardPreview.value.length === 0) {
        showError('Select a department level with active users.');
        return;
    }

    isGeneratingMonthly.value = true;

    router.post(route('task-boards.monthly-generate'), {
        ...monthlyForm,
        ...monthlySelectionParams.value,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            showGenerateMonthlyModal.value = false;
        },
        onError: (errors) => {
            showError(Object.values(errors).flat().join(', ') || 'Unable to generate monthly boards');
        },
        onFinish: () => {
            isGeneratingMonthly.value = false;
        },
    });
};

const toggleClosed = () => {
    showClosed.value = !showClosed.value;
    router.get(route('task-boards.index'), {
        closed: showClosed.value ? 1 : 0,
        ...deptFilterParams.value,
        ...(filterNodeId.value === '' ? { skip_default_department: 1 } : {}),
    }, {
        preserveScroll: true,
        preserveState: true,
    });
};

const monthLabel = (value) => monthOptions.find((month) => Number(month.value) === Number(value))?.label || '';

const boardPeriodLabel = (board) => {
    const label = monthLabel(board.board_month);
    return label && board.board_year ? `${label} ${board.board_year}` : '';
};

</script>

<template>
    <Head title="Task Board" />

    <AppLayout>
        <template #header>Task Board</template>

        <div class="space-y-6">
            <div class="flex flex-col gap-4 rounded-lg border border-gray-200 bg-white p-5 shadow-sm md:flex-row md:items-center md:justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">Task Board</h1>
                    <p class="mt-1 text-sm text-gray-500">Kanban boards for service work, follow-ups, and team tasks.</p>
                </div>
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                    <button
                        type="button"
                        @click="toggleClosed"
                        class="inline-flex h-10 items-center justify-center gap-2 rounded-lg border border-gray-300 bg-white px-4 text-sm font-semibold text-gray-700 shadow-sm transition-colors hover:bg-gray-50"
                    >
                        <ArchiveBoxIcon class="h-4 w-4" />
                        {{ showClosed ? 'Open boards' : 'Closed boards' }}
                    </button>
                    <button
                        v-if="hasPermission('task_boards.create')"
                        type="button"
                        @click="openGenerateMonthlyModal"
                        class="inline-flex h-10 items-center justify-center gap-2 rounded-lg bg-emerald-600 px-4 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-emerald-700"
                    >
                        <CalendarDaysIcon class="h-4 w-4" />
                        Generate Monthly
                    </button>
                    <button
                        v-if="hasPermission('task_boards.create')"
                        type="button"
                        @click="openCreateModal"
                        class="inline-flex h-10 items-center justify-center gap-2 rounded-lg bg-blue-600 px-4 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-blue-700"
                    >
                        <PlusIcon class="h-4 w-4" />
                        Create Board
                    </button>
                </div>
            </div>

            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)_minmax(0,0.8fr)_minmax(0,0.8fr)_auto] xl:items-end">
                    <div>
                        <label class="mb-1 block text-xs font-bold uppercase tracking-wider text-gray-500">Search</label>
                        <input
                            v-model="search"
                            type="search"
                            class="h-10 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                            placeholder="Search boards..."
                        >
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-bold uppercase tracking-wider text-gray-500">Department</label>
                        <HierarchySelector
                            v-model="filterNodeId"
                            :nodes="hierarchicalOptions"
                            placeholder="All Departments"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-bold uppercase tracking-wider text-gray-500">Month</label>
                        <select v-model="boardFilters.month" class="h-10 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <option value="">All months</option>
                            <option v-for="month in monthOptions" :key="month.value" :value="month.value">
                                {{ month.label }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-bold uppercase tracking-wider text-gray-500">Year</label>
                        <select v-model="boardFilters.year" class="h-10 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <option value="">All years</option>
                            <option v-for="year in yearFilterOptions" :key="year" :value="year">
                                {{ year }}
                            </option>
                        </select>
                    </div>
                    <button
                        type="button"
                        :disabled="!hasBoardFilters"
                        class="inline-flex h-10 items-center justify-center rounded-lg border border-gray-300 bg-white px-4 text-sm font-semibold text-gray-700 shadow-sm transition-colors hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50"
                        @click="clearBoardFilters"
                    >
                        Clear
                    </button>
                </div>
                <p class="mt-3 text-xs font-semibold text-gray-500">
                    Showing {{ sortedBoards.length }} of {{ totalBoardCount }} board{{ totalBoardCount === 1 ? '' : 's' }}
                </p>
            </div>

            <div v-if="sortedBoards.length" class="space-y-4">
                <div class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
                    <div class="flex flex-col gap-2 border-b border-gray-100 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <p class="text-xs font-black uppercase tracking-widest text-gray-400">Year</p>
                            <h2 class="text-2xl font-black text-gray-900">{{ selectedMatrixYear }}</h2>
                        </div>
                        <div class="text-xs font-semibold text-gray-500">
                            {{ matrixColumns.length }} team{{ matrixColumns.length === 1 ? '' : 's' }}
                        </div>
                    </div>

                    <div v-if="matrixColumns.length" class="overflow-x-auto">
                        <table class="min-w-full border-collapse text-left">
                            <thead>
                                <tr class="border-b border-gray-200 bg-gray-50">
                                    <th class="sticky left-0 z-10 w-28 bg-gray-50 px-4 py-3 text-xs font-black uppercase tracking-widest text-gray-500">Month</th>
                                    <th class="min-w-96 px-4 py-3 text-xs font-black uppercase tracking-widest text-gray-500">Team</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="row in matrixRows" :key="row.value" class="border-b border-gray-100 last:border-b-0">
                                    <th class="sticky left-0 z-10 bg-white px-4 py-4 text-sm font-black text-gray-800">
                                        {{ row.label }}
                                    </th>
                                    <td class="h-16 min-w-96 border-l border-gray-100 px-3 py-3 align-top">
                                        <div v-if="row.boardItems.length" class="flex flex-wrap gap-2">
                                            <Link
                                                v-for="item in row.boardItems"
                                                :key="item.board.id"
                                                :href="route('task-boards.show', item.board.id)"
                                                :title="item.board.title"
                                                class="inline-flex min-w-20 max-w-full items-center gap-1 rounded-lg border border-blue-100 bg-blue-50 px-2.5 py-1.5 text-xs font-black text-blue-700 transition-colors hover:border-blue-200 hover:bg-blue-100"
                                            >
                                                <StarIcon v-if="item.board.starred" class="h-3.5 w-3.5 shrink-0 text-yellow-500" />
                                                <span class="truncate">{{ boardChipLabel(item.board, item.column) }}</span>
                                            </Link>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-else class="p-10 text-center text-sm font-semibold text-gray-500">
                        Select a department with configured teams to show the matrix.
                    </div>
                </div>
            </div>

            <div v-else class="rounded-lg border border-dashed border-gray-300 bg-white p-12 text-center">
                <p class="text-sm font-semibold text-gray-600">No boards found.</p>
            </div>
        </div>

        <Modal :show="showGenerateMonthlyModal" @close="showGenerateMonthlyModal = false" maxWidth="2xl">
            <div class="p-6">
                <div class="mb-6 flex items-center justify-between">
                    <h2 class="text-xl font-bold text-gray-900">Generate Monthly Boards</h2>
                    <button type="button" @click="showGenerateMonthlyModal = false" class="rounded-lg p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-600">
                        <XMarkIcon class="h-5 w-5" />
                    </button>
                </div>

                <form class="space-y-5" @submit.prevent="submitMonthlyBoards">
                    <div class="grid gap-4 md:grid-cols-3">
                        <div class="md:col-span-1">
                            <label class="mb-1 block text-xs font-bold uppercase tracking-wider text-gray-500">Department</label>
                            <HierarchySelector
                                v-model="monthlyNodeId"
                                :nodes="hierarchicalOptions"
                                placeholder="Select department"
                            />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-bold uppercase tracking-wider text-gray-500">Month</label>
                            <select v-model.number="monthlyForm.month" required class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <option v-for="month in monthOptions" :key="month.value" :value="month.value">
                                    {{ month.label }}
                                </option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-bold uppercase tracking-wider text-gray-500">Year</label>
                            <select v-model.number="monthlyForm.year" required class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <option v-for="year in yearOptions" :key="year" :value="year">
                                    {{ year }}
                                </option>
                            </select>
                        </div>
                    </div>

                    <div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
                        <div class="mb-3 flex items-center justify-between gap-3">
                            <p class="text-xs font-bold uppercase tracking-wider text-gray-500">Boards to Create</p>
                            <span class="rounded-full bg-white px-2.5 py-1 text-xs font-bold text-gray-600 ring-1 ring-gray-200">
                                {{ monthlyBoardPreview.length }} board{{ monthlyBoardPreview.length === 1 ? '' : 's' }}
                            </span>
                        </div>

                        <div v-if="monthlyBoardPreview.length" class="max-h-72 space-y-2 overflow-y-auto pr-1">
                            <div
                                v-for="board in monthlyBoardPreview"
                                :key="board.name"
                                class="flex items-center justify-between gap-3 rounded-lg border border-gray-200 bg-white px-3 py-2"
                            >
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-bold text-gray-900">{{ board.title }}</p>
                                    <p class="text-xs font-medium text-gray-500">{{ board.path }}</p>
                                </div>
                                <span class="shrink-0 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-bold text-emerald-700">
                                    {{ board.user_count }} user{{ board.user_count === 1 ? '' : 's' }}
                                </span>
                            </div>
                        </div>
                        <p v-else class="py-8 text-center text-sm font-semibold text-gray-500">No active sub-units found.</p>
                    </div>

                    <div class="flex justify-end gap-3 border-t border-gray-100 pt-5">
                        <button type="button" @click="showGenerateMonthlyModal = false" class="rounded-lg bg-gray-100 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-200">Cancel</button>
                        <button
                            type="submit"
                            :disabled="isGeneratingMonthly || monthlyBoardPreview.length === 0"
                            class="rounded-lg bg-emerald-600 px-5 py-2 text-sm font-bold text-white shadow-sm hover:bg-emerald-700 disabled:opacity-50"
                        >
                            {{ isGeneratingMonthly ? 'Generating...' : 'Generate Boards' }}
                        </button>
                    </div>
                </form>
            </div>
        </Modal>

        <Modal :show="showCreateModal" @close="showCreateModal = false" maxWidth="2xl">
            <div class="p-6">
                <div class="mb-6 flex items-center justify-between">
                    <h2 class="text-xl font-bold text-gray-900">Create Board</h2>
                    <button type="button" @click="showCreateModal = false" class="rounded-lg p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-600">
                        <XMarkIcon class="h-5 w-5" />
                    </button>
                </div>

                <form class="space-y-5" @submit.prevent="submitBoard">
                    <div>
                        <label class="mb-1 block text-xs font-bold uppercase tracking-wider text-gray-500">Board title</label>
                        <input v-model="form.title" type="text" required class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-bold uppercase tracking-wider text-gray-500">Description</label>
                        <textarea v-model="form.description" rows="3" class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"></textarea>
                    </div>
                    <div>
                        <label class="mb-2 block text-xs font-bold uppercase tracking-wider text-gray-500">Background</label>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="color in backgroundOptions"
                                :key="color"
                                type="button"
                                class="h-9 w-14 rounded-md border-2 transition"
                                :class="form.background_value === color ? 'border-gray-900' : 'border-white ring-1 ring-gray-200'"
                                :style="{ background: color }"
                                @click="form.background_type = 'color'; form.background_value = color"
                            ></button>
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-bold uppercase tracking-wider text-gray-500">Members</label>
                        <MultiAutocomplete
                            v-model="form.member_ids"
                            :options="userOptions"
                            label-key="label"
                            value-key="id"
                            placeholder="Select one or more members..."
                        />
                    </div>
                    <div class="flex justify-end gap-3 border-t border-gray-100 pt-5">
                        <button type="button" @click="showCreateModal = false" class="rounded-lg bg-gray-100 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-200">Cancel</button>
                        <button type="submit" :disabled="isSubmitting" class="rounded-lg bg-blue-600 px-5 py-2 text-sm font-bold text-white shadow-sm hover:bg-blue-700 disabled:opacity-50">
                            {{ isSubmitting ? 'Creating...' : 'Create Board' }}
                        </button>
                    </div>
                </form>
            </div>
        </Modal>
    </AppLayout>
</template>
