<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { 
    PlusIcon, 
    TrashIcon, 
    ChevronRightIcon, 
    CalendarIcon,
    ClockIcon,
    CheckCircleIcon,
    ArrowPathIcon,
    ExclamationCircleIcon,
    FunnelIcon,
    ArrowsPointingOutIcon,
    PencilSquareIcon,
    XMarkIcon,
    DocumentDuplicateIcon
} from '@heroicons/vue/24/outline';

import { useToast } from '@/Composables/useToast.js';
import { useConfirm } from '@/Composables/useConfirm.js';

const props = defineProps({
    project: Object,
    users: Array,
});

const { success, info } = useToast();
const { confirm: confirmAction } = useConfirm();
const isAddingTask = ref(false);
const isEditing = ref(false);
const editingTaskId = ref(null);
const showFilters = ref(false);
const isApplyingTemplates = ref(false);

// Refs for scroll syncing (Simplified to single container)
const mainWorkspaceRef = ref(null);

const form = useForm({
    project_id: props.project.id,
    name: '',
    category: '',
    assigned_to: '',
    status: 'Pending',
    progress: 0,
    start_date: '',
    end_date: '',
});

// Sync status with progress in the form
watch(() => form.progress, (newProgress) => {
    if (newProgress >= 100) {
        form.status = 'Done';
    } else if (newProgress > 0) {
        form.status = 'Ongoing';
    } else {
        form.status = 'Pending';
    }
});

const stats = computed(() => {
    const tasks = props.project.tasks || [];
    const total = tasks.length;
    const completed = tasks.filter(t => t.status === 'Done').length;
    const ongoing = tasks.filter(t => t.status === 'Ongoing').length;
    const pending = tasks.filter(t => t.status === 'Pending').length;

    // Calculate average progress
    const totalProgressSum = tasks.reduce((sum, t) => sum + (t.progress || 0), 0);
    const progress = total > 0 ? Math.round(totalProgressSum / total) : 0;

    return { total, completed, ongoing, pending, progress };
});

const projectTeamMembers = computed(() => {
    // Relationship is named teamMembers in the Model
    const team = props.project.teamMembers || props.project.team_members || [];
    
    return team.map(m => {
        if (m.user) {
            return {
                id: m.user.id,
                name: m.user.name,
                is_external: false
            };
        } else {
            return {
                id: m.external_name, // Use name as ID for external
                name: m.external_name,
                is_external: true
            };
        }
    });
});

const applyActivityTemplates = async () => {
    const storeClass = props.project.store?.class || 'Regular';
    const ok = await confirmAction({
        title: 'Apply Activity Templates',
        message: `Are you sure you want to auto-populate tasks based on the "${storeClass}" store class? Existing tasks will not be duplicated.`,
        confirmLabel: 'Apply Templates',
        cancelLabel: 'Cancel',
        variant: 'primary'
    });
    
    if (ok) {
        isApplyingTemplates.value = true;
        useForm({}).post(route('projects.apply-templates', props.project.id), {
            preserveScroll: true,
            onFinish: () => {
                isApplyingTemplates.value = false;
            }
        });
    }
};

const saveTask = () => {
    if (isEditing.value) {
        form.put(route('projects-tasks.update', { 'projects_task': editingTaskId.value, tab: 'gantt' }), {
            preserveScroll: true,
            onSuccess: () => {
                isAddingTask.value = false;
                isEditing.value = false;
                editingTaskId.value = null;
                form.reset();
                form.project_id = props.project.id;
            }

        });
    } else {
        form.post(route('projects-tasks.store', { tab: 'gantt' }), {
            preserveScroll: true,
            onSuccess: () => {
                isAddingTask.value = false;
                form.reset();
                form.project_id = props.project.id;
            },
            onError: (errors) => {
                console.error('Task Creation Failed:', errors);
            }
        });
    }
};

