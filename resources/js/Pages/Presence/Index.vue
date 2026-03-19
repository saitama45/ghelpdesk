<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';
import { ref, onMounted, onUnmounted, watch, computed } from 'vue';
import axios from 'axios';
import UserStatus from '@/Components/UserStatus.vue';

const allUsers = ref([]);
const statsData = ref({
    first_login_today: null,
    idle_base_seconds: 0,
    current_idle_started_at: null,
    last_logout_at: null,
    status: 'offline'
});
const selectedUserId = ref(null);
const subUnitFilter = ref('All');
const pollingInterval = ref(null);
const timerInterval = ref(null);
const isRefreshing = ref(false);
const currentTime = ref(new Date());

const selectedUser = computed(() => allUsers.value.find(u => u.id === selectedUserId.value));

const fetchAllUsers = async () => {
    try {
        const response = await axios.get(route('presence.active-users'));
        allUsers.value = response.data;
    } catch (error) {
        console.error('Error fetching all users:', error);
    }
};

const fetchUserStats = async (userId) => {
    if (!userId) return;
    selectedUserId.value = userId;
    try {
        const response = await axios.get(route('presence.user-stats', userId));
        statsData.value = response.data;
    } catch (error) {
        console.error('Error fetching user stats:', error);
    }
};

const handleRefresh = async () => {
    isRefreshing.value = true;
    await fetchAllUsers();
    if (selectedUserId.value) {
        await fetchUserStats(selectedUserId.value);
    }
    setTimeout(() => {
        isRefreshing.value = false;
    }, 500);
};

const subUnitOptions = computed(() => {
    const units = [...new Set(allUsers.value.map(u => u.sub_unit || 'Unassigned'))];
    return ['All', ...units.sort()];
});

const groupedUsers = computed(() => {
    const groups = {};
    const filtered = subUnitFilter.value === 'All' 
        ? allUsers.value 
        : allUsers.value.filter(u => (u.sub_unit || 'Unassigned') === subUnitFilter.value);

    filtered.forEach(user => {
        const unit = user.sub_unit || 'Unassigned';
        if (!groups[unit]) groups[unit] = [];
        groups[unit].push(user);
    });
    
    return Object.keys(groups).sort().reduce((acc, key) => {
        acc[key] = groups[key].sort((a, b) => a.name.localeCompare(b.name));
        return acc;
    }, {});
});

/**
 * Logic: First login today until (Now OR Last Logout)
 */
const formatTimeOnline = (firstLogin, lastLogout, status) => {
    if (!firstLogin) return '0s';
    const start = new Date(firstLogin);
    const end = (status === 'offline' && lastLogout) ? new Date(lastLogout) : currentTime.value;
    let diff = Math.floor((end - start) / 1000);
    return convertSecondsToDetailed(diff > 0 ? diff : 0);
};

/**
 * Logic: Sum of finished idle sessions + (Now - current idle start)
 * Resets to 0s if status is 'online'.
 */
const formatTimeIdle = (stats, status) => {
    if (status === 'online') return '0s';
    
    let totalSeconds = stats.idle_base_seconds || 0;
    
    if (stats.current_idle_started_at) {
        const start = new Date(stats.current_idle_started_at);
        const end = currentTime.value;
        const currentSession = Math.floor((end - start) / 1000);
        if (currentSession > 0) totalSeconds += currentSession;
    }
    
    return convertSecondsToDetailed(totalSeconds);
};

/**
 * Logic: Last logout until Now (Resets when NOT offline)
 */
const formatTimeOffline = (lastLogout, status) => {
    if (status !== 'offline' || !lastLogout) return '0s';
    const start = new Date(lastLogout);
    const end = currentTime.value;
    let diff = Math.floor((end - start) / 1000);
    return convertSecondsToDetailed(diff > 0 ? diff : 0);
};

const convertSecondsToDetailed = (diff) => {
    const years = Math.floor(diff / (365 * 24 * 3600));
    diff %= (365 * 24 * 3600);
    const months = Math.floor(diff / (30 * 24 * 3600));
    diff %= (30 * 24 * 3600);
    const weeks = Math.floor(diff / (7 * 24 * 3600));
    diff %= (7 * 24 * 3600);
    const days = Math.floor(diff / (24 * 3600));
    diff %= (24 * 3600);
    const hours = Math.floor(diff / 3600);
    diff %= 3600;
    const minutes = Math.floor(diff / 60);
    const seconds = diff % 60;

    let parts = [];
    if (years > 0) parts.push(`${years}y`);
    if (months > 0) parts.push(`${months}mo`);
    if (weeks > 0) parts.push(`${weeks}w`);
    if (days > 0) parts.push(`${days}d`);
    if (hours > 0) parts.push(`${hours}h`);
    if (minutes > 0) parts.push(`${minutes}m`);
    parts.push(`${seconds}s`);

    return parts.join(' ');
};

