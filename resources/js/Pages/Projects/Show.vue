<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ProjectGantt from '@/Components/ProjectTracker/ProjectGantt.vue';
import AssetsBoard from '@/Components/ProjectTracker/AssetsBoard.vue';
import Modal from '@/Components/Modal.vue';
import Autocomplete from '@/Components/Autocomplete.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { useConfirm } from '@/Composables/useConfirm.js';
import { usePermission } from '@/Composables/usePermission.js';
import { 
    ChevronLeftIcon,
    CalendarIcon,
    UserGroupIcon,
    ChartBarIcon,
    CpuChipIcon,
    ClipboardDocumentCheckIcon,
    InformationCircleIcon,
    PlusIcon,
    TrashIcon,
    XMarkIcon,
    PencilIcon
} from '@heroicons/vue/24/outline';

const props = defineProps({
    project: Object,
    users: Array,
    stores: Array,
    departmentOptions: Array,
    boardYears: Array,
    taskListTargets: Object,
    project_templates: Array
});

const formatDate = (dateString) => {
    if (!dateString) return 'TBD';
    return new Date(dateString).toLocaleDateString('en-US', {
        month: 'long',
        day: 'numeric',
        year: 'numeric'
    });
};

const formatDateForInput = (dateString) => {
    if (!dateString) return '';
    try {
        const date = new Date(dateString);
        if (isNaN(date.getTime())) return '';
        
        // Use local date components to avoid timezone shifting
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        
        return `${year}-${month}-${day}`;
    } catch (e) {
        return '';
    }
};

const { hasPermission } = usePermission();

// Initialize activeTab from URL query parameter if present
const urlParams = new URLSearchParams(window.location.search);
const initialTab = urlParams.get('tab');
const activeTab = ref(initialTab || 'overview');

const { confirm: confirmAction } = useConfirm();

const showManageTeamModal = ref(false);
const isOpeningTaskList = ref(false);
const now = new Date();
const teamForm = useForm({
    project_id: props.project.id,
    user_id: '',
    external_name: '',
    department: '',
    sub_unit: '',
    role_type: '',
    team_category: 'CASA Team',
});

const teamMembers = computed(() => {
    return props.project.team_members || props.project.teamMembers || [];
});

const userOptions = computed(() => {
    return props.users.map(u => ({
        id: u.id,
        label: `${u.name}${u.department || u.sub_unit ? ` - ${u.department || '-'} / ${u.sub_unit || '-'}` : ''}`
    }));
});

const showEditProjectModal = ref(false);
const editForm = useForm({
    name: props.project.name,
    store_id: props.project.store_id,
    status: props.project.status,
    turn_over_date: formatDateForInput(props.project.turn_over_date),
    training_date: formatDateForInput(props.project.training_date),
    testing_date: formatDateForInput(props.project.testing_date),
    mock_service_date: formatDateForInput(props.project.mock_service_date),
    turn_over_to_franchisee_date: formatDateForInput(props.project.turn_over_to_franchisee_date),
    target_go_live: formatDateForInput(props.project.target_go_live),
    board_month: props.project.board_month || now.getMonth() + 1,
    board_year: props.project.board_year || now.getFullYear(),
    remarks: props.project.remarks,
});

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

const missingTaskListTargets = computed(() => props.taskListTargets?.missing || []);

const confirmAutoCreateMonthlyBoards = async (extraTarget = null) => {
    const missing = [...missingTaskListTargets.value];

    if (extraTarget?.department && extraTarget?.sub_unit) {
        const exists = (props.taskListTargets?.targets || []).some((target) => {
            return target.department === extraTarget.department && target.sub_unit === extraTarget.sub_unit && target.exists;
        });

        if (!exists) {
            missing.push({
                title: `${extraTarget.department} ${extraTarget.sub_unit}`,
            });
        }
    }

    if (missing.length === 0) {
        return true;
    }

    return await confirmAction({
        title: 'Auto-create Monthly Board',
        message: `This will automatically create ${missing.length} monthly task board${missing.length === 1 ? '' : 's'} for this project sync.`,
        confirmLabel: 'Create and Sync',
        variant: 'primary',
    });
};