const editTask = (task) => {
    isEditing.value = true;
    editingTaskId.value = task.id;
    isAddingTask.value = true;
    
    form.name = task.name;
    form.category = task.category;
    form.assigned_to = task.assigned_to;
    form.status = task.status;
    form.progress = task.progress;
    form.start_date = task.start_date ? task.start_date.split('T')[0] : '';
    form.end_date = task.end_date ? task.end_date.split('T')[0] : '';
};

const updateTaskField = (task, field, value) => {
    if (task[field] === value) return;

    const data = { [field]: value };
    
    // Auto-update status if progress is changed
    if (field === 'progress') {
        const prog = parseInt(value);
        if (prog >= 100) data.status = 'Done';
        else if (prog > 0) data.status = 'Ongoing';
        else data.status = 'Pending';
    }

    const updateForm = useForm(data);

    updateForm.put(route('projects-tasks.update', { 'projects_task': task.id, tab: 'gantt' }), {
        preserveScroll: true
    });
};

const deleteTask = async (taskId) => {
    const ok = await confirmAction({
        title: 'Delete Task',
        message: 'Are you sure you want to permanently delete this task? This cannot be undone.',
        confirmLabel: 'Delete',
        variant: 'danger'
    });
    
    if (ok) {
        useForm({}).delete(route('projects-tasks.destroy', { 'projects_task': taskId, tab: 'gantt' }), {
            preserveScroll: true
        });
    }
};

const closeForm = () => {
    isAddingTask.value = false;
    isEditing.value = false;
    editingTaskId.value = null;
    form.reset();
    form.project_id = props.project.id;
};

const parseLocalDate = (dateString) => {
    if (!dateString) return null;
    const datePart = dateString.split('T')[0];
    const [year, month, day] = datePart.split('-').map(Number);
    return new Date(year, month - 1, day);
};

const calculateDays = (start, end) => {
    if (!start || !end) return '-';
    const s = parseLocalDate(start);
    const e = parseLocalDate(end);
    const diffTime = Math.abs(e - s);
    return Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1;
};

const getStatusStyles = (status) => {
    switch (status) {
        case 'Done': return 'bg-emerald-100 text-emerald-700 border-emerald-200';
        case 'Ongoing': return 'bg-sky-100 text-sky-700 border-sky-200';
        case 'Pending': return 'bg-amber-100 text-amber-700 border-amber-200';
        default: return 'bg-slate-100 text-slate-700 border-slate-200';
    }
};

const getBarColorClass = (status) => {
    switch (status) {
        case 'Done': return 'bg-emerald-500';
        case 'Ongoing': return 'bg-indigo-500';
        case 'Pending': return 'bg-amber-500';
        default: return 'bg-slate-400';
    }
};

const timelineBounds = computed(() => {
    if (!props.project.tasks || props.project.tasks.length === 0) {
        const today = new Date();
        const twoWeeks = new Date(today);
        twoWeeks.setDate(twoWeeks.getDate() + 14);
        return { start: today, end: twoWeeks };
    }

    let minDate = null;
    let maxDate = null;

    props.project.tasks.forEach(task => {
        if (task.start_date) {
            const s = parseLocalDate(task.start_date);
            if (!minDate || s < minDate) minDate = s;
        }
        if (task.end_date) {
            const e = parseLocalDate(task.end_date);
            if (!maxDate || e > maxDate) maxDate = e;
        }
    });

    if (minDate) minDate.setDate(minDate.getDate() - 5);
    if (maxDate) maxDate.setDate(maxDate.getDate() + 10);

    return { 
        start: minDate || new Date(), 
        end: maxDate || new Date(new Date().setDate(new Date().getDate() + 20)) 
    };
});

const timelineDays = computed(() => {
    const days = [];
    let current = new Date(timelineBounds.value.start);
    const end = new Date(timelineBounds.value.end);

    while (current <= end) {
        days.push(new Date(current));
        current.setDate(current.getDate() + 1);
    }
    return days;
});

