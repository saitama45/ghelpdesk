<script setup>
import { Head, useForm, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { ChevronLeftIcon } from '@heroicons/vue/24/outline';
import Autocomplete from '@/Components/Autocomplete.vue';

const props = defineProps({
    stores:           Array,
    vendors:          Array,
    departments:      Array,
    projectTypes:     Array,
    defaultType:      { type: String, default: 'Store Opening' },
    boardYears:       Array,
    availableBoards:  { type: Array, default: () => [] },
});

const now = new Date();

const sortedStores = computed(() =>
    [...(props.stores ?? [])].sort((a, b) =>
        String(a.name ?? '').localeCompare(String(b.name ?? ''), undefined, { sensitivity: 'base' })
    )
);

const sortedVendors = computed(() =>
    (props.vendors ?? []).map(v => ({ label: v.name, value: v.id }))
);

const sortedDepartments = computed(() =>
    (props.departments ?? []).map(d => ({ label: d.name, value: d.id }))
);

const boardOptions = computed(() =>
    (props.availableBoards ?? []).map(b => ({ label: b.title, value: b.id }))
);

const form = useForm({
    project_type: props.defaultType,
    board_id:     null,
    store_id:     '',
    subject_type: '',
    subject_id:   '',
    name:         '',
    status:       'Planning',
    turn_over_date:               '',
    training_date:                '',
    testing_date:                 '',
    mock_service_date:            '',
    turn_over_to_franchisee_date: '',
    target_go_live:               '',
    day1_date:                    '',
    board_month: now.getMonth() + 1,
    board_year:  now.getFullYear(),
    remarks:     ''
});

const isStoreOpening   = computed(() => form.project_type === 'Store Opening');
const isVendorProject  = computed(() => form.project_type === 'Vendor Project');
const isInitiative     = computed(() => form.project_type === 'Internal Initiative');

const onTypeChange = () => {
    form.store_id    = '';
    form.subject_type = '';
    form.subject_id  = '';
};

const onVendorChange = (val) => {
    form.subject_type = val ? 'App\\Models\\Vendor' : '';
    form.subject_id   = val || '';
};

const onDeptChange = (val) => {
    form.subject_type = val ? 'App\\Models\\Department' : '';
    form.subject_id   = val || '';
};

const typeConfig = {
    'Store Opening':       { color: 'bg-blue-500',   label: 'New store branch being set up and deployed.' },
    'IT Deployment':       { color: 'bg-violet-500', label: 'Software rollout, infrastructure upgrade, or system go-live.' },
    'Internal Initiative': { color: 'bg-teal-500',   label: 'Internal program or process improvement within a department.' },
    'Vendor Project':      { color: 'bg-amber-500',  label: 'Project managed with or on behalf of an external vendor/partner.' },
    'General':             { color: 'bg-gray-400',   label: 'Free-form project not tied to a specific category.' },
};

const monthOptions = [
    { value: 1, label: 'January' }, { value: 2, label: 'February' },
    { value: 3, label: 'March' },   { value: 4, label: 'April' },
    { value: 5, label: 'May' },     { value: 6, label: 'June' },
    { value: 7, label: 'July' },    { value: 8, label: 'August' },
    { value: 9, label: 'September' },{ value: 10, label: 'October' },
    { value: 11, label: 'November' },{ value: 12, label: 'December' },
];

const submit = () => {
    form.post(route('projects.store'));
};
</script>

<template>
    <Head title="Create Project" />

    <AppLayout content-class="w-full max-w-none px-2 sm:px-4 lg:px-6">
        <template #header>
            <div class="flex items-center">
                <Link :href="route('projects.index')" class="mr-4 text-gray-400 hover:text-gray-600 dark:text-gray-400">
                    <ChevronLeftIcon class="h-6 w-6" />
                </Link>
                Create New Project
            </div>
        </template>

        <div class="mx-auto max-w-4xl">
            <form @submit.prevent="submit" class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <div class="space-y-6 p-6">

                    <!-- Project Type Selector -->
                    <div>
                        <label class="mb-2 block text-sm font-semibold text-gray-700 dark:text-gray-300">Project Type</label>
                        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
                            <button
                                v-for="type in (projectTypes ?? [])"
                                :key="type"
                                type="button"
                                @click="form.project_type = type; onTypeChange()"
                                :class="[
                                    'flex flex-col items-start rounded-lg border-2 px-3 py-2.5 text-left text-sm transition-all',
                                    form.project_type === type
                                        ? 'border-blue-500 bg-blue-50 shadow-sm dark:border-blue-400 dark:bg-blue-900/20'
                                        : 'border-gray-200 bg-white hover:border-gray-300 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:hover:border-gray-500'
                                ]"
                            >
                                <span :class="['mb-1.5 inline-block h-2 w-2 rounded-full', typeConfig[type]?.color ?? 'bg-gray-400']" />
                                <span class="font-semibold leading-tight" :class="form.project_type === type ? 'text-blue-700 dark:text-blue-300' : 'text-gray-700 dark:text-gray-300'">
                                    {{ type }}
                                </span>
                                <span class="mt-0.5 text-[10px] leading-snug text-gray-400 dark:text-gray-500">
                                    {{ typeConfig[type]?.label }}
                                </span>
                            </button>
                        </div>
                        <div v-if="form.errors.project_type" class="mt-1 text-xs text-red-500">{{ form.errors.project_type }}</div>
                    </div>

                    <hr class="border-gray-100 dark:border-gray-700">

                    <!-- Basic Info -->
                    <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                        <div class="md:col-span-2">
                            <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Project Name</label>
                            <input
                                v-model="form.name"
                                type="text"
                                required
                                :placeholder="isStoreOpening ? 'e.g. NN NAIA Terminal 1 (NT1)' : 'Project name…'"
                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
                            />
                            <div v-if="form.errors.name" class="mt-1 text-xs text-red-500">{{ form.errors.name }}</div>
                        </div>

                        <!-- Store Branch (Store Opening only) -->
                        <div v-if="isStoreOpening">
                            <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Store Branch <span class="text-red-500">*</span></label>
                            <Autocomplete
                                v-model="form.store_id"
                                :options="sortedStores"
                                label-key="name"
                                value-key="id"
                                placeholder="Select a store"
                            />
                            <div v-if="form.errors.store_id" class="mt-1 text-xs text-red-500">{{ form.errors.store_id }}</div>
                        </div>

                        <!-- Vendor (Vendor Project only) -->
                        <div v-if="isVendorProject">
                            <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Vendor / Partner</label>
                            <Autocomplete
                                :model-value="form.subject_id || null"
                                :options="sortedVendors"
                                placeholder="Select a vendor"
                                @update:modelValue="onVendorChange"
                            />
                            <div v-if="form.errors.subject_id" class="mt-1 text-xs text-red-500">{{ form.errors.subject_id }}</div>
                        </div>

                        <!-- Department (Internal Initiative only) -->
                        <div v-if="isInitiative">
                            <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Owning Department</label>
                            <Autocomplete
                                :model-value="form.subject_id || null"
                                :options="sortedDepartments"
                                placeholder="Select a department"
                                @update:modelValue="onDeptChange"
                            />
                            <div v-if="form.errors.subject_id" class="mt-1 text-xs text-red-500">{{ form.errors.subject_id }}</div>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Initial Status</label>
                            <select
                                v-model="form.status"
                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 pl-2 pr-7"
                            >
                                <option value="Planning">Planning</option>
                                <option value="In Progress">In Progress</option>
                                <option value="Delayed">Delayed</option>
                            </select>
                            <div v-if="form.errors.status" class="mt-1 text-xs text-red-500">{{ form.errors.status }}</div>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Task Board Month</label>
                            <select
                                v-model.number="form.board_month"
                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 pl-2 pr-7"
                            >
                                <option v-for="month in monthOptions" :key="month.value" :value="month.value">{{ month.label }}</option>
                            </select>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Task Board Year</label>
                            <select
                                v-model.number="form.board_year"
                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 pl-2 pr-7"
                            >
                                <option v-for="year in boardYears" :key="year" :value="year">{{ year }}</option>
                            </select>
                        </div>
                    </div>

                    <hr class="border-gray-100 dark:border-gray-700">

                    <!-- Important Dates -->
                    <div>
                        <h3 class="mb-4 text-sm font-semibold text-gray-900 dark:text-gray-100">Important Dates</h3>
                        <div class="mb-6 rounded-lg border border-dashed border-blue-200 bg-blue-50/50 p-4 dark:border-blue-800 dark:bg-blue-900/10">
                            <label class="mb-1 block text-sm font-semibold text-blue-700 dark:text-blue-300">Day 1 Date</label>
                            <input v-model="form.day1_date" type="date" class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">When an activity template is applied, each milestone/activity/sub-task's Start and End Date is auto-scheduled from this date using the template's lead time (days).</p>
                            <div v-if="form.errors.day1_date" class="mt-1 text-xs text-red-500">{{ form.errors.day1_date }}</div>
                        </div>
                        <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Target Go-Live</label>
                                <input v-model="form.target_go_live" type="date" class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Turn-over (to TAS)</label>
                                <input v-model="form.turn_over_date" type="date" class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Training Date</label>
                                <input v-model="form.training_date" type="date" class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Testing Date</label>
                                <input v-model="form.testing_date" type="date" class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Mock Service Date</label>
                                <input v-model="form.mock_service_date" type="date" class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Turn-over to Franchisee</label>
                                <input v-model="form.turn_over_to_franchisee_date" type="date" class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                            </div>
                        </div>
                    </div>

                    <!-- Remarks -->
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Remarks</label>
                        <textarea
                            v-model="form.remarks"
                            rows="3"
                            class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
                        />
                    </div>

                    <!-- Link existing board (optional) -->
                    <div v-if="boardOptions.length > 0" class="rounded-lg border border-dashed border-indigo-200 bg-indigo-50/50 p-4 dark:border-indigo-800 dark:bg-indigo-900/10">
                        <label class="mb-1 block text-sm font-semibold text-indigo-700 dark:text-indigo-300">
                            Link Existing Task Board <span class="font-normal text-gray-400">(optional)</span>
                        </label>
                        <p class="mb-2 text-xs text-gray-500 dark:text-gray-400">Import cards from an existing manual board as project tasks when this project is created.</p>
                        <Autocomplete
                            :model-value="form.board_id"
                            :options="boardOptions"
                            placeholder="Select a board to link…"
                            @update:modelValue="form.board_id = $event || null"
                        />
                        <div v-if="form.errors.board_id" class="mt-1 text-xs text-red-500">{{ form.errors.board_id }}</div>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-3 border-t border-gray-100 bg-gray-50 px-6 py-4 dark:border-gray-700 dark:bg-gray-900/40">
                    <Link
                        :href="route('projects.index')"
                        class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700"
                    >
                        Cancel
                    </Link>
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 disabled:opacity-50"
                    >
                        {{ form.processing ? 'Saving…' : 'Create Project' }}
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