const selectedTeamUser = computed(() => {
    return props.users.find((user) => Number(user.id) === Number(teamForm.user_id)) || null;
});

const selectedDepartment = computed(() => {
    return (props.departmentOptions || []).find((department) => department.name === teamForm.department) || null;
});

const openEditModal = () => {
    editForm.name = props.project.name;
    editForm.store_id = props.project.store_id;
    editForm.status = props.project.status;
    editForm.turn_over_date = formatDateForInput(props.project.turn_over_date);
    editForm.training_date = formatDateForInput(props.project.training_date);
    editForm.testing_date = formatDateForInput(props.project.testing_date);
    editForm.mock_service_date = formatDateForInput(props.project.mock_service_date);
    editForm.turn_over_to_franchisee_date = formatDateForInput(props.project.turn_over_to_franchisee_date);
    editForm.target_go_live = formatDateForInput(props.project.target_go_live);
    editForm.board_month = props.project.board_month || now.getMonth() + 1;
    editForm.board_year = props.project.board_year || now.getFullYear();
    editForm.remarks = props.project.remarks;
    showEditProjectModal.value = true;
};

const updateProject = async () => {
    const ok = await confirmAutoCreateMonthlyBoards();
    if (!ok) return;

    editForm
        .transform((data) => ({
            ...data,
            auto_create_monthly_boards: true,
        }))
        .put(route('projects.update', props.project.id), {
        onSuccess: () => {
            showEditProjectModal.value = false;
        },
        preserveScroll: true
    });
};

const syncTeamTargetFromUser = () => {
    if (!selectedTeamUser.value) return;

    teamForm.department = selectedTeamUser.value.department || teamForm.department;
    teamForm.sub_unit = selectedTeamUser.value.sub_unit || teamForm.sub_unit;
    teamForm.external_name = '';
};

const addTeamMember = async () => {
    teamForm.clearErrors();
    
    if (!teamForm.user_id && !teamForm.external_name) {
        teamForm.setError({
            user_id: 'Please select a system user or enter an external name.',
            external_name: 'Please select a system user or enter an external name.'
        });
        return;
    }

    if (!teamForm.department || !teamForm.sub_unit) {
        teamForm.setError({
            department: 'Select a department.',
            sub_unit: 'Select a sub-unit.',
        });
        return;
    }

    const ok = await confirmAutoCreateMonthlyBoards({
        department: teamForm.department,
        sub_unit: teamForm.sub_unit,
    });
    if (!ok) return;

    teamForm
        .transform((data) => ({
            ...data,
            auto_create_monthly_boards: true,
        }))
        .post(route('projects-team-members.store'), {
        onSuccess: () => {
            teamForm.reset('user_id', 'external_name', 'department', 'sub_unit', 'role_type');
        },
        preserveScroll: true
    });
};

const removeTeamMember = async (id) => {
    const ok = await confirmAction({
        title: 'Remove Team Member',
        message: 'Are you sure you want to remove this member from the project team?'
    });

    if (ok) {
        useForm({}).delete(route('projects-team-members.destroy', id), {
            preserveScroll: true
        });
    }
};

const openProjectTaskList = () => {
    if (isOpeningTaskList.value) return;

    confirmAutoCreateMonthlyBoards().then((ok) => {
        if (!ok) return;

        router.post(route('projects.task-list', props.project.id), {
            auto_create_monthly_boards: true,
        }, {
        preserveScroll: true,
        onStart: () => {
            isOpeningTaskList.value = true;
        },
        onFinish: () => {
            isOpeningTaskList.value = false;
        },
        });
    });
};

const projectProgress = computed(() => {
    const tasks = props.project.tasks || [];
    if (tasks.length === 0) return 0;
    const totalProgress = tasks.reduce((sum, task) => sum + (task.progress || 0), 0);
    return Math.round(totalProgress / tasks.length);
});

const getStatusColor = (status) => {
    switch (status.toLowerCase()) {
        case 'completed': return 'bg-emerald-50 text-emerald-700 border-emerald-100';
        case 'in progress': return 'bg-blue-50 text-blue-700 border-blue-100';
        case 'delayed': return 'bg-red-50 text-red-700 border-red-100';
        default: return 'bg-gray-50 text-gray-700 border-gray-100';
    }
};
</script>

