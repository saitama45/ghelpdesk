<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Autocomplete from '@/Components/Autocomplete.vue';
import Modal from '@/Components/Modal.vue';
import MultiAutocomplete from '@/Components/MultiAutocomplete.vue';
import { useToast } from '@/Composables/useToast';
import { usePermission } from '@/Composables/usePermission';
import {
    ArchiveBoxIcon,
    CalendarDaysIcon,
    PlusIcon,
    StarIcon,
    UserGroupIcon,
    XMarkIcon,
} from '@heroicons/vue/24/outline';
import { StarIcon as StarSolidIcon } from '@heroicons/vue/24/solid';

const props = defineProps({
    boards: Array,
    users: Array,
    monthlyDepartments: Array,
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

const form = reactive({
    title: '',
    description: '',
    background_type: 'color',
    background_value: '#0f766e',
    member_ids: [],
});

const monthlyForm = reactive({
    department: '',
    month: now.getMonth() + 1,
    year: currentYear,
});

const boardFilters = reactive({
    department: '',
    subUnit: '',
    month: '',
    year: currentYear,
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

const uniqueSorted = (values) => {
    return [...new Set(values.map(normalizeValue).filter(Boolean))]
        .sort((a, b) => a.localeCompare(b, undefined, { sensitivity: 'base', numeric: true }));
};

const departmentFilterOptions = computed(() => {
    const fromBoards = (props.boards || []).map((board) => board.department);
    const fromDepartmentSetup = (props.monthlyDepartments || []).map((department) => department.name);

    return uniqueSorted([...fromBoards, ...fromDepartmentSetup]);
});

const subUnitFilterOptions = computed(() => {
    const selectedDepartment = normalizeValue(boardFilters.department).toLowerCase();
    const fromBoards = (props.boards || [])
        .filter((board) => !selectedDepartment || normalizeValue(board.department).toLowerCase() === selectedDepartment)
        .map((board) => board.sub_unit);

    const fromDepartmentSetup = (props.monthlyDepartments || [])
        .filter((department) => !selectedDepartment || normalizeValue(department.name).toLowerCase() === selectedDepartment)
        .flatMap((department) => (department.sub_units || []).map((subUnit) => subUnit.name));

    return uniqueSorted([...fromBoards, ...fromDepartmentSetup]);
});

const departmentAutocompleteOptions = computed(() => [
    { id: '', name: 'All departments' },
    ...departmentFilterOptions.value.map((department) => ({
        id: department,
        name: department,
    })),
]);

const subUnitAutocompleteOptions = computed(() => [
    { id: '', name: 'All sub-units' },
    ...subUnitFilterOptions.value.map((subUnit) => ({
        id: subUnit,
        name: subUnit,
    })),
]);

const monthFilterOptions = computed(() => {
    const boardMonths = uniqueSorted((props.boards || []).map((board) => board.board_month))
        .map((month) => Number(month))
        .filter((month) => month >= 1 && month <= 12);

    return monthOptions.filter((month) => boardMonths.includes(month.value));
});

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
    return !!(search.value.trim() || boardFilters.department || boardFilters.subUnit || boardFilters.month || boardFilters.year);
});

const totalBoardCount = computed(() => (props.boards || []).length);

const filteredBoards = computed(() => {
    const term = search.value.trim().toLowerCase();
    const department = normalizeValue(boardFilters.department).toLowerCase();
    const subUnit = normalizeValue(boardFilters.subUnit).toLowerCase();
    const month = Number(boardFilters.month) || null;
    const year = Number(boardFilters.year) || null;

    return (props.boards || []).filter((board) => {
        const matchesSearch = !term ||
            board.title?.toLowerCase().includes(term) ||
            board.description?.toLowerCase().includes(term) ||
            board.department?.toLowerCase().includes(term) ||
            board.sub_unit?.toLowerCase().includes(term);

        const matchesDepartment = !department || normalizeValue(board.department).toLowerCase() === department;
        const matchesSubUnit = !subUnit || normalizeValue(board.sub_unit).toLowerCase() === subUnit;
        const matchesMonth = !month || Number(board.board_month) === month;
        const matchesYear = !year || Number(board.board_year) === year;

        return matchesSearch && matchesDepartment && matchesSubUnit && matchesMonth && matchesYear;
    });
});

const sortedBoards = computed(() => {
    return [...filteredBoards.value].sort((a, b) => Number(b.starred) - Number(a.starred));
});

const userOptions = computed(() => {
    return (props.users || []).map((user) => ({
        ...user,
        label: `${user.name}${user.email ? ` - ${user.email}` : ''}`,
    }));
});

const monthlyDepartmentOptions = computed(() => props.monthlyDepartments || []);

const selectedMonthlyDepartment = computed(() => {
    return monthlyDepartmentOptions.value.find((department) => department.name === monthlyForm.department) || null;
});

const selectedMonthLabel = computed(() => {
    return monthOptions.find((month) => Number(month.value) === Number(monthlyForm.month))?.label || '';
});

const monthlyBoardPreview = computed(() => {
    const department = selectedMonthlyDepartment.value;
    if (!department) return [];

    return (department.sub_units || []).map((subUnit) => ({
        ...subUnit,
        title: `${subUnit.name} ${selectedMonthLabel.value} ${monthlyForm.year}`,
    }));
});

watch(() => boardFilters.department, () => {
    if (boardFilters.subUnit && !subUnitFilterOptions.value.includes(boardFilters.subUnit)) {
        boardFilters.subUnit = '';
    }
});

const clearBoardFilters = () => {
    search.value = '';
    boardFilters.department = '';
    boardFilters.subUnit = '';
    boardFilters.month = '';
    boardFilters.year = '';
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
    if (!monthlyForm.department && monthlyDepartmentOptions.value.length) {
        monthlyForm.department = monthlyDepartmentOptions.value[0].name;
    }

    monthlyForm.month = now.getMonth() + 1;
    monthlyForm.year = now.getFullYear();
    showGenerateMonthlyModal.value = true;
};

const submitBoard = () => {
    if (isSubmitting.value) return;
    isSubmitting.value = true;

    router.post(route('task-boards.store'), form, {
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

    if (!monthlyForm.department || monthlyBoardPreview.value.length === 0) {
        showError('Select a department with active sub-units.');
        return;
    }

    isGeneratingMonthly.value = true;

    router.post(route('task-boards.monthly-generate'), monthlyForm, {
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
    router.get(route('task-boards.index'), { closed: showClosed.value ? 1 : 0 }, {
        preserveScroll: true,
        preserveState: true,
    });
};

const boardBackground = (board) => {
    if (board.background_type === 'image' && board.background_value) {
        return {
            backgroundImage: `linear-gradient(120deg, rgba(15, 23, 42, 0.74), rgba(15, 23, 42, 0.38)), url(${board.background_value})`,
            backgroundSize: 'cover',
            backgroundPosition: 'center',
        };
    }

    return {
        background: board.background_value || '#0f766e',
    };
};

const monthLabel = (value) => monthOptions.find((month) => Number(month.value) === Number(value))?.label || '';

const boardPeriodLabel = (board) => {
    const label = monthLabel(board.board_month);
    return label && board.board_year ? `${label} ${board.board_year}` : '';
};

const initials = (name) => (name || 'U').split(' ').map((part) => part[0]).join('').slice(0, 2).toUpperCase();
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
                <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,0.8fr)_minmax(0,0.8fr)_auto] xl:items-end">
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
                        <Autocomplete
                            v-model="boardFilters.department"
                            :options="departmentAutocompleteOptions"
                            label-key="name"
                            value-key="id"
                            placeholder="All departments"
                            class="modern-autocomplete"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-bold uppercase tracking-wider text-gray-500">Sub-Unit</label>
                        <Autocomplete
                            v-model="boardFilters.subUnit"
                            :options="subUnitAutocompleteOptions"
                            label-key="name"
                            value-key="id"
                            placeholder="All sub-units"
                            class="modern-autocomplete"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-bold uppercase tracking-wider text-gray-500">Month</label>
                        <select v-model="boardFilters.month" class="h-10 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <option value="">All months</option>
                            <option v-for="month in monthFilterOptions" :key="month.value" :value="month.value">
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

            <div v-if="sortedBoards.length" class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                <Link
                    v-for="board in sortedBoards"
                    :key="board.id"
                    :href="route('task-boards.show', board.id)"
                    class="group overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm transition-all hover:-translate-y-0.5 hover:shadow-md"
                >
                    <div class="h-28 p-4 text-white" :style="boardBackground(board)">
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <StarSolidIcon v-if="board.starred" class="h-4 w-4 shrink-0 text-yellow-300" />
                                    <StarIcon v-else class="h-4 w-4 shrink-0 text-white/50 opacity-0 transition-opacity group-hover:opacity-100" />
                                    <h2 class="truncate text-lg font-black">{{ board.title }}</h2>
                                </div>
                                <p class="mt-2 line-clamp-2 text-xs font-medium text-white/80">{{ board.description || 'No board description' }}</p>
                            </div>
                            <div class="flex shrink-0 flex-col items-end gap-1">
                                <span v-if="board.is_monthly_board" class="rounded-full bg-white/20 px-2 py-1 text-[10px] font-black uppercase tracking-wider">Monthly</span>
                                <span v-if="board.is_project_board" class="rounded-full bg-white/20 px-2 py-1 text-[10px] font-black uppercase tracking-wider">Project</span>
                                <span v-if="board.closed_at" class="rounded-full bg-black/25 px-2 py-1 text-[10px] font-black uppercase tracking-wider">Closed</span>
                            </div>
                        </div>
                    </div>
                    <div class="space-y-3 px-4 py-3">
                        <div v-if="board.department || board.sub_unit || boardPeriodLabel(board)" class="flex flex-wrap gap-2 text-[11px] font-bold text-gray-600">
                            <span v-if="board.department || board.sub_unit" class="rounded-md bg-gray-100 px-2 py-1">
                                {{ [board.department, board.sub_unit].filter(Boolean).join(' / ') }}
                            </span>
                            <span v-if="boardPeriodLabel(board)" class="rounded-md bg-gray-100 px-2 py-1">
                                {{ boardPeriodLabel(board) }}
                            </span>
                        </div>
                        <div v-if="board.project" class="rounded-md bg-blue-50 px-3 py-2 text-xs font-semibold text-blue-900">
                            <div class="flex items-center justify-between gap-3">
                                <span class="truncate">{{ board.project.store?.name || board.project.name }}</span>
                                <span class="shrink-0">{{ board.project.progress }}%</span>
                            </div>
                            <div class="mt-1 flex flex-wrap gap-2 text-[11px] text-blue-700">
                                <span>{{ board.project.activity_count }} activities</span>
                                <span>{{ board.project.subtask_count }} sub-tasks</span>
                                <span>{{ board.project.milestone_count }} milestones</span>
                            </div>
                        </div>
                        <div class="flex items-center justify-between">
                            <div class="flex -space-x-2">
                                <div
                                    v-for="member in board.members.slice(0, 5)"
                                    :key="member.id"
                                    class="flex h-8 w-8 items-center justify-center overflow-hidden rounded-full border-2 border-white bg-gray-100 text-[10px] font-bold text-gray-700"
                                    :title="member.name"
                                >
                                    <img v-if="member.profile_photo" :src="'/serve-storage/' + member.profile_photo" class="h-full w-full object-cover" :alt="member.name">
                                    <span v-else>{{ initials(member.name) }}</span>
                                </div>
                            </div>
                            <div class="flex items-center gap-2 text-xs font-semibold text-gray-500">
                                <UserGroupIcon class="h-4 w-4" />
                                {{ board.members_count }}
                            </div>
                        </div>
                    </div>
                </Link>
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
                            <select v-model="monthlyForm.department" required class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <option value="" disabled>Select department</option>
                                <option v-for="department in monthlyDepartmentOptions" :key="department.name" :value="department.name">
                                    {{ department.name }}
                                </option>
                            </select>
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
                                    <p class="text-xs font-medium text-gray-500">{{ board.name }}</p>
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
