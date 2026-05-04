<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import MultiAutocomplete from '@/Components/MultiAutocomplete.vue';
import { useToast } from '@/Composables/useToast';
import { usePermission } from '@/Composables/usePermission';
import {
    ArchiveBoxIcon,
    PlusIcon,
    StarIcon,
    UserGroupIcon,
    XMarkIcon,
} from '@heroicons/vue/24/outline';
import { StarIcon as StarSolidIcon } from '@heroicons/vue/24/solid';

const props = defineProps({
    boards: Array,
    users: Array,
    filters: Object,
});

const { showError } = useToast();
const { hasPermission } = usePermission();

const showCreateModal = ref(false);
const isSubmitting = ref(false);
const search = ref('');
const showClosed = ref(!!props.filters?.closed);

const form = reactive({
    title: '',
    description: '',
    background_type: 'color',
    background_value: '#0f766e',
    member_ids: [],
});

const backgroundOptions = [
    '#0f766e',
    '#1d4ed8',
    '#7c3aed',
    '#be123c',
    '#b45309',
    '#374151',
];

const filteredBoards = computed(() => {
    const term = search.value.trim().toLowerCase();
    if (!term) return props.boards || [];

    return (props.boards || []).filter((board) => {
        return board.title?.toLowerCase().includes(term) ||
            board.description?.toLowerCase().includes(term);
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

const openCreateModal = () => {
    form.title = '';
    form.description = '';
    form.background_type = 'color';
    form.background_value = '#0f766e';
    form.member_ids = [];
    showCreateModal.value = true;
};

const submitBoard = () => {
    if (isSubmitting.value) return;
    isSubmitting.value = true;

    router.post(route('task-lists.store'), form, {
        onError: (errors) => {
            showError(Object.values(errors).flat().join(', ') || 'Unable to create board');
        },
        onFinish: () => {
            isSubmitting.value = false;
        },
    });
};

const toggleClosed = () => {
    showClosed.value = !showClosed.value;
    router.get(route('task-lists.index'), { closed: showClosed.value ? 1 : 0 }, {
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

const initials = (name) => (name || 'U').split(' ').map((part) => part[0]).join('').slice(0, 2).toUpperCase();
</script>

<template>
    <Head title="Task Lists" />

    <AppLayout>
        <template #header>Task Lists</template>

        <div class="space-y-6">
            <div class="flex flex-col gap-4 rounded-lg border border-gray-200 bg-white p-5 shadow-sm md:flex-row md:items-center md:justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">Task Lists</h1>
                    <p class="mt-1 text-sm text-gray-500">Kanban boards for service work, follow-ups, and team tasks.</p>
                </div>
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                    <input
                        v-model="search"
                        type="search"
                        class="h-10 rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                        placeholder="Search boards..."
                    >
                    <button
                        type="button"
                        @click="toggleClosed"
                        class="inline-flex h-10 items-center justify-center gap-2 rounded-lg border border-gray-300 bg-white px-4 text-sm font-semibold text-gray-700 shadow-sm transition-colors hover:bg-gray-50"
                    >
                        <ArchiveBoxIcon class="h-4 w-4" />
                        {{ showClosed ? 'Open boards' : 'Closed boards' }}
                    </button>
                    <button
                        v-if="hasPermission('task_lists.create')"
                        type="button"
                        @click="openCreateModal"
                        class="inline-flex h-10 items-center justify-center gap-2 rounded-lg bg-blue-600 px-4 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-blue-700"
                    >
                        <PlusIcon class="h-4 w-4" />
                        Create Board
                    </button>
                </div>
            </div>

            <div v-if="sortedBoards.length" class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                <Link
                    v-for="board in sortedBoards"
                    :key="board.id"
                    :href="route('task-lists.show', board.id)"
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
                            <span v-if="board.closed_at" class="rounded-full bg-black/25 px-2 py-1 text-[10px] font-black uppercase tracking-wider">Closed</span>
                        </div>
                    </div>
                    <div class="flex items-center justify-between px-4 py-3">
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
                </Link>
            </div>

            <div v-else class="rounded-lg border border-dashed border-gray-300 bg-white p-12 text-center">
                <p class="text-sm font-semibold text-gray-600">No boards found.</p>
            </div>
        </div>

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