<template>
    <Head :title="project.name" />

    <AppLayout>
        <template #header>
            Project Detail
        </template>

        <!-- Edit Project Modal -->
        <Modal :show="showEditProjectModal" @close="showEditProjectModal = false" maxWidth="4xl">
            <div class="p-6">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-xl font-bold text-gray-900">Edit Project Details</h2>
                    <button @click="showEditProjectModal = false" class="text-gray-400 hover:text-gray-500">
                        <XMarkIcon class="w-6 h-6" />
                    </button>
                </div>

                <form @submit.prevent="updateProject" class="space-y-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- Basic Info -->
                        <div class="space-y-4">
                            <div>
                                <InputLabel for="edit_name" value="Project Name" />
                                <TextInput 
                                    id="edit_name" 
                                    type="text" 
                                    v-model="editForm.name" 
                                    class="w-full" 
                                    required 
                                />
                                <InputError :message="editForm.errors.name" />
                            </div>

                            <div>
                                <InputLabel for="edit_store_id" value="Store Branch" />
                                <Autocomplete
                                    v-model="editForm.store_id"
                                    :options="stores"
                                    label-key="name"
                                    value-key="id"
                                    placeholder="Select a store"
                                />
                                <InputError :message="editForm.errors.store_id" />
                            </div>

                            <div>
                                <InputLabel for="edit_status" value="Status" />
                                <select 
                                    id="edit_status"
                                    v-model="editForm.status"
                                    class="w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm"
                                    required
                                >
                                    <option value="Pending">Pending</option>
                                    <option value="In Progress">In Progress</option>
                                    <option value="Completed">Completed</option>
                                    <option value="Delayed">Delayed</option>
                                    <option value="Cancelled">Cancelled</option>
                                </select>
                                <InputError :message="editForm.errors.status" />
                            </div>
                        </div>

                        <!-- Dates -->
                        <div class="space-y-4">
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <InputLabel for="edit_target_go_live" value="Target Go-Live" />
                                    <TextInput id="edit_target_go_live" type="date" v-model="editForm.target_go_live" class="w-full" />
                                    <InputError :message="editForm.errors.target_go_live" />
                                </div>
                                <div>
                                    <InputLabel for="edit_turn_over_date" value="Store Turn-over" />
                                    <TextInput id="edit_turn_over_date" type="date" v-model="editForm.turn_over_date" class="w-full" />
                                    <InputError :message="editForm.errors.turn_over_date" />
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <InputLabel for="edit_training_date" value="Training Date" />
                                    <TextInput id="edit_training_date" type="date" v-model="editForm.training_date" class="w-full" />
                                    <InputError :message="editForm.errors.training_date" />
                                </div>
                                <div>
                                    <InputLabel for="edit_testing_date" value="Testing Date" />
                                    <TextInput id="edit_testing_date" type="date" v-model="editForm.testing_date" class="w-full" />
                                    <InputError :message="editForm.errors.testing_date" />
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <InputLabel for="edit_mock_service_date" value="Mock Service" />
                                    <TextInput id="edit_mock_service_date" type="date" v-model="editForm.mock_service_date" class="w-full" />
                                    <InputError :message="editForm.errors.mock_service_date" />
                                </div>
                                <div>
                                    <InputLabel for="edit_turn_over_to_franchisee_date" value="Franchisee T.O." />
                                    <TextInput id="edit_turn_over_to_franchisee_date" type="date" v-model="editForm.turn_over_to_franchisee_date" class="w-full" />
                                    <InputError :message="editForm.errors.turn_over_to_franchisee_date" />
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <InputLabel for="edit_board_month" value="Task Board Month" />
                                    <select
                                        id="edit_board_month"
                                        v-model.number="editForm.board_month"
                                        class="w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm"
                                    >
                                        <option v-for="month in monthOptions" :key="month.value" :value="month.value">{{ month.label }}</option>
                                    </select>
                                    <InputError :message="editForm.errors.board_month" />
                                </div>
                                <div>
                                    <InputLabel for="edit_board_year" value="Task Board Year" />
                                    <select
                                        id="edit_board_year"
                                        v-model.number="editForm.board_year"
                                        class="w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm"
                                    >
                                        <option v-for="year in boardYears" :key="year" :value="year">{{ year }}</option>
                                    </select>
                                    <InputError :message="editForm.errors.board_year" />
                                </div>
                            </div>
                        </div>
                    </div>

                    <div>
                        <InputLabel for="edit_remarks" value="Remarks" />
                        <textarea 
                            id="edit_remarks" 
                            v-model="editForm.remarks" 
                            class="w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm min-h-[100px]"
                            placeholder="Add project remarks or updates..."
                        ></textarea>
                        <InputError :message="editForm.errors.remarks" />
                    </div>

                    <div class="flex justify-end gap-3 pt-6 border-t border-gray-100">
                        <SecondaryButton @click="showEditProjectModal = false" :disabled="editForm.processing">
                            Cancel
                        </SecondaryButton>
                        <PrimaryButton :disabled="editForm.processing">
                            <span v-if="editForm.processing">Saving...</span>
                            <span v-else>Save Changes</span>
                        </PrimaryButton>
                    </div>
                </form>
            </div>
        </Modal>

        <!-- Manage Team Modal -->
        <Modal :show="showManageTeamModal" @close="showManageTeamModal = false" maxWidth="4xl">
            <div class="p-6">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-xl font-bold text-gray-900">Manage Project Team</h2>
                    <button @click="showManageTeamModal = false" class="text-gray-400 hover:text-gray-500">
                        <XMarkIcon class="w-6 h-6" />
                    </button>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                    <!-- Current Team List -->
                    <div>
                        <h3 class="text-sm font-bold text-gray-700 uppercase tracking-wider mb-4">Current Members</h3>
                        <div class="space-y-3 max-h-[400px] overflow-y-auto pr-2">
                            <div v-for="member in teamMembers" :key="member.id" class="flex items-center justify-between p-3 bg-gray-50 rounded-xl border border-gray-100 group">
                                <div class="flex items-center min-w-0">
                                    <div class="h-10 w-10 rounded-full bg-white shadow-sm border border-gray-200 flex items-center justify-center text-sm font-bold text-gray-600 flex-shrink-0">
                                        {{ (member.user?.name || member.external_name || 'U').charAt(0) }}
                                    </div>
                                    <div class="ml-3 min-w-0">
                                        <p class="text-sm font-bold text-gray-900 truncate">{{ member.user?.name || member.external_name }}</p>
                                        <p class="text-[10px] text-gray-500 uppercase font-black">{{ member.role_type }}</p>
                                        <p class="text-[10px] text-blue-600 font-black">{{ member.department || '-' }} / {{ member.sub_unit || '-' }}</p>
                                    </div>
                                </div>
                                <button @click="removeTeamMember(member.id)" class="p-1.5 text-gray-400 hover:text-red-600 transition-colors">
                                    <TrashIcon class="w-5 h-5" />
                                </button>
                            </div>
                            <div v-if="!teamMembers.length" class="text-center py-8 text-gray-400 text-sm italic">
                                No team members assigned yet.
                            </div>
                        </div>
                    </div>

                    <!-- Add New Member Form -->
                    <div class="bg-gray-50/50 p-6 rounded-2xl border border-gray-100">
                        <h3 class="text-sm font-bold text-gray-700 uppercase tracking-wider mb-4">Add New Member</h3>
                        <form @submit.prevent="addTeamMember" class="space-y-4">
                            <div>
                                <InputLabel for="user_id" value="System User" />
                                <Autocomplete
                                    v-model="teamForm.user_id"
                                    :options="userOptions"
                                    label-key="label"
                                    value-key="id"
                                    placeholder="Select a user..."
                                    @update:modelValue="syncTeamTargetFromUser"
                                />
                                <InputError :message="teamForm.errors.user_id" />
                            </div>

                            <div class="relative py-2">
                                <div class="absolute inset-0 flex items-center" aria-hidden="true">
                                    <div class="w-full border-t border-gray-200"></div>
                                </div>
                                <div class="relative flex justify-center text-[10px] uppercase font-black">
                                    <span class="px-2 bg-gray-50 text-gray-400">Or External Name</span>
                                </div>
                            </div>

                            <div>
                                <InputLabel for="external_name" value="External/Manual Name" />
                                <TextInput 
                                    id="external_name" 
                                    type="text" 
                                    v-model="teamForm.external_name" 
                                    class="w-full"
                                    placeholder="e.g. Franchisee Name"
                                    @input="teamForm.user_id = ''"
                                />
                                <InputError :message="teamForm.errors.external_name" />
                            </div>

                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <InputLabel for="team_department" value="Department" />
                                    <select
                                        id="team_department"
                                        v-model="teamForm.department"
                                        class="w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm"
                                        required
                                        @change="teamForm.sub_unit = ''"
                                    >
                                        <option value="">Select department...</option>
                                        <option v-for="department in departmentOptions" :key="department.name" :value="department.name">
                                            {{ department.name }}
                                        </option>
                                    </select>
                                    <InputError :message="teamForm.errors.department" />
                                </div>
                                <div>
                                    <InputLabel for="team_sub_unit" value="Sub-Unit" />
                                    <select
                                        id="team_sub_unit"
                                        v-model="teamForm.sub_unit"
                                        class="w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm"
                                        required
                                    >
                                        <option value="">Select sub-unit...</option>
                                        <option v-for="subUnit in (selectedDepartment?.sub_units || [])" :key="subUnit" :value="subUnit">
                                            {{ subUnit }}
                                        </option>
                                    </select>
                                    <InputError :message="teamForm.errors.sub_unit" />
                                </div>
                            </div>

                            <div>
                                <InputLabel for="role_type" value="Role in Project" />
                                <select 
                                    v-model="teamForm.role_type" 
                                    id="role_type"
                                    class="w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm"
                                    required
                                >
                                    <option value="">Select role...</option>
                                    <option value="Lead Partner">Lead Partner</option>
                                    <option value="Leader">Leader</option>
                                    <option value="SO Rep">SO Rep</option>
                                    <option value="SMITS">SMITS</option>
                                    <option value="Marketing">Marketing</option>
                                    <option value="Training">Training</option>
                                    <option value="SCM">SCM</option>
                                    <option value="Contractor">Contractor</option>
                                    <option value="Franchisee">Franchisee</option>
                                    <option value="Other">Other</option>
                                </select>
                                <InputError :message="teamForm.errors.role_type" />
                            </div>

                            <div>
                                <InputLabel for="team_category" value="Team Category" />
                                <div class="flex gap-4 mt-2">
                                    <label class="flex items-center">
                                        <input type="radio" v-model="teamForm.team_category" value="CASA Team" class="text-blue-600 focus:ring-blue-500" />
                                        <span class="ml-2 text-sm text-gray-700">CASA Team</span>
                                    </label>
                                    <label class="flex items-center">
                                        <input type="radio" v-model="teamForm.team_category" value="Extended Team" class="text-blue-600 focus:ring-blue-500" />
                                        <span class="ml-2 text-sm text-gray-700">Extended</span>
                                    </label>
                                </div>
                                <InputError :message="teamForm.errors.team_category" />
                            </div>

                            <div class="pt-4">
                                <PrimaryButton class="w-full justify-center py-3 text-sm font-bold bg-indigo-600 hover:bg-indigo-700 text-white shadow-indigo-200 shadow-lg" :disabled="teamForm.processing">
                                    <PlusIcon class="w-4 h-4 mr-2" />
                                    Save & Add to Team
                                </PrimaryButton>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Modal Footer -->
                <div class="mt-8 pt-6 border-t border-gray-100 flex justify-end">
                    <SecondaryButton @click="showManageTeamModal = false" class="px-8">
                        Done Managing Team
                    </SecondaryButton>
                </div>
            </div>
        </Modal>

        <div class="space-y-8">
            <!-- Project Header Info -->
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="bg-gradient-to-r from-blue-600 to-indigo-700 h-2"></div>
                <div class="p-8 flex flex-col md:flex-row md:items-center justify-between gap-6">
                    <div class="flex items-start">
                        <Link :href="route('projects.index')" class="mr-6 p-3 rounded-xl bg-gray-50 text-gray-400 hover:text-blue-600 hover:bg-blue-50 transition-all border border-gray-100">
                            <ChevronLeftIcon class="h-6 w-6" />
                        </Link>
                        <div>
                            <div class="flex items-center gap-3 mb-2">
                                <span class="px-3 py-1 bg-blue-50 text-blue-700 text-[10px] font-black uppercase tracking-widest rounded-full border border-blue-100">
                                    {{ project.store?.name || 'Project' }}
                                </span>
                                <span :class="['px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-widest border', getStatusColor(project.status)]">
                                    {{ project.status }}
                                </span>
                            </div>
                            <div class="flex items-center gap-4">
                                <h1 class="text-3xl font-black text-gray-900 tracking-tight">{{ project.name }}</h1>
                                <button 
                                    v-if="hasPermission('projects.edit')" 
                                    @click="openEditModal"
                                    class="p-2 rounded-lg bg-gray-50 text-gray-400 hover:text-indigo-600 hover:bg-indigo-50 transition-all border border-gray-100"
                                    title="Edit Project Details"
                                >
                                    <PencilIcon class="h-5 w-5" />
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center gap-8 px-8 border-l border-gray-100 hidden lg:flex">
                        <div class="text-center">
                            <p class="text-[10px] text-gray-400 font-black uppercase tracking-widest mb-1">Overall Completion</p>
                            <div class="flex items-center justify-center text-emerald-600">
                                <ChartBarIcon class="w-5 h-5 mr-2 opacity-50" />
                                <p class="text-xl font-black">{{ projectProgress }}%</p>
                            </div>
                        </div>
                        <div class="text-center border-l border-gray-50 pl-8">
                            <p class="text-[10px] text-gray-400 font-black uppercase tracking-widest mb-1">Target Go-Live</p>
                            <div class="flex items-center justify-center text-blue-600">
                                <CalendarIcon class="w-5 h-5 mr-2 opacity-50" />
                                <p class="text-xl font-black">{{ formatDate(project.target_go_live) }}</p>
                            </div>
                        </div>
                    </div>
                    <button
                        type="button"
                        :disabled="isOpeningTaskList"
                        @click="openProjectTaskList"
                        class="inline-flex items-center justify-center gap-2 rounded-xl bg-blue-600 px-4 py-3 text-sm font-black text-white shadow-sm transition-colors hover:bg-blue-700 disabled:opacity-50"
                    >
                        <ClipboardDocumentCheckIcon class="h-5 w-5" />
                        {{ project.task_board ? 'Open Task Board' : 'Create Task Board' }}
                    </button>
                </div>
            </div>

            <!-- Modern Navigation Tabs -->
            <div class="flex items-center justify-between">
                <nav class="flex p-1.5 bg-gray-100 rounded-2xl w-fit">
                    <button 
                        @click="activeTab = 'overview'"
                        :class="[activeTab === 'overview' ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-500 hover:text-gray-700', 'px-6 py-2.5 rounded-xl text-sm font-bold flex items-center transition-all']"
                    >
                        <InformationCircleIcon class="w-4 h-4 mr-2" />
                        Overview
                    </button>
                    <button 
                        @click="activeTab = 'gantt'"
                        :class="[activeTab === 'gantt' ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-500 hover:text-gray-700', 'px-6 py-2.5 rounded-xl text-sm font-bold flex items-center transition-all']"
                    >
                        <ChartBarIcon class="w-4 h-4 mr-2" />
                        Gantt Chart
                    </button>
                    <button 
                        @click="activeTab = 'assets'"
                        :class="[activeTab === 'assets' ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-500 hover:text-gray-700', 'px-6 py-2.5 rounded-xl text-sm font-bold flex items-center transition-all']"
                    >
                        <CpuChipIcon class="w-4 h-4 mr-2" />
                        IT Assets Board
                    </button>
                </nav>
            </div>

            <!-- Tab Content -->
            <div class="mt-6">
                <!-- Overview Tab -->
                <div v-if="activeTab === 'overview'" class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <!-- Dates Card -->
                    <div class="lg:col-span-2 space-y-6">
                        <div class="bg-white shadow rounded-lg p-6 border border-gray-200">
                            <h3 class="text-lg font-bold text-gray-900 mb-4 flex items-center">
                                <CalendarIcon class="w-5 h-5 mr-2 text-blue-600" />
                                Project Timeline Milestones
                            </h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-y-6 gap-x-12">
                                <div class="flex flex-col">
                                    <span class="text-sm text-gray-500">Target Go-Live</span>
                                    <span class="text-lg font-bold text-blue-700">{{ formatDate(project.target_go_live) }}</span>
                                </div>
                                <div class="flex flex-col">
                                    <span class="text-sm text-gray-500">Store Turn-over</span>
                                    <span class="text-base font-semibold">{{ formatDate(project.turn_over_date) }}</span>
                                </div>
                                <div class="flex flex-col">
                                    <span class="text-sm text-gray-500">Training Dates</span>
                                    <span class="text-base font-semibold">{{ formatDate(project.training_date) }}</span>
                                </div>
                                <div class="flex flex-col">
                                    <span class="text-sm text-gray-500">Testing Date</span>
                                    <span class="text-base font-semibold">{{ formatDate(project.testing_date) }}</span>
                                </div>
                                <div class="flex flex-col">
                                    <span class="text-sm text-gray-500">Mock Service</span>
                                    <span class="text-base font-semibold">{{ formatDate(project.mock_service_date) }}</span>
                                </div>
                                <div class="flex flex-col">
                                    <span class="text-sm text-gray-500">Turn-over to Franchisee</span>
                                    <span class="text-base font-semibold">{{ formatDate(project.turn_over_to_franchisee_date) }}</span>
                                </div>
                            </div>
                        </div>

                        <div v-if="project.remarks" class="bg-white shadow rounded-lg p-6 border border-gray-200">
                            <h3 class="text-lg font-bold text-gray-900 mb-2">Remarks</h3>
                            <p class="text-gray-700 whitespace-pre-line">{{ project.remarks }}</p>
                        </div>
                    </div>

                    <!-- Team Card -->
                    <div class="space-y-6">
                        <div class="bg-white shadow rounded-lg p-6 border border-gray-200">
                            <h3 class="text-lg font-bold text-gray-900 mb-4 flex items-center">
                                <UserGroupIcon class="w-5 h-5 mr-2 text-blue-600" />
                                Project Team
                            </h3>
                            <div class="space-y-4">
                                <div v-for="member in teamMembers" :key="member.id" class="flex items-center justify-between">
                                    <div class="flex items-center">
                                        <div class="h-8 w-8 rounded-full bg-gray-200 flex items-center justify-center text-xs font-bold text-gray-600">
                                            {{ (member.user?.name || member.external_name || 'U').charAt(0) }}
                                        </div>
                                        <div class="ml-3">
                                            <p class="text-sm font-medium text-gray-900">{{ member.user?.name || member.external_name }}</p>
                                            <p class="text-xs text-gray-500">{{ member.role_type }}</p>
                                            <p class="text-[10px] font-bold text-blue-600">{{ member.department || '-' }} / {{ member.sub_unit || '-' }}</p>
                                        </div>
                                    </div>
                                    <span class="text-[10px] uppercase font-bold text-gray-400 bg-gray-50 px-1.5 py-0.5 rounded">
                                        {{ member.team_category }}
                                    </span>
                                </div>
                                <button @click.stop="showManageTeamModal = true" class="w-full mt-4 py-2 border-2 border-dashed border-gray-300 rounded-md text-sm text-gray-500 hover:border-blue-400 hover:text-blue-500 transition-colors">
                                    Manage Team
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Gantt Chart Tab -->
                <div v-if="activeTab === 'gantt'">
                    <ProjectGantt
                        :project="project"
                        :users="users"
                        :projectTemplates="project_templates"
                        :taskListTargets="taskListTargets"
                    />
                </div>

                <!-- Assets Board Tab -->
                <div v-if="activeTab === 'assets'">
                    <AssetsBoard :project="project" />
                </div>
            </div>
        </div>
    </AppLayout>
</template>