const getGanttBarStyles = (task) => {
    if (!task.start_date || !task.end_date) return { display: 'none' };
    
    const taskStart = parseLocalDate(task.start_date);
    const taskEnd = parseLocalDate(task.end_date);
    
    const startIndex = timelineDays.value.findIndex(d => d.toDateString() === taskStart.toDateString());
    let endIndex = timelineDays.value.findIndex(d => d.toDateString() === taskEnd.toDateString());
    
    if (startIndex === -1) return { display: 'none' };
    if (endIndex === -1) endIndex = startIndex;

    return {
        gridColumnStart: startIndex + 1,
        gridColumnEnd: endIndex + 2
    };
};

const groupedTasks = computed(() => {
    if (!props.project.tasks) return {};
    return props.project.tasks.reduce((groups, task) => {
        const category = task.category || 'General';
        if (!groups[category]) groups[category] = [];
        groups[category].push(task);
        return groups;
    }, {});
});

const syncScroll = (e) => {
    const { scrollTop } = e.target;
    if (leftTableRef.value) leftTableRef.value.scrollTop = scrollTop;
    if (rightTableRef.value) rightTableRef.value.scrollTop = scrollTop;
};

const isToday = (date) => {
    const today = new Date();
    return date.getDate() === today.getDate() &&
           date.getMonth() === today.getMonth() &&
           date.getFullYear() === today.getFullYear();
};

const isWeekend = (date) => {
    const day = date.getDay();
    return day === 0 || day === 6;
};
</script>

