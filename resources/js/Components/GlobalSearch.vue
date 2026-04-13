<template>
    <div class="relative max-w-lg w-full" v-click-away="closeSearch">
        <div class="relative">
            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                <MagnifyingGlassIcon class="h-5 w-5 text-gray-400" aria-hidden="true" />
            </div>
            <input
                ref="searchInput"
                v-model="query"
                type="text"
                class="block w-full pl-10 pr-3 py-2 border border-gray-300 rounded-md leading-5 bg-white placeholder-gray-500 focus:outline-none focus:placeholder-gray-400 focus:ring-1 focus:ring-blue-500 focus:border-blue-500 sm:text-sm transition-all duration-200"
                placeholder="Search tickets, users, menus... (Ctrl+/)"
                @input="handleInput"
                @focus="isFocused = true"
                @keydown.esc="closeSearch"
                @keydown.down.prevent="navigateResults(1)"
                @keydown.up.prevent="navigateResults(-1)"
                @keydown.enter.prevent="selectCurrentResult"
            />
            <div v-if="loading" class="absolute inset-y-0 right-0 pr-3 flex items-center">
                <LoadingSpinner size="sm" />
            </div>
        </div>

        <!-- Search Results Dropdown -->
        <div
            v-if="showResults"
            class="absolute mt-1 w-full bg-white shadow-2xl rounded-md border border-gray-200 z-50 overflow-hidden max-h-[80vh] flex flex-col"
        >
            <div class="overflow-y-auto py-2">
                <!-- Menus -->
                <div v-if="results.menus.length > 0">
                    <div class="px-4 py-2 text-xs font-semibold text-gray-500 uppercase tracking-wider bg-gray-50">
                        Navigation
                    </div>
                    <ul class="divide-y divide-gray-100">
                        <li v-for="(menu, index) in results.menus" :key="'menu-' + index">
                            <Link
                                :href="menu.url"
                                class="flex items-center px-4 py-3 hover:bg-blue-50 transition-colors group"
                                :class="{ 'bg-blue-50': isSelected('menu', index) }"
                                @click="closeSearch"
                            >
                                <div class="flex-shrink-0 w-8 h-8 bg-blue-100 text-blue-600 rounded-md flex items-center justify-center">
                                    <LinkIcon class="w-4 h-4" />
                                </div>
                                <div class="ml-3">
                                    <p class="text-sm font-medium text-gray-900 group-hover:text-blue-600">
                                        {{ menu.name }}
                                    </p>
                                    <p class="text-xs text-gray-500">{{ menu.path }}</p>
                                </div>
                            </Link>
                        </li>
                    </ul>
                </div>

                <!-- Tickets -->
                <div v-if="results.tickets.length > 0">
                    <div class="px-4 py-2 text-xs font-semibold text-gray-500 uppercase tracking-wider bg-gray-50 border-t border-gray-100">
                        Tickets
                    </div>
                    <ul class="divide-y divide-gray-100">
                        <li v-for="(ticket, index) in results.tickets" :key="'ticket-' + index">
                            <Link
                                :href="route('tickets.show', ticket.id)"
                                class="flex items-center px-4 py-3 hover:bg-blue-50 transition-colors group"
                                :class="{ 'bg-blue-50': isSelected('ticket', index) }"
                                @click="closeSearch"
                            >
                                <div class="flex-shrink-0 w-8 h-8 bg-orange-100 text-orange-600 rounded-md flex items-center justify-center">
                                    <TicketIcon class="w-4 h-4" />
                                </div>
                                <div class="ml-3 flex-1 min-w-0">
                                    <div class="flex items-center justify-between">
                                        <p class="text-sm font-medium text-gray-900 truncate group-hover:text-blue-600">
                                            <span class="text-blue-600 font-bold">[{{ ticket.ticket_key }}]</span> {{ ticket.title }}
                                        </p>
                                        <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium" :class="getStatusClass(ticket.status)">
                                            {{ ticket.status }}
                                        </span>
                                    </div>
                                    <p class="text-xs text-gray-500 truncate">
                                        {{ ticket.company_name }} • Assigned to: {{ ticket.assignee_name }}
                                    </p>
                                </div>
                            </Link>
                        </li>
                    </ul>
                </div>

                <!-- POS Requests -->
                <div v-if="results.pos_requests.length > 0">
                    <div class="px-4 py-2 text-xs font-semibold text-gray-500 uppercase tracking-wider bg-gray-50 border-t border-gray-100">
                        POS Requests
                    </div>
                    <ul class="divide-y divide-gray-100">
                        <li v-for="(req, index) in results.pos_requests" :key="'pos-' + index">
                            <Link
                                :href="route('pos-requests.show', req.id)"
                                class="flex items-center px-4 py-3 hover:bg-blue-50 transition-colors group"
                                :class="{ 'bg-blue-50': isSelected('pos_request', index) }"
                                @click="closeSearch"
                            >
                                <div class="flex-shrink-0 w-8 h-8 bg-indigo-100 text-indigo-600 rounded-md flex items-center justify-center">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                                </div>
                                <div class="ml-3 flex-1 min-w-0">
                                    <div class="flex items-center justify-between">
                                        <p class="text-sm font-medium text-gray-900 truncate group-hover:text-blue-600">
                                            <span class="text-indigo-600 font-bold">[POS #{{ req.id }}]</span> {{ req.request_type }}
                                        </p>
                                        <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium" :class="getStatusClass(req.status)">
                                            {{ req.status }}
                                        </span>
                                    </div>
                                    <p class="text-xs text-gray-500 truncate">
                                        {{ req.company }} • {{ req.requester }}
                                        <span v-if="req.ticket_key" class="ml-1 font-bold text-blue-600">• {{ req.ticket_key }}</span>
                                    </p>
                                </div>
                            </Link>
                        </li>
                    </ul>
                </div>

                <!-- SAP Requests -->
                <div v-if="results.sap_requests.length > 0">
                    <div class="px-4 py-2 text-xs font-semibold text-gray-500 uppercase tracking-wider bg-gray-50 border-t border-gray-100">
                        SAP Requests
                    </div>
                    <ul class="divide-y divide-gray-100">
                        <li v-for="(req, index) in results.sap_requests" :key="'sap-' + index">
                            <Link
                                :href="route('sap-requests.show', req.id)"
                                class="flex items-center px-4 py-3 hover:bg-teal-50 transition-colors group"
                                :class="{ 'bg-teal-50': isSelected('sap_request', index) }"
                                @click="closeSearch"
                            >
                                <div class="flex-shrink-0 w-8 h-8 bg-teal-100 text-teal-600 rounded-md flex items-center justify-center">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                </div>
                                <div class="ml-3 flex-1 min-w-0">
                                    <div class="flex items-center justify-between">
                                        <p class="text-sm font-medium text-gray-900 truncate group-hover:text-teal-600">
                                            <span class="text-teal-600 font-bold">[SAP #{{ req.id }}]</span> {{ req.request_type }}
                                        </p>
                                        <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium" :class="getStatusClass(req.status)">
                                            {{ req.status }}
                                        </span>
                                    </div>
                                    <p class="text-xs text-gray-500 truncate">
                                        {{ req.company }} • {{ req.requester }}
                                        <span v-if="req.ticket_key" class="ml-1 font-bold text-teal-600">• {{ req.ticket_key }}</span>
                                    </p>
                                </div>
                            </Link>
                        </li>
                    </ul>
                </div>

                <!-- Users -->
                <div v-if="results.users.length > 0">
                    <div class="px-4 py-2 text-xs font-semibold text-gray-500 uppercase tracking-wider bg-gray-50 border-t border-gray-100">
                        Users
                    </div>
                    <ul class="divide-y divide-gray-100">
                        <li v-for="(user, index) in results.users" :key="'user-' + index">
                            <Link
                                :href="route('users.index', { search: user.email })"
                                class="flex items-center px-4 py-3 hover:bg-blue-50 transition-colors group"
                                :class="{ 'bg-blue-50': isSelected('user', index) }"
                                @click="closeSearch"
                            >
                                <div v-if="user.profile_photo" class="flex-shrink-0 w-8 h-8 rounded-full overflow-hidden">
                                    <img :src="'/serve-storage/' + user.profile_photo" class="w-full h-full object-cover">
                                </div>
                                <div v-else class="flex-shrink-0 w-8 h-8 bg-gray-100 text-gray-600 rounded-full flex items-center justify-center">
                                    <UserIcon class="w-4 h-4" />
                                </div>
                                <div class="ml-3">
                                    <p class="text-sm font-medium text-gray-900 group-hover:text-blue-600">
                                        {{ user.name }}
                                    </p>
                                    <p class="text-xs text-gray-500">{{ user.email }}</p>
                                </div>
                            </Link>
                        </li>
                    </ul>
                </div>

                <!-- No Results -->
                <div v-if="!loading && query.length >= 2 && totalResults === 0" class="px-4 py-8 text-center" role="status">
                    <MagnifyingGlassIcon class="mx-auto h-12 w-12 text-gray-400" />
                    <h3 class="mt-2 text-sm font-medium text-gray-900">No results found</h3>
                    <p class="mt-1 text-sm text-gray-500">No matches found for "{{ query }}"</p>
                </div>

                <div v-if="query.length < 2 && query.length > 0" class="px-4 py-4 text-center text-sm text-gray-500">
                    Type at least 2 characters to search...
                </div>
            </div>
            
            <!-- Footer -->
            <div class="px-4 py-2 bg-gray-50 border-t border-gray-100 flex items-center justify-between text-[10px] text-gray-400">
                <div class="flex items-center space-x-3">
                    <span><kbd class="px-1.5 py-0.5 border border-gray-300 rounded bg-white text-gray-600 font-sans">ESC</kbd> to close</span>
                    <span><kbd class="px-1.5 py-0.5 border border-gray-300 rounded bg-white text-gray-600 font-sans">↑↓</kbd> to navigate</span>
                    <span><kbd class="px-1.5 py-0.5 border border-gray-300 rounded bg-white text-gray-600 font-sans">ENTER</kbd> to select</span>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, watch, onMounted, onUnmounted, computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import { 
    MagnifyingGlassIcon, 
    TicketIcon, 
    UserIcon, 
    LinkIcon 
} from '@heroicons/vue/24/outline';
import LoadingSpinner from '@/Components/LoadingSpinner.vue';

const query = ref('');
const results = ref({ menus: [], tickets: [], pos_requests: [], sap_requests: [], users: [] });
const loading = ref(false);
const isFocused = ref(false);
const searchInput = ref(null);
const selectedIndex = ref(-1);
let debounceTimeout = null;

const totalResults = computed(() => {
    return results.value.menus.length + results.value.tickets.length +
           results.value.pos_requests.length + results.value.sap_requests.length +
           results.value.users.length;
});

const showResults = computed(() => {
    return isFocused.value && (query.value.length >= 2 || (query.value.length > 0 && loading.value));
});

const handleInput = () => {
    if (debounceTimeout) clearTimeout(debounceTimeout);
    
    if (query.value.length < 2) {
        results.value = { menus: [], tickets: [], pos_requests: [], sap_requests: [], users: [] };
        return;
    }

    debounceTimeout = setTimeout(async () => {
        loading.value = true;
        try {
            const response = await axios.get(route('global-search'), {
                params: { query: query.value }
            });
            results.value = response.data;
            selectedIndex.value = totalResults.value > 0 ? 0 : -1;
        } catch (error) {
            console.error('Search error:', error);
        } finally {
            loading.value = false;
        }
    }, 300);
};

const closeSearch = () => {
    isFocused.value = false;
    selectedIndex.value = -1;
};

const getStatusClass = (status) => {
    if (!status) return 'bg-gray-100 text-gray-800';
    switch (status.toLowerCase()) {
        case 'open': return 'bg-blue-100 text-blue-800';
        case 'in progress': return 'bg-yellow-100 text-yellow-800';
        case 'resolved': return 'bg-green-100 text-green-800';
        case 'closed': return 'bg-gray-100 text-gray-800';
        case 'pending': return 'bg-purple-100 text-purple-800';
        default: return 'bg-gray-100 text-gray-800';
    }
};

// Navigation logic
const allResults = computed(() => {
    const list = [];
    results.value.menus.forEach((item, index) => list.push({ type: 'menu', index, data: item }));
    results.value.tickets.forEach((item, index) => list.push({ type: 'ticket', index, data: item }));
    results.value.pos_requests.forEach((item, index) => list.push({ type: 'pos_request', index, data: item }));
    results.value.sap_requests.forEach((item, index) => list.push({ type: 'sap_request', index, data: item }));
    results.value.users.forEach((item, index) => list.push({ type: 'user', index, data: item }));
    return list;
});

const navigateResults = (direction) => {
    if (totalResults.value === 0) return;
    selectedIndex.value = (selectedIndex.value + direction + totalResults.value) % totalResults.value;
};

const isSelected = (type, index) => {
    const item = allResults.value[selectedIndex.value];
    return item && item.type === type && item.index === index;
};

const selectCurrentResult = () => {
    const item = allResults.value[selectedIndex.value];
    if (item) {
        if (item.type === 'menu') router.visit(item.data.url);
        else if (item.type === 'ticket') router.visit(route('tickets.show', item.data.id));
        else if (item.type === 'pos_request') router.visit(route('pos-requests.show', item.data.id));
        else if (item.type === 'sap_request') router.visit(route('sap-requests.show', item.data.id));
        else if (item.type === 'user') router.visit(route('users.index', { search: item.data.email }));
        closeSearch();
    }
};

// Keyboard shortcut
const handleKeydown = (e) => {
    if ((e.ctrlKey || e.metaKey) && e.key === '/') {
        e.preventDefault();
        searchInput.value?.focus();
    }
};

onMounted(() => {
    window.addEventListener('keydown', handleKeydown);
});

onUnmounted(() => {
    window.removeEventListener('keydown', handleKeydown);
    if (debounceTimeout) clearTimeout(debounceTimeout);
});
</script>