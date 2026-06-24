<script setup>
import { ref, computed, watch } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ProjectGantt from '@/Components/ProjectTracker/ProjectGantt.vue';
import AssetsBoard from '@/Components/ProjectTracker/AssetsBoard.vue';
import Modal from '@/Components/Modal.vue';
import Autocomplete from '@/Components/Autocomplete.vue';
import HierarchySelector from '@/Components/HierarchySelector.vue';
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
    LinkIcon,
    PlusIcon,
    TrashIcon,
    XMarkIcon,
    PencilIcon
} from '@heroicons/vue/24/outline';

const props = defineProps({
    project: Object,
    projectTypes: { type: Array, default: () => [] },
    users: Array,
    stores: Array,
    vendors: { type: Array, default: () => [] },
    departments: { type: Array, default: () => [] },
    departmentOptions: Array,
    hierarchicalDepartments: Array,
    boardYears: Array,
    availableBoards: { type: Array, default: () => [] },
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

const page = usePage();
const currentUser = computed(() => page.props.auth?.user || {});

const defaultDepartment = currentUser.value.department || '';
const defaultSubUnit = currentUser.value.org_path || '';

const teamForm = useForm({
    project_id: props.project.id,
    user_id: '',
    external_name: '',
    department: defaultDepartment,
    sub_unit: defaultSubUnit,
    role_type: '',
    team_category: 'CASA Team',
});

const teamMembers = computed(() => {
    return props.project.team_members || props.project.teamMembers || [];
});

const userOptions = computed(() => {
    const existingUserIds = teamMembers.value.map(m => Number(m.user_id)).filter(id => id);
    return props.users
        .filter(u => !existingUserIds.includes(Number(u.id)))
        .map(u => ({
            id: u.id,
            label: `${u.name}${u.department || u.sub_unit ? ` - ${u.department || '-'} / ${u.sub_unit || '-'}` : ''}`
        }));
});

const showEditProjectModal = ref(false);
const showAttachBoardModal = ref(false);
const attachBoardId = ref(null);
const attachBoardSearch = ref('');
const isAttachingBoard = ref(false);

const filteredAvailableBoards = computed(() => {
    const q = attachBoardSearch.value.toLowerCase();
    return (props.availableBoards ?? []).filter(b => !q || b.title.toLowerCase().includes(q));
});

const confirmAttachBoard = () => {
    if (!attachBoardId.value || isAttachingBoard.value) return;
    isAttachingBoard.value = true;
    router.post(
        route('task-boards.link-to-project', attachBoardId.value),
        { project_id: props.project.id },
        {
            onSuccess: () => { showAttachBoardModal.value = false; },
            onFinish: () => { isAttachingBoard.value = false; },
        }
    );
};

const editForm = useForm({
    project_type: props.project.project_type || 'Store Opening',
    name: props.project.name,
    store_id: props.project.store_id,
    subject_type: props.project.subject_type || '',
    subject_id: props.project.subject_id || '',
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

const findNodeIdByStrings = (departmentName, orgPath) => {
    if (!departmentName) return '';
    const dept = (props.hierarchicalDepartments || []).find(d => d.name === departmentName);
    if (!dept) return '';
    if (!orgPath) return `dept-${dept.id}`;
    
    const targetPath = orgPath.split(' > ').map(p => p.trim());
    let currentNodes = dept.nodes || [];
    let lastFoundNodeId = `dept-${dept.id}`;
    
    for (const pathPart of targetPath) {
        const node = currentNodes.find(n => n.name === pathPart);
        if (!node) break;
        lastFoundNodeId = node.id;
        currentNodes = node.children || [];
    }
    return lastFoundNodeId;
};

const getNodeDetails = (nodeId) => {
    if (!nodeId) return null;
    if (typeof nodeId === 'string' && nodeId.startsWith('dept-')) {
        const deptId = Number(nodeId.replace('dept-', ''));
        const dept = (props.hierarchicalDepartments || []).find(d => Number(d.id) === deptId);
        if (dept) return { department: dept.name, sub_unit: '' };
    }
    
    const findNodeAndPath = (nodes, targetId, currentPath = []) => {
        for (const node of (nodes || [])) {
            if (Number(node.id) === Number(targetId)) {
                return { department_id: node.department_id, path: [...currentPath, node.name] };
            }
            if (node.children) {
                const found = findNodeAndPath(node.children, targetId, [...currentPath, node.name]);
                if (found) return found;
            }
        }
        return null;
    };
    
    for (const dept of (props.hierarchicalDepartments || [])) {
        const found = findNodeAndPath(dept.nodes, nodeId);
        if (found) return { department: dept.name, sub_unit: found.path.join(' > ') };
    }
    return null;
};

const selectedDepartmentNode = ref(findNodeIdByStrings(defaultDepartment, defaultSubUnit));

watch(selectedDepartmentNode, (newVal) => {
    if (!newVal) {
        teamForm.department = '';
        teamForm.sub_unit = '';
        return;
    }
    const details = getNodeDetails(newVal);
    if (details) {
        teamForm.department = details.department;
        teamForm.sub_unit = details.sub_unit;
    }
});

const hierarchicalDepartmentOptions = computed(() => {
    return (props.hierarchicalDepartments || []).map(dept => ({
        ...dept,
        id: `dept-${dept.id}`,
        children: dept.nodes || [],
    }));
});

const selectedTeamUser = computed(() => {
    return props.users.find((user) => Number(user.id) === Number(teamForm.user_id)) || null;
});

const selectedDepartment = computed(() => {
    return (props.departmentOptions || []).find((department) => department.name === teamForm.department) || null;
});

const openEditModal = () => {
    editForm.project_type = props.project.project_type || 'Store Opening';
    editForm.name = props.project.name;
    editForm.store_id = props.project.store_id;
    editForm.subject_type = props.project.subject_type || '';
    editForm.subject_id = props.project.subject_id || '';
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
    teamForm.sub_unit = selectedTeamUser.value.org_path || selectedTeamUser.value.sub_unit || teamForm.sub_unit;
    teamForm.external_name = '';

    selectedDepartmentNode.value = findNodeIdByStrings(teamForm.department, teamForm.sub_unit);
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

    const isDuplicate = teamMembers.value.some(member => {
        if (teamForm.user_id && String(member.user_id) === String(teamForm.user_id)) return true;
        if (!teamForm.user_id && teamForm.external_name && member.external_name?.toLowerCase() === teamForm.external_name.toLowerCase()) return true;
        return false;
    });

    if (isDuplicate) {
        teamForm.setError({
            user_id: 'This member is already in the project team.',
            external_name: 'This member is already in the project team.'
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
            selectedDepartmentNode.value = findNodeIdByStrings(defaultDepartment, defaultSubUnit);
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

        router.post(route('projects.task-board', props.project.id), {
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

    <AppLayout content-class="w-full max-w-none px-2 sm:px-4 lg:px-6">
        <template #header>
            Project Detail
        </template>

        <!-- Edit Project Modal -->
        <Modal :show="showEditProjectModal" @close="showEditProjectModal = false" maxWidth="4xl">
            <div class="p-6">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-xl font-bold text-gray-900 dark:text-gray-100">Edit Project Details</h2>
                    <button @click="showEditProjectModal = false" class="text-gray-400 hover:text-gray-500 dark:text-gray-400">
                        <XMarkIcon class="w-6 h-6" />
                    </button>
                </div>

                <form @submit.prevent="updateProject" class="space-y-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- Basic Info -->
                        <div class="space-y-4">
                            <div>
                                <InputLabel for="edit_project_type" value="Project Type" />
                                <select
                                    id="edit_project_type"
                                    v-model="editForm.project_type"
                                    class="w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm dark:border-gray-600 pl-2 pr-7"
                                >
                                    <option v-for="type in (projectTypes ?? [])" :key="type" :value="type">{{ type }}</option>
                                </select>
                                <InputError :message="editForm.errors.project_type" />
                            </div>

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

                            <div v-if="editForm.project_type === 'Store Opening'">
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

                            <div v-if="editForm.project_type === 'Vendor Project'">
                                <InputLabel value="Vendor / Partner" />
                                <Autocomplete
                                    :model-value="editForm.subject_id || null"
                                    :options="(vendors ?? []).map(v => ({ label: v.name, value: v.id }))"
                                    placeholder="Select a vendor"
                                    @update:modelValue="(val) => { editForm.subject_type = val ? 'App\\Models\\Vendor' : ''; editForm.subject_id = val || ''; }"
                                />
                                <InputError :message="editForm.errors.subject_id" />
                            </div>

                            <div v-if="editForm.project_type === 'Internal Initiative'">
                                <InputLabel value="Owning Department" />
                                <Autocomplete
                                    :model-value="editForm.subject_id || null"
                                    :options="(departments ?? []).map(d => ({ label: d.name, value: d.id }))"
                                    placeholder="Select a department"
                                    @update:modelValue="(val) => { editForm.subject_type = val ? 'App\\Models\\Department' : ''; editForm.subject_id = val || ''; }"
                                />
                                <InputError :message="editForm.errors.subject_id" />
                            </div>

                            <div>
                                <InputLabel for="edit_status" value="Status" />
                                <select 
                                    id="edit_status"
                                    v-model="editForm.status"
                                    class="w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm dark:border-gray-600"
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
                                        class="w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm dark:border-gray-600"
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
                                        class="w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm dark:border-gray-600"
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
                            class="w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm min-h-[100px] dark:border-gray-600"
                            placeholder="Add project remarks or updates..."
                        ></textarea>
                        <InputError :message="editForm.errors.remarks" />
                    </div>

                    <div class="flex justify-end gap-3 pt-6 border-t border-gray-100 dark:border-gray-700">
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
                    <h2 class="text-xl font-bold text-gray-900 dark:text-gray-100">Manage Project Team</h2>
                    <button @click="showManageTeamModal = false" class="text-gray-400 hover:text-gray-500 dark:text-gray-400">
                        <XMarkIcon class="w-6 h-6" />
                    </button>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                    <!-- Current Team List -->
                    <div>
                        <h3 class="text-sm font-bold text-gray-700 uppercase tracking-wider mb-4 dark:text-gray-300">Current Members</h3>
                        <div class="space-y-3 max-h-[400px] overflow-y-auto pr-2">
                            <div v-for="member in teamMembers" :key="member.id" class="flex items-center justify-between p-3 bg-gray-50 rounded-xl border border-gray-100 group dark:bg-gray-900/50 dark:border-gray-700">
                                <div class="flex items-center min-w-0">
                                    <div class="h-10 w-10 rounded-full bg-white shadow-sm border border-gray-200 flex items-center justify-center text-sm font-bold text-gray-600 flex-shrink-0 dark:bg-gray-800 dark:text-gray-300 dark:border-gray-700">
                                        {{ (member.user?.name || member.external_name || 'U').charAt(0) }}
                                    </div>
                                    <div class="ml-3 min-w-0">
                                        <p class="text-sm font-bold text-gray-900 truncate dark:text-gray-100">{{ member.user?.name || member.external_name }}</p>
                                        <p class="text-[10px] text-gray-500 uppercase font-black dark:text-gray-300">{{ member.role_type }}</p>
                                        <p class="text-[10px] text-blue-600 font-black">{{ member.department || '-' }} / {{ member.sub_unit || '-' }}</p>
                                    </div>
                                </div>
                                <button @click="removeTeamMember(member.id)" class="p-1.5 text-gray-400 hover:text-red-600 transition-colors dark:text-gray-400">
                                    <TrashIcon class="w-5 h-5" />
                                </button>
                            </div>
                            <div v-if="!teamMembers.length" class="text-center py-8 text-gray-400 text-sm italic dark:text-gray-400">
                                No team members assigned yet.
                            </div>
                        </div>
                    </div>

                    <!-- Add New Member Form -->
                    <div class="bg-gray-50/50 p-6 rounded-2xl border border-gray-100 dark:border-gray-700">
                        <h3 class="text-sm font-bold text-gray-700 uppercase tracking-wider mb-4 dark:text-gray-300">Add New Member</h3>
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
                                    <div class="w-full border-t border-gray-200 dark:border-gray-700"></div>
                                </div>
                                <div class="relative flex justify-center text-[10px] uppercase font-black">
                                    <span class="px-2 bg-gray-50 text-gray-400 dark:bg-gray-900/50 dark:text-gray-400">Or External Name</span>
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

                            <div>
                                <InputLabel value="Department / Sub-Unit" />
                                <HierarchySelector
                                    v-model="selectedDepartmentNode"
                                    :nodes="hierarchicalDepartmentOptions"
                                    placeholder="Select department or sub-unit..."
                                />
                                <InputError :message="teamForm.errors.department" />
                                <InputError :message="teamForm.errors.sub_unit" />
                            </div>

                            <div>
                                <InputLabel for="role_type" value="Role in Project" />
                                <select 
                                    v-model="teamForm.role_type" 
                                    id="role_type"
                                    class="w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm dark:border-gray-600"
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
                                        <span class="ml-2 text-sm text-gray-700 dark:text-gray-300">CASA Team</span>
                                    </label>
                                    <label class="flex items-center">
                                        <input type="radio" v-model="teamForm.team_category" value="Extended Team" class="text-blue-600 focus:ring-blue-500" />
                                        <span class="ml-2 text-sm text-gray-700 dark:text-gray-300">Extended</span>
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
                <div class="mt-8 pt-6 border-t border-gray-100 flex justify-end dark:border-gray-700">
                    <SecondaryButton @click="showManageTeamModal = false" class="px-8">
                        Done Managing Team
                    </SecondaryButton>
                </div>
            </div>
        </Modal>

        <div class="space-y-8">
            <!-- Project Header Info -->
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden dark:bg-gray-800 dark:border-gray-700">
                <div class="bg-gradient-to-r from-blue-600 to-indigo-700 h-2"></div>
                <div class="p-8 flex flex-col md:flex-row md:items-center justify-between gap-6">
                    <div class="flex items-start">
                        <Link :href="route('projects.index')" class="mr-6 p-3 rounded-xl bg-gray-50 text-gray-400 hover:text-blue-600 hover:bg-blue-50 transition-all border border-gray-100 dark:bg-gray-900/50 dark:text-gray-400 dark:border-gray-700">
                            <ChevronLeftIcon class="h-6 w-6" />
                        </Link>
                        <div>
                            <div class="flex items-center gap-2 mb-2 flex-wrap">
                                <span class="px-2.5 py-1 bg-gray-100 text-gray-600 text-[10px] font-black uppercase tracking-widest rounded-full border border-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:border-gray-600">
                                    {{ project.project_type || 'Store Opening' }}
                                </span>
                                <span v-if="project.store?.name || project.subject?.name" class="px-2.5 py-1 bg-blue-50 text-blue-700 text-[10px] font-black uppercase tracking-widest rounded-full border border-blue-100 dark:bg-blue-900/30 dark:text-blue-300 dark:border-blue-800">
                                    {{ project.store?.name || project.subject?.name }}
                                </span>
                                <span :class="['px-2.5 py-1 rounded-full text-[10px] font-black uppercase tracking-widest border', getStatusColor(project.status)]">
                                    {{ project.status }}
                                </span>
                            </div>
                            <div class="flex items-center gap-4">
                                <h1 class="text-3xl font-black text-gray-900 tracking-tight dark:text-gray-100">{{ project.name }}</h1>
                                <button 
                                    v-if="hasPermission('projects.edit')" 
                                    @click="openEditModal"
                                    class="p-2 rounded-lg bg-gray-50 text-gray-400 hover:text-indigo-600 hover:bg-indigo-50 transition-all border border-gray-100 dark:bg-gray-900/50 dark:text-gray-400 dark:border-gray-700"
                                    title="Edit Project Details"
                                >
                                    <PencilIcon class="h-5 w-5" />
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center gap-8 px-8 border-l border-gray-100 hidden lg:flex dark:border-gray-700">
                        <div class="text-center">
                            <p class="text-[10px] text-gray-400 font-black uppercase tracking-widest mb-1 dark:text-gray-400">Overall Completion</p>
                            <div class="flex items-center justify-center text-emerald-600">
                                <ChartBarIcon class="w-5 h-5 mr-2 opacity-50" />
                                <p class="text-xl font-black">{{ projectProgress }}%</p>
                            </div>
                        </div>
                        <div class="text-center border-l border-gray-50 pl-8">
                            <p class="text-[10px] text-gray-400 font-black uppercase tracking-widest mb-1 dark:text-gray-400">Target Go-Live</p>
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
                <nav class="flex p-1.5 bg-gray-100 rounded-2xl w-fit dark:bg-gray-800">
                    <button 
                        @click="activeTab = 'overview'"
                        :class="[activeTab === 'overview' ? 'bg-white text-blue-600 shadow-sm dark:bg-slate-900 dark:text-blue-300' : 'text-gray-500 hover:text-gray-700 dark:text-slate-300 dark:hover:text-slate-100', 'px-6 py-2.5 rounded-xl text-sm font-bold flex items-center transition-all']"
                    >
                        <InformationCircleIcon class="w-4 h-4 mr-2" />
                        Overview
                    </button>
                    <button 
                        @click="activeTab = 'gantt'"
                        :class="[activeTab === 'gantt' ? 'bg-white text-blue-600 shadow-sm dark:bg-slate-900 dark:text-blue-300' : 'text-gray-500 hover:text-gray-700 dark:text-slate-300 dark:hover:text-slate-100', 'px-6 py-2.5 rounded-xl text-sm font-bold flex items-center transition-all']"
                    >
                        <ChartBarIcon class="w-4 h-4 mr-2" />
                        Gantt Chart
                    </button>
                    <button 
                        @click="activeTab = 'assets'"
                        :class="[activeTab === 'assets' ? 'bg-white text-blue-600 shadow-sm dark:bg-slate-900 dark:text-blue-300' : 'text-gray-500 hover:text-gray-700 dark:text-slate-300 dark:hover:text-slate-100', 'px-6 py-2.5 rounded-xl text-sm font-bold flex items-center transition-all']"
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
                        <div class="bg-white shadow rounded-lg p-6 border border-gray-200 dark:bg-gray-800 dark:border-gray-700">
                            <h3 class="text-lg font-bold text-gray-900 mb-4 flex items-center dark:text-gray-100">
                                <CalendarIcon class="w-5 h-5 mr-2 text-blue-600" />
                                Project Timeline Milestones
                            </h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-y-6 gap-x-12">
                                <div class="flex flex-col">
                                    <span class="text-sm text-gray-500 dark:text-gray-300">Target Go-Live</span>
                                    <span class="text-lg font-bold text-blue-700">{{ formatDate(project.target_go_live) }}</span>
                                </div>
                                <div class="flex flex-col">
                                    <span class="text-sm text-gray-500 dark:text-gray-300">Store Turn-over</span>
                                    <span class="text-base font-semibold">{{ formatDate(project.turn_over_date) }}</span>
                                </div>
                                <div class="flex flex-col">
                                    <span class="text-sm text-gray-500 dark:text-gray-300">Training Dates</span>
                                    <span class="text-base font-semibold">{{ formatDate(project.training_date) }}</span>
                                </div>
                                <div class="flex flex-col">
                                    <span class="text-sm text-gray-500 dark:text-gray-300">Testing Date</span>
                                    <span class="text-base font-semibold">{{ formatDate(project.testing_date) }}</span>
                                </div>
                                <div class="flex flex-col">
                                    <span class="text-sm text-gray-500 dark:text-gray-300">Mock Service</span>
                                    <span class="text-base font-semibold">{{ formatDate(project.mock_service_date) }}</span>
                                </div>
                                <div class="flex flex-col">
                                    <span class="text-sm text-gray-500 dark:text-gray-300">Turn-over to Franchisee</span>
                                    <span class="text-base font-semibold">{{ formatDate(project.turn_over_to_franchisee_date) }}</span>
                                </div>
                            </div>
                        </div>

                        <div v-if="project.remarks" class="bg-white shadow rounded-lg p-6 border border-gray-200 dark:bg-gray-800 dark:border-gray-700">
                            <h3 class="text-lg font-bold text-gray-900 mb-2 dark:text-gray-100">Remarks</h3>
                            <p class="text-gray-700 whitespace-pre-line dark:text-gray-300">{{ project.remarks }}</p>
                        </div>
                    </div>

                    <!-- Team Card -->
                    <div class="space-y-6">
                        <div class="bg-white shadow rounded-lg p-6 border border-gray-200 dark:bg-gray-800 dark:border-gray-700">
                            <h3 class="text-lg font-bold text-gray-900 mb-4 flex items-center dark:text-gray-100">
                                <UserGroupIcon class="w-5 h-5 mr-2 text-blue-600" />
                                Project Team
                            </h3>
                            <div class="space-y-4">
                                <div v-for="member in teamMembers" :key="member.id" class="flex items-center justify-between">
                                    <div class="flex items-center">
                                        <div class="h-8 w-8 rounded-full bg-gray-200 flex items-center justify-center text-xs font-bold text-gray-600 dark:bg-gray-700 dark:text-gray-300">
                                            {{ (member.user?.name || member.external_name || 'U').charAt(0) }}
                                        </div>
                                        <div class="ml-3">
                                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ member.user?.name || member.external_name }}</p>
                                            <p class="text-xs text-gray-500 dark:text-gray-300">{{ member.role_type }}</p>
                                            <p class="text-[10px] font-bold text-blue-600">{{ member.department || '-' }} / {{ member.sub_unit || '-' }}</p>
                                        </div>
                                    </div>
                                    <span class="text-[10px] uppercase font-bold text-gray-400 bg-gray-50 px-1.5 py-0.5 rounded dark:bg-gray-900/50 dark:text-gray-400">
                                        {{ member.team_category }}
                                    </span>
                                </div>
                                <button @click.stop="showManageTeamModal = true" class="w-full mt-4 py-2 border-2 border-dashed border-gray-300 rounded-md text-sm text-gray-500 hover:border-blue-400 hover:text-blue-500 transition-colors dark:text-gray-300 dark:border-gray-600">
                                    Manage Team
                                </button>
                            </div>
                        </div>

                        <!-- Attach Existing Board (only when no task board linked yet and boards are available) -->
                        <div v-if="!project.task_board && availableBoards.length > 0 && hasPermission('projects.edit')" class="rounded-lg border-2 border-dashed border-gray-200 p-5 dark:border-gray-700">
                            <h3 class="mb-1 text-sm font-bold text-gray-700 dark:text-gray-300">Link Existing Board</h3>
                            <p class="mb-3 text-xs text-gray-500 dark:text-gray-400">Attach a manual task board to this project and import its cards as project tasks.</p>
                            <button
                                type="button"
                                @click="showAttachBoardModal = true"
                                class="inline-flex items-center gap-2 rounded-lg border border-indigo-300 bg-indigo-50 px-3 py-2 text-sm font-semibold text-indigo-700 transition-colors hover:bg-indigo-100 dark:border-indigo-700 dark:bg-indigo-900/20 dark:text-indigo-300"
                            >
                                <LinkIcon class="h-4 w-4" />
                                Attach Board
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Gantt Chart Tab -->
                <div v-if="activeTab === 'gantt'" class="dark:bg-slate-950">
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

        <!-- Attach Existing Board Modal -->
        <Modal :show="showAttachBoardModal" @close="showAttachBoardModal = false" maxWidth="md">
            <div class="p-6">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="text-lg font-bold text-gray-900 dark:text-gray-100">Attach Existing Board</h2>
                    <button type="button" @click="showAttachBoardModal = false" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                        <XMarkIcon class="h-5 w-5" />
                    </button>
                </div>
                <p class="mb-4 text-sm text-gray-600 dark:text-gray-400">
                    Select a manual task board to link to this project. Its cards will be imported as project tasks if this project has no tasks yet.
                </p>
                <div class="relative mb-3">
                    <input
                        v-model="attachBoardSearch"
                        type="text"
                        placeholder="Search boards…"
                        class="w-full rounded-lg border border-gray-300 py-2 pl-9 pr-3 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
                    />
                    <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-gray-400">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M17 11A6 6 0 1 1 5 11a6 6 0 0 1 12 0z" /></svg>
                    </span>
                </div>
                <div class="max-h-60 overflow-y-auto rounded-lg border border-gray-200 dark:border-gray-600">
                    <button
                        v-for="board in filteredAvailableBoards"
                        :key="board.id"
                        type="button"
                        @click="attachBoardId = board.id"
                        :class="[
                            'flex w-full items-center px-4 py-2.5 text-left text-sm transition-colors',
                            attachBoardId === board.id
                                ? 'bg-indigo-50 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-300'
                                : 'hover:bg-gray-50 dark:hover:bg-gray-700'
                        ]"
                    >
                        <span class="font-medium">{{ board.title }}</span>
                    </button>
                    <p v-if="filteredAvailableBoards.length === 0" class="px-4 py-6 text-center text-sm text-gray-400">No available boards found.</p>
                </div>
                <div class="mt-4 flex items-center justify-end gap-3">
                    <button type="button" @click="showAttachBoardModal = false" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300">
                        Cancel
                    </button>
                    <button
                        type="button"
                        :disabled="!attachBoardId || isAttachingBoard"
                        @click="confirmAttachBoard"
                        class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-40"
                    >
                        {{ isAttachingBoard ? 'Attaching…' : 'Attach & Import' }}
                    </button>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>