<template>
    <div class="bg-slate-50 rounded-xl border border-slate-200 shadow-xl flex flex-col h-[750px] overflow-hidden">
        <!-- Modern Toolbar -->
        <div class="bg-white px-6 py-4 border-b border-slate-200 flex flex-wrap items-center justify-between gap-4">
            <div class="flex items-center space-x-4">
                <div class="p-2 bg-indigo-50 rounded-lg">
                    <CalendarIcon class="w-6 h-6 text-indigo-600" />
                </div>
                <div>
                    <h3 class="text-lg font-bold text-slate-900">Project Timeline</h3>
                    <p class="text-xs text-slate-500 font-medium">Manage tasks and schedule visualize</p>
                </div>
            </div>

            <!-- Stats Summary -->
            <div class="hidden lg:flex items-center space-x-6 px-6 border-l border-r border-slate-100">
                <div class="text-center">
                    <p class="text-[10px] uppercase tracking-wider font-bold text-slate-400">Completion</p>
                    <p class="text-sm font-bold text-slate-900">{{ stats.progress }}%</p>
                </div>
                <div class="h-8 w-px bg-slate-100"></div>
                <div class="text-center">
                    <p class="text-[10px] uppercase tracking-wider font-bold text-slate-400">Total Tasks</p>
                    <p class="text-sm font-bold text-slate-900">{{ stats.total }}</p>
                </div>
                <div class="h-8 w-px bg-slate-100"></div>
                <div class="text-center">
                    <p class="text-[10px] uppercase tracking-wider font-bold text-emerald-500">Done</p>
                    <p class="text-sm font-bold text-slate-900">{{ stats.completed }}</p>
                </div>
            </div>

            <div class="flex items-center space-x-2">
                <button @click="applyActivityTemplates" 
                        class="inline-flex items-center px-4 py-2 bg-white border border-indigo-200 hover:bg-indigo-50 text-indigo-700 text-sm font-bold rounded-lg shadow-sm transition-all transform active:scale-95 disabled:opacity-50"
                        :disabled="isApplyingTemplates"
                >
                    <DocumentDuplicateIcon class="w-4 h-4 mr-2" />
                    {{ isApplyingTemplates ? 'Applying...' : 'Apply Templates' }}
                </button>
                <button @click="showFilters = !showFilters" class="p-2 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-colors">
                    <FunnelIcon class="w-5 h-5" />
                </button>
                <button 
                    @click="isAddingTask = !isAddingTask"
                    class="inline-flex items-center px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-bold rounded-lg shadow-sm transition-all transform active:scale-95"
                >
                    <PlusIcon class="w-4 h-4 mr-2" />
                    New Task
                </button>
            </div>
        </div>

        <transition
            enter-active-class="transition duration-300 ease-out"
            enter-from-class="transform -translate-y-4 opacity-0"
            enter-to-class="transform translate-y-0 opacity-100"
            leave-active-class="transition duration-200 ease-in"
            leave-from-class="transform translate-y-0 opacity-100"
            leave-to-class="transform -translate-y-4 opacity-0"
        >
            <div v-if="isAddingTask" class="p-6 bg-indigo-50/30 border-b border-indigo-100 z-30">
                <div class="grid grid-cols-1 md:grid-cols-12 gap-x-8 gap-y-4 items-end">
                    <div class="md:col-span-2">
                        <label class="block text-[10px] font-bold text-indigo-900 uppercase tracking-widest mb-1.5 ml-1">Category</label>
                        <input v-model="form.category" type="text" placeholder="Group Name" class="w-full text-sm border-slate-200 rounded-xl shadow-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-all">
                        <div v-if="form.errors.category" class="text-red-500 text-[10px] mt-1 ml-1 font-bold italic">{{ form.errors.category }}</div>
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-[10px] font-bold text-indigo-900 uppercase tracking-widest mb-1.5 ml-1">Task Name</label>
                        <input v-model="form.name" type="text" placeholder="What needs to be done?" class="w-full text-sm border-slate-200 rounded-xl shadow-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-all">
                        <div v-if="form.errors.name" class="text-red-500 text-[10px] mt-1 ml-1 font-bold italic">{{ form.errors.name }}</div>
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-[10px] font-bold text-indigo-900 uppercase tracking-widest mb-1.5 ml-1">Assigned To</label>
                        <select v-model="form.assigned_to" class="w-full text-sm border-slate-200 rounded-xl shadow-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-all">
                            <option value="">Unassigned</option>
                            <option v-for="member in projectTeamMembers" :key="member.id" :value="member.id">{{ member.name }}</option>
                        </select>
                        <div v-if="form.errors.assigned_to" class="text-red-500 text-[10px] mt-1 ml-1 font-bold italic">{{ form.errors.assigned_to }}</div>
                    </div>
                    <div class="md:col-span-1">
                        <label class="block text-[10px] font-bold text-indigo-900 uppercase tracking-widest mb-1.5 ml-1">Progress</label>
                        <input v-model="form.progress" type="number" min="0" max="100" class="w-full text-sm border-slate-200 rounded-xl shadow-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-all">
                        <div v-if="form.errors.progress" class="text-red-500 text-[10px] mt-1 ml-1 font-bold italic">{{ form.errors.progress }}</div>
                    </div>
                    <div class="md:col-span-3">
                        <label class="block text-[10px] font-bold text-indigo-900 uppercase tracking-widest mb-1.5 ml-1">Timeline</label>
                        <div class="flex items-center space-x-2">
                            <input v-model="form.start_date" type="date" class="w-full text-xs border-slate-200 rounded-xl shadow-sm focus:ring-2 focus:ring-indigo-500">
                            <span class="text-slate-400">→</span>
                            <input v-model="form.end_date" type="date" class="w-full text-xs border-slate-200 rounded-xl shadow-sm focus:ring-2 focus:ring-indigo-500">
                        </div>
                        <div v-if="form.errors.start_date || form.errors.end_date" class="text-red-500 text-[10px] mt-1 ml-1 font-bold italic">{{ form.errors.start_date || form.errors.end_date }}</div>
                    </div>
                    <div class="md:col-span-2 flex items-center space-x-2 pl-4">
                        <button @click="saveTask" :disabled="form.processing" class="flex-1 bg-indigo-600 text-white font-bold py-2.5 rounded-xl hover:bg-indigo-700 shadow-md transition-all active:scale-95 disabled:opacity-50 text-sm whitespace-nowrap">
                            {{ isEditing ? 'Update' : 'Add' }}
                        </button>
                        <button @click="closeForm" class="flex-1 px-3 py-2.5 bg-white text-slate-500 font-bold border border-slate-200 rounded-xl hover:bg-slate-50 transition-all text-sm whitespace-nowrap">
                            Cancel
                        </button>
                    </div>
                </div>
            </div>
        </transition>

        <!-- Main Workspace: Unified Scroll -->
        <div class="flex-1 overflow-auto relative bg-[#fafbfc]" ref="mainWorkspaceRef">
            <div :style="{ width: (480 + timelineDays.length * 48) + 'px' }" class="relative min-h-full">
                
                <!-- STICKY HEADER ROW -->
                <div class="sticky top-0 z-40 flex h-14 bg-white border-b border-slate-200">
                    <!-- Left Header -->
                    <div class="sticky left-0 z-50 w-[480px] h-full flex items-center bg-slate-50/95 backdrop-blur-sm px-4 text-[10px] font-bold text-slate-500 uppercase tracking-widest border-r border-slate-200 shadow-[8px_0_15px_-10px_rgba(0,0,0,0.05)]">
                        <div class="w-1/2">Task Name</div>
                        <div class="w-1/4 px-2 text-center">Owner</div>
                        <div class="w-1/4 pl-2 pr-6 text-right">Status</div>
                    </div>
                    <!-- Right Header (Timeline) -->
                    <div class="flex-1 flex flex-col">
                        <div class="h-7 flex items-center px-4 border-b border-slate-100 text-[10px] font-black text-slate-400 uppercase tracking-widest relative bg-white/80 backdrop-blur-md">
                            <template v-for="(day, idx) in timelineDays" :key="'m'+idx">
                                <div v-if="day.getDate() === 1 || idx === 0" 
                                     class="absolute flex items-center space-x-2"
                                     :style="{ left: (idx * 48 + 16) + 'px' }">
                                    <span class="text-slate-900">{{ day.toLocaleString('en-US', { month: 'short' }) }}</span>
                                    <span class="text-slate-300">{{ day.getFullYear() }}</span>
                                </div>
                            </template>
                        </div>
                        <div class="h-7 flex text-[10px] font-bold text-slate-500 bg-white/80 backdrop-blur-md">
                            <div v-for="(day, idx) in timelineDays" :key="idx" 
                                 class="flex-shrink-0 w-12 flex items-center justify-center border-r border-slate-100"
                                 :class="[
                                    isWeekend(day) ? 'bg-slate-50/50 text-slate-300' : 'text-slate-400',
                                    isToday(day) ? 'bg-indigo-600 text-white z-20 rounded-t-sm shadow-lg' : ''
                                 ]">
                                {{ day.getDate() }}
                            </div>
                        </div>
                    </div>
                </div>

                <!-- BODY -->
                <div class="relative">
                    <!-- Vertical Grid Lines (Background) -->
                    <div class="absolute inset-0 flex pointer-events-none z-0">
                         <div class="w-[480px] flex-shrink-0"></div>
                         <div v-for="(day, idx) in timelineDays" :key="'grid'+idx" 
                             class="flex-shrink-0 w-12 border-r border-slate-100 h-full"
                             :class="[
                                isWeekend(day) ? 'bg-slate-50/10' : '',
                                isToday(day) ? 'bg-indigo-50/30 border-r-indigo-200 border-r-2' : ''
                             ]">
                        </div>
                    </div>

                    <!-- Today Indicator Line -->
                    <div v-for="(day, idx) in timelineDays" :key="'line'+idx">
                         <div v-if="isToday(day)" class="absolute h-full w-[2px] bg-indigo-500/50 z-10 pointer-events-none" :style="{ left: (480 + idx * 48 + 23) + 'px' }">
                            <div class="bg-indigo-600 text-[8px] text-white px-1 rounded-sm absolute -top-0 transform -translate-x-1/2 font-bold shadow-sm uppercase tracking-tighter">Today</div>
                         </div>
                    </div>

                    <!-- Rows -->
                    <template v-for="(tasks, category) in groupedTasks" :key="category">
                        <!-- Category Row -->
                        <div class="flex sticky top-14 z-30">
                            <div class="sticky left-0 z-40 w-[480px] h-10 bg-slate-100/80 backdrop-blur-md flex items-center px-4 border-b border-slate-200 border-r shadow-[8px_0_15px_-10px_rgba(0,0,0,0.05)]">
                                <div class="flex items-center space-x-2">
                                    <ChevronRightIcon class="w-3 h-3 text-slate-400 transform rotate-90" />
                                    <span class="text-[11px] font-black text-slate-600 uppercase tracking-wider">{{ category }}</span>
                                    <span class="ml-2 px-1.5 py-0.5 bg-slate-200 text-slate-500 rounded text-[9px] font-bold">{{ tasks.length }}</span>
                                </div>
                            </div>
                            <div class="flex-1 h-10 bg-slate-100/30 border-b border-slate-200"></div>
                        </div>

                        <!-- Task Rows -->
                        <div v-for="task in tasks" :key="task.id" @click="editTask(task)"
                             class="flex min-h-[3.5rem] border-b border-slate-100 hover:bg-indigo-50/10 group transition-colors cursor-pointer relative z-10">
                            
                            <!-- Left Task Info (Sticky) -->
                            <div class="sticky left-0 z-20 w-[480px] bg-white group-hover:bg-transparent flex items-center border-r border-slate-200 shadow-[8px_0_15px_-10px_rgba(0,0,0,0.05)]">
                                <div class="w-1/2 px-4 flex items-center space-x-3 py-2">
                                    <div class="relative flex-shrink-0" @click.stop>
                                        <div class="w-5 h-5 rounded-full border-2 flex items-center justify-center transition-colors"
                                             :class="task.status === 'Done' ? 'border-emerald-500 bg-emerald-50' : 'border-slate-200 group-hover:border-indigo-300'">
                                            <CheckCircleIcon v-if="task.status === 'Done'" class="w-3.5 h-3.5 text-emerald-600" />
                                        </div>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center justify-between mb-0.5">
                                            <div class="text-[13px] font-bold text-slate-700 whitespace-normal break-words leading-tight mr-2">
                                                {{ task.name }}
                                            </div>
                                            <span class="text-[10px] font-black text-slate-400 tabular-nums flex-shrink-0">{{ task.progress }}%</span>
                                        </div>
                                        <div class="w-full bg-slate-100 h-1 rounded-full overflow-hidden">
                                            <div class="h-full transition-all duration-500" 
                                                 :class="getBarColorClass(task.status)" 
                                                 :style="{ width: task.progress + '%' }"></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="w-1/4 px-2 text-center py-2">
                                     <div v-if="task.assigned_to" class="mx-auto h-7 w-7 rounded-lg bg-indigo-100 flex items-center justify-center text-[10px] font-bold text-indigo-700 border border-indigo-200" :title="users.find(u => u.id == task.assigned_to)?.name">
                                        {{ (users.find(u => u.id == task.assigned_to)?.name || 'U').charAt(0) }}
                                    </div>
                                    <div v-else class="mx-auto h-7 w-7 rounded-lg border border-dashed border-slate-200 flex items-center justify-center text-slate-300">?</div>
                                </div>
                                <div class="w-1/4 pl-2 pr-6 flex items-center justify-end gap-2 group/actions relative py-2">
                                    <div class="flex-shrink-0">
                                        <span class="px-3 py-1 border rounded-full text-[10px] font-black uppercase tracking-widest transition-all shadow-sm min-w-[70px] inline-block text-center"
                                              :class="getStatusStyles(task.status)">
                                            {{ task.status }}
                                        </span>
                                    </div>
                                    <div class="flex items-center">
                                        <button @click.stop="deleteTask(task.id)" class="p-1 text-red-400 hover:text-red-600 transition-colors opacity-40 group-hover:opacity-100 flex-shrink-0" title="Delete Task">
                                            <TrashIcon class="w-4 h-4" />
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- Right Gantt Bar Area -->
                            <div class="flex-1 relative">
                                <div class="absolute inset-0 grid h-full py-2.5 px-[1px]" :style="{ gridTemplateColumns: `repeat(${timelineDays.length}, 48px)` }">
                                    <div v-if="task.start_date && task.end_date"
                                         class="h-full rounded-lg relative overflow-hidden group/bar transition-all hover:scale-[1.01] hover:shadow-lg cursor-pointer z-20"
                                         :style="getGanttBarStyles(task)"
                                         @click="isAddingTask = false"
                                    >
                                        <div class="absolute inset-0" :class="getBarColorClass(task.status)"></div>
                                        <div class="absolute top-0 left-0 h-full bg-black/15 flex items-center justify-end pr-2 overflow-hidden" :style="{ width: task.progress + '%' }">
                                            <div v-if="task.progress > 0" class="h-full w-full bg-gradient-to-r from-transparent to-white/10"></div>
                                        </div>
                                        <div class="absolute inset-0 flex items-center px-2 justify-between gap-1.5">
                                            <span class="text-[10px] font-black text-white truncate shadow-sm tracking-tight flex-1">{{ task.name }}</span>
                                            <span class="text-[9px] font-bold text-white/80 whitespace-nowrap">{{ task.progress }}%</span>
                                        </div>
                                        <div class="absolute bottom-full left-1/2 transform -translate-x-1/2 mb-2 px-2 py-1 bg-slate-800 text-white text-[9px] rounded opacity-0 group-hover/bar:opacity-100 pointer-events-none transition-opacity whitespace-nowrap z-50 font-bold">
                                            {{ task.name }}: {{ task.start_date.split('T')[0] }} to {{ task.end_date.split('T')[0] }}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        <!-- Footer / Shortcuts -->
        <div class="h-10 bg-white border-t border-slate-200 px-6 flex items-center justify-between text-[10px] text-slate-400 font-bold uppercase tracking-widest">
            <div class="flex items-center space-x-6">
                <div class="flex items-center space-x-2">
                    <div class="w-2 h-2 rounded-full bg-indigo-500"></div>
                    <span>Project Plan</span>
                </div>
                <div class="flex items-center space-x-2">
                    <div class="w-2 h-2 rounded-full bg-emerald-500"></div>
                    <span>Completed</span>
                </div>
                <div class="flex items-center space-x-2">
                    <div class="w-2 h-2 rounded-full bg-slate-200"></div>
                    <span>Weekend</span>
                </div>
            </div>
            <div class="flex items-center space-x-2">
                <ClockIcon class="w-3 h-3" />
                <span>Last updated: {{ new Date().toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'}) }}</span>
            </div>
        </div>
    </div>
</template>

<style scoped>
.no-scrollbar::-webkit-scrollbar {
    display: none;
}
.no-scrollbar {
    -ms-overflow-style: none;
    scrollbar-width: none;
}

/* Custom Horizontal Scrollbar */
::-webkit-scrollbar {
    height: 10px;
    width: 6px;
}
::-webkit-scrollbar-track {
    background: #f8fafc;
}
::-webkit-scrollbar-thumb {
    background: #e2e8f0;
    border-radius: 10px;
    border: 2px solid #f8fafc;
}
::-webkit-scrollbar-thumb:hover {
    background: #cbd5e1;
}

/* Scroll Syncing Smoothness */
.scroll-sync {
    will-change: transform;
}
</style>