const formatSimpleDuration = (seconds) => {
    if (!seconds || seconds < 0) return '0s';
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const s = seconds % 60;
    if (h > 0) return `${h}h ${m}m`;
    if (m > 0) return `${m}m ${s}s`;
    return `${s}s`;
};

onMounted(() => {
    fetchAllUsers();
    timerInterval.value = setInterval(() => {
        currentTime.value = new Date();
        
        // Update list counters for the directory sidebar
        allUsers.value.forEach(user => {
            if (user.status !== 'offline') {
                user.duration_current_status = (user.duration_current_status || 0) + 1;
            }
        });
    }, 1000);
    pollingInterval.value = setInterval(fetchAllUsers, 15000);
});

onUnmounted(() => {
    clearInterval(pollingInterval.value);
    clearInterval(timerInterval.value);
});

// Watch for status changes to ensure stats are accurate
watch(() => selectedUser.value?.status, (newStatus, oldStatus) => {
    if (newStatus && newStatus !== oldStatus) {
        fetchUserStats(selectedUserId.value);
    }
});
</script>

<template>
    <Head title="Real-time Presence" />

    <AppLayout>
        <template #header>
            Presence & Activity
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="grid grid-cols-1 gap-6 lg:grid-cols-4">
                    <!-- Directory Sidebar -->
                    <div class="lg:col-span-1 overflow-hidden bg-white shadow-sm sm:rounded-lg border border-gray-200 flex flex-col max-h-[calc(100vh-200px)]">
                        <div class="p-6 text-gray-900 border-b border-gray-100 flex-shrink-0">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="text-lg font-bold text-gray-700 uppercase tracking-wider text-xs">Directory</h3>
                                <button @click="handleRefresh" class="p-1.5 rounded-full text-blue-500 hover:bg-blue-50" :class="{'animate-spin': isRefreshing}">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                    </svg>
                                </button>
                            </div>
                            <div class="mb-2">
                                <label class="block text-[9px] font-black text-gray-400 uppercase mb-1 ml-1">Filter Sub-Unit</label>
                                <select v-model="subUnitFilter" class="block w-full border-gray-200 rounded-lg text-[11px] font-bold py-2 bg-gray-50">
                                    <option v-for="option in subUnitOptions" :key="option" :value="option">{{ option }}</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="flex-1 overflow-y-auto p-4 space-y-6 custom-scrollbar">
                            <div v-for="(users, unit) in groupedUsers" :key="unit" class="space-y-2">
                                <div class="flex items-center space-x-2 px-2">
                                    <span class="h-1.5 w-1.5 bg-blue-400 rounded-full"></span>
                                    <h4 class="text-[11px] font-black text-blue-900 uppercase tracking-widest opacity-60">{{ unit }}</h4>
                                </div>
                                <div class="space-y-1">
                                    <div v-for="user in users" :key="user.id" @click="fetchUserStats(user.id)"
                                        class="group flex cursor-pointer items-center justify-between p-2 rounded-lg border border-transparent transition-all"
                                        :class="selectedUserId === user.id ? 'bg-blue-600 text-white shadow-md' : 'hover:bg-gray-50'"
                                    >
                                        <div class="flex items-center min-w-0">
                                            <UserStatus :status="user.status" size="sm" class="mr-2" />
                                            <div class="truncate">
                                                <div class="text-xs font-bold" :class="selectedUserId === user.id ? 'text-white' : 'text-gray-700'">{{ user.name }}</div>
                                                <div class="text-[9px] uppercase font-black opacity-60">{{ user.status }}</div>
                                            </div>
                                        </div>
                                        <div v-if="user.status !== 'offline'" class="text-[9px] font-mono px-1.5 py-0.5 rounded" :class="selectedUserId === user.id ? 'bg-blue-700 text-white' : 'bg-gray-100 text-gray-500'">
                                            {{ formatSimpleDuration(user.duration_current_status) }}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Stats View -->
                    <div class="lg:col-span-3 overflow-hidden bg-white shadow-sm sm:rounded-lg border border-gray-200">
                        <div class="p-8 text-gray-900 h-full flex flex-col">
                            <div v-if="selectedUser" class="animate-in fade-in slide-in-from-right-4 duration-300">
                                <div class="flex items-center justify-between mb-10 pb-6 border-b border-gray-50">
                                    <div class="flex items-center">
                                        <UserStatus :status="selectedUser.status" size="xl" class="mr-5" />
                                        <div>
                                            <h3 class="text-3xl font-black text-gray-900 tracking-tight">{{ selectedUser.name }}</h3>
                                            <p class="text-gray-400 text-xs font-bold uppercase tracking-widest mt-1">
                                                {{ selectedUser.sub_unit }} &bull; First Seen Today: {{ statsData.first_login_today ? new Date(statsData.first_login_today).toLocaleTimeString() : 'N/A' }}
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-10">
                                    <!-- Time Online -->
                                    <div class="p-6 bg-gradient-to-br from-green-50 to-white border border-green-100 rounded-[2rem] shadow-sm">
                                        <div class="text-green-600 text-[9px] font-black uppercase tracking-widest mb-3 flex items-center">
                                            <span class="h-1.5 w-1.5 bg-green-500 rounded-full mr-2"></span>
                                            Time Online
                                        </div>
                                        <div class="text-2xl font-black text-gray-800 tabular-nums mb-1">
                                            {{ formatTimeOnline(statsData.first_login_today, statsData.last_logout_at, selectedUser.status) }}
                                        </div>
                                        <div class="text-[9px] text-gray-400 font-bold uppercase">From first login today</div>
                                    </div>
                                    
                                    <!-- Time Idle -->
                                    <div class="p-6 bg-gradient-to-br from-orange-50 to-white border border-orange-100 rounded-[2rem] shadow-sm">
                                        <div class="text-orange-600 text-[9px] font-black uppercase tracking-widest mb-3 flex items-center">
                                            <span class="h-1.5 w-1.5 bg-orange-500 rounded-full mr-2"></span>
                                            Time Idle
                                        </div>
                                        <div class="text-2xl font-black text-gray-800 tabular-nums mb-1">
                                            {{ formatTimeIdle(statsData, selectedUser.status) }}
                                        </div>
                                        <div class="text-[9px] text-gray-400 font-bold uppercase italic">Since last online status</div>
                                    </div>

                                    <!-- Time Offline -->
                                    <div class="p-6 bg-gradient-to-br from-gray-50 to-white border border-gray-100 rounded-[2rem] shadow-sm">
                                        <div class="text-gray-500 text-[9px] font-black uppercase tracking-widest mb-3 flex items-center">
                                            <span class="h-1.5 w-1.5 bg-gray-400 rounded-full mr-2"></span>
                                            Time Offline
                                        </div>
                                        <div class="text-2xl font-black text-gray-800 tabular-nums mb-1">{{ formatTimeOffline(statsData.last_logout_at, selectedUser.status) }}</div>
                                        <div class="text-[9px] text-gray-400 font-bold uppercase italic">Since last session ended</div>
                                    </div>
                                </div>

                                <div class="bg-blue-900 p-6 rounded-[1.5rem] shadow-xl text-blue-100">
                                    <div class="flex items-start">
                                        <div class="bg-blue-800 p-2 rounded-lg mr-4"><svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg></div>
                                        <div class="text-xs leading-relaxed font-medium">
                                            <strong>Time Online:</strong> Span from first login today. Clock stops at logout.
                                            <br><strong>Time Idle:</strong> Total idle duration since last Online status. Resets when returning to Online.
                                            <br><strong>Time Offline:</strong> Duration since last logout. Resets upon login.
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div v-else class="flex flex-1 flex-col items-center justify-center text-gray-300">
                                <div class="bg-gray-50 p-10 rounded-full border border-gray-100 mb-8"><svg xmlns="http://www.w3.org/2000/svg" class="h-20 w-24 opacity-20" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M15 15l-2 5L9 9l11 4-5 2zm0 0l5 5M7.188 2.239l.777 2.897M5.136 7.965l-2.898-.777M13.95 4.05l-2.122 2.122m-5.657 5.656l-2.12 2.122" /></svg></div>
                                <h3 class="text-xl font-black text-gray-900 tracking-widest uppercase">Select a User</h3>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.custom-scrollbar::-webkit-scrollbar { width: 3px; }
.custom-scrollbar::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 10px; }
</style>