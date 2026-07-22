<template>
    <div class="relative max-w-lg w-full" v-click-away="closeSearch">
        <div class="relative">
            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                <MagnifyingGlassIcon class="h-5 w-5 text-gray-400 dark:text-gray-400" aria-hidden="true" />
            </div>
            <input
                ref="searchInput"
                v-model="query"
                type="text"
                class="block w-full pl-10 pr-3 py-2 border border-gray-300 rounded-md leading-5 bg-white placeholder-gray-500 focus:outline-none focus:placeholder-gray-400 focus:ring-1 focus:ring-blue-500 focus:border-blue-500 sm:text-sm transition-all duration-200 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400"
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
            class="absolute mt-1 w-full bg-white shadow-2xl rounded-md border border-gray-200 z-50 overflow-hidden max-h-[85vh] flex flex-col dark:bg-gray-800 dark:border-gray-700"
        >
            <!-- Tab Bar + Sort Control -->
            <div class="border-b border-gray-100 bg-white dark:bg-gray-800 dark:border-gray-700">
                <!-- Tabs -->
                <div class="flex overflow-x-auto scrollbar-none">
                    <button
                        v-for="tab in tabs"
                        :key="tab.key"
                        @click="switchTab(tab.key)"
                        class="flex-shrink-0 px-3 py-2.5 text-xs font-semibold whitespace-nowrap border-b-2 transition-colors"
                        :class="activeTab === tab.key
                            ? 'border-blue-500 text-blue-600'
                            : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                    >
                        {{ tab.label }}
                        <span
                            v-if="tabCount(tab.key) > 0"
                            class="ml-1 inline-flex items-center justify-center px-1.5 py-0.5 rounded-full text-[10px] font-bold"
                            :class="activeTab === tab.key ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-500'"
                        >{{ tabCount(tab.key) }}</span>
                    </button>
                </div>

                <!-- Sort Control (hidden for Navigation tab) -->
                <div v-if="activeTab !== 'navigation'" class="flex items-center justify-end px-3 py-1.5 border-t border-gray-50">
                    <span class="text-[10px] text-gray-400 mr-2 font-medium dark:text-gray-400">Sort:</span>
                    <div class="flex gap-1">
                        <button
                            v-for="s in sortOptions"
                            :key="s.value"
                            @click="switchSort(s.value)"
                            class="px-2 py-0.5 rounded text-[10px] font-semibold transition-colors"
                            :class="activeSort === s.value
                                ? 'bg-blue-100 text-blue-700'
                                : 'text-gray-400 hover:text-gray-600 hover:bg-gray-100'"
                        >{{ s.label }}</button>
                    </div>
                </div>
            </div>

            <!-- Results -->
            <div class="overflow-y-auto py-2 flex-1">

                <!-- Navigation -->
                <div v-if="showSection('navigation') && results.menus.length > 0">
                    <div class="px-4 py-2 text-xs font-semibold text-gray-500 uppercase tracking-wider bg-gray-50 dark:bg-gray-900/50 dark:text-gray-300">
                        Navigation
                    </div>
                    <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                        <li v-for="(menu, index) in results.menus" :key="'menu-' + index">
                            <Link
                                :href="menu.url"
                                class="flex items-center px-4 py-3 hover:bg-blue-50 transition-colors group"
                                :class="{ 'bg-blue-50': isSelected('menu', index) }"
                                @click.prevent="openSearchResult('menu', menu)"
                            >
                                <div class="flex-shrink-0 w-8 h-8 bg-blue-100 text-blue-600 rounded-md flex items-center justify-center">
                                    <LinkIcon class="w-4 h-4" />
                                </div>
                                <div class="ml-3">
                                    <p class="text-sm font-medium text-gray-900 group-hover:text-blue-600 dark:text-gray-100">{{ menu.name }}</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-300">{{ menu.path }}</p>
                                </div>
                            </Link>
                        </li>
                    </ul>
                </div>

                <!-- Tickets -->
                <div v-if="showSection('tickets') && results.tickets.length > 0">
                    <div class="px-4 py-2 text-xs font-semibold text-gray-500 uppercase tracking-wider bg-gray-50 border-t border-gray-100 dark:bg-gray-900/50 dark:text-gray-300 dark:border-gray-700">
                        Tickets
                    </div>
                    <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                        <li v-for="(ticket, index) in results.tickets" :key="'ticket-' + index">
                            <Link
                                :href="route('tickets.show', ticket.id)"
                                class="flex items-center px-4 py-3 hover:bg-blue-50 transition-colors group"
                                :class="{ 'bg-blue-50': isSelected('ticket', index) }"
                                @click.prevent="openSearchResult('ticket', ticket)"
                            >
                                <div class="flex-shrink-0 w-8 h-8 bg-orange-100 text-orange-600 rounded-md flex items-center justify-center">
                                    <TicketIcon class="w-4 h-4" />
                                </div>
                                <div class="ml-3 flex-1 min-w-0">
                                    <div class="flex items-center justify-between">
                                        <p class="text-sm font-medium text-gray-900 truncate group-hover:text-blue-600 dark:text-gray-100">
                                            <span class="text-blue-600 font-bold">[{{ ticket.ticket_key }}]</span> {{ ticket.title }}
                                        </p>
                                        <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium" :class="getStatusClass(ticket.status)">
                                            {{ ticket.status }}
                                        </span>
                                    </div>
                                    <p class="text-xs text-gray-500 truncate dark:text-gray-300">
                                        {{ ticket.company_name }} • Assigned to: {{ ticket.assignee_name }}
                                    </p>
                                </div>
                            </Link>
                        </li>
                    </ul>
                </div>

                <!-- Requests (POS + SAP merged) -->
                <div v-if="showSection('requests') && results.requests.length > 0">
                    <div class="px-4 py-2 text-xs font-semibold text-gray-500 uppercase tracking-wider bg-gray-50 border-t border-gray-100 dark:bg-gray-900/50 dark:text-gray-300 dark:border-gray-700">
                        Requests
                    </div>
                    <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                        <li v-for="(req, index) in results.requests" :key="'req-' + index">
                            <Link
                                :href="route(req.source === 'pos' ? 'pos-requests.show' : 'sap-requests.show', req.id)"
                                class="flex items-center px-4 py-3 hover:bg-blue-50 transition-colors group"
                                :class="{ 'bg-blue-50': isSelected('request', index) }"
                                @click.prevent="openSearchResult('request', req)"
                            >
                                <div class="flex-shrink-0 w-8 h-8 rounded-md flex items-center justify-center"
                                    :class="req.source === 'pos' ? 'bg-indigo-100 text-indigo-600' : 'bg-teal-100 text-teal-600'">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                                    </svg>
                                </div>
                                <div class="ml-3 flex-1 min-w-0">
                                    <div class="flex items-center justify-between">
                                        <p class="text-sm font-medium text-gray-900 truncate group-hover:text-blue-600 dark:text-gray-100">
                                            <span class="font-bold" :class="req.source === 'pos' ? 'text-indigo-600' : 'text-teal-600'">
                                                [{{ req.source === 'pos' ? 'POS' : 'SAP' }} #{{ req.id }}]
                                            </span> {{ req.request_type }}
                                        </p>
                                        <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium" :class="getStatusClass(req.status)">
                                            {{ req.status }}
                                        </span>
                                    </div>
                                    <p class="text-xs text-gray-500 truncate dark:text-gray-300">
                                        {{ req.company }} • {{ req.requester }}
                                        <span v-if="req.ticket_key" class="ml-1 font-bold text-blue-600">• {{ req.ticket_key }}</span>
                                    </p>
                                </div>
                            </Link>
                        </li>
                    </ul>
                </div>

                <!-- Forms (Request Types) -->
                <div v-if="showSection('forms') && results.forms.length > 0">
                    <div class="px-4 py-2 text-xs font-semibold text-gray-500 uppercase tracking-wider bg-gray-50 border-t border-gray-100 dark:bg-gray-900/50 dark:text-gray-300 dark:border-gray-700">
                        Forms
                    </div>
                    <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                        <li v-for="(form, index) in results.forms" :key="'form-' + index">
                            <Link
                                :href="getFormLink(form)"
                                class="flex items-center px-4 py-3 hover:bg-blue-50 transition-colors group"
                                :class="{ 'bg-blue-50': isSelected('form', index) }"
                                @click.prevent="openSearchResult('form', form)"
                            >
                                <div class="flex-shrink-0 w-8 h-8 bg-purple-100 text-purple-600 rounded-md flex items-center justify-center">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                    </svg>
                                </div>
                                <div class="ml-3 flex-1 min-w-0">
                                    <p class="text-sm font-medium text-gray-900 truncate group-hover:text-blue-600 dark:text-gray-100">{{ form.name }}</p>
                                    <p class="text-xs text-gray-500 flex items-center gap-1 dark:text-gray-300">
                                        <span class="font-mono text-gray-400 dark:text-gray-400">{{ form.code }}</span>
                                        <span v-for="rf in form.request_for" :key="rf"
                                            class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold"
                                            :class="rf === 'POS' ? 'bg-indigo-100 text-indigo-700' : rf === 'SAP' ? 'bg-teal-100 text-teal-700' : 'bg-gray-100 text-gray-600'">
                                            {{ rf }}
                                        </span>
                                    </p>
                                </div>
                            </Link>
                        </li>
                    </ul>
                </div>

                <!-- Projects -->
                <div v-if="showSection('projects') && results.projects.length > 0">
                    <div class="px-4 py-2 text-xs font-semibold text-gray-500 uppercase tracking-wider bg-gray-50 border-t border-gray-100 dark:bg-gray-900/50 dark:text-gray-300 dark:border-gray-700">
                        Projects
                    </div>
                    <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                        <li v-for="(project, index) in results.projects" :key="'project-' + index">
                            <Link
                                :href="route('projects.show', project.id)"
                                class="flex items-center px-4 py-3 hover:bg-green-50 transition-colors group"
                                :class="{ 'bg-green-50': isSelected('project', index) }"
                                @click.prevent="openSearchResult('project', project)"
                            >
                                <div class="flex-shrink-0 w-8 h-8 bg-green-100 text-green-600 rounded-md flex items-center justify-center">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/>
                                    </svg>
                                </div>
                                <div class="ml-3 flex-1 min-w-0">
                                    <div class="flex items-center justify-between">
                                        <p class="text-sm font-medium text-gray-900 truncate group-hover:text-green-600 dark:text-gray-100">{{ project.name }}</p>
                                        <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium" :class="getProjectStatusClass(project.status)">
                                            {{ project.status }}
                                        </span>
                                    </div>
                                    <p class="text-xs text-gray-500 truncate dark:text-gray-300">
                                        {{ project.store_name }}<span v-if="project.company_name"> • {{ project.company_name }}</span>
                                    </p>
                                </div>
                            </Link>
                        </li>
                    </ul>
                </div>

                <!-- Inventory (Assets + Stock Ins) -->
                <div v-if="showSection('inventory') && results.inventory.length > 0">
                    <div class="px-4 py-2 text-xs font-semibold text-gray-500 uppercase tracking-wider bg-gray-50 border-t border-gray-100 dark:bg-gray-900/50 dark:text-gray-300 dark:border-gray-700">
                        Inventory
                    </div>
                    <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                        <li v-for="(item, index) in results.inventory" :key="'inv-' + index">
                            <Link
                                :href="getInventoryLink(item)"
                                class="flex items-center px-4 py-3 hover:bg-orange-50 transition-colors group"
                                :class="{ 'bg-orange-50': isSelected('inventory', index) }"
                                @click.prevent="openSearchResult('inventory', item)"
                            >
                                <div class="flex-shrink-0 w-8 h-8 rounded-md flex items-center justify-center"
                                    :class="item.type === 'asset' ? 'bg-orange-100 text-orange-600' : 'bg-yellow-100 text-yellow-600'">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                                    </svg>
                                </div>
                                <div class="ml-3 flex-1 min-w-0">
                                    <p class="text-sm font-medium text-gray-900 truncate group-hover:text-orange-600 dark:text-gray-100">
                                        <span class="font-bold text-[10px] mr-1 px-1 py-0.5 rounded"
                                            :class="item.type === 'asset' ? 'bg-orange-100 text-orange-700' : 'bg-yellow-100 text-yellow-700'">
                                            {{ item.type === 'asset' ? 'ASSET' : 'STOCK' }}
                                        </span>
                                        <span v-if="item.type === 'asset'">{{ item.item_code }} — {{ item.label }}</span>
                                        <span v-else>{{ item.dr_no }} — {{ item.vendor }}</span>
                                    </p>
                                    <p class="text-xs text-gray-500 truncate dark:text-gray-300">
                                        <span v-if="item.type === 'asset'">{{ item.description }}</span>
                                        <span v-else>S/N: {{ item.serial_no || 'N/A' }} • {{ item.status }}</span>
                                        <span v-if="item.company_name"> • {{ item.company_name }}</span>
                                    </p>
                                </div>
                            </Link>
                        </li>
                    </ul>
                </div>

                <!-- Schedules -->
                <div v-if="showSection('schedules') && results.schedules.length > 0">
                    <div class="px-4 py-2 text-xs font-semibold text-gray-500 uppercase tracking-wider bg-gray-50 border-t border-gray-100 dark:bg-gray-900/50 dark:text-gray-300 dark:border-gray-700">
                        Schedules
                    </div>
                    <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                        <li v-for="(sched, index) in results.schedules" :key="'sched-' + index">
                            <Link
                                :href="route('schedules.index')"
                                class="flex items-center px-4 py-3 hover:bg-gray-50 transition-colors group dark:hover:bg-gray-700"
                                :class="{ 'bg-gray-50': isSelected('schedule', index) }"
                                @click.prevent="openSearchResult('schedule', sched)"
                            >
                                <div class="flex-shrink-0 w-8 h-8 bg-gray-100 text-gray-600 rounded-md flex items-center justify-center dark:bg-gray-800 dark:text-gray-300">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                    </svg>
                                </div>
                                <div class="ml-3 flex-1 min-w-0">
                                    <div class="flex items-center justify-between">
                                        <p class="text-sm font-medium text-gray-900 truncate group-hover:text-gray-600 dark:text-gray-100">{{ sched.user_name }}</p>
                                        <span v-if="sched.status" class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300">
                                            {{ sched.status }}
                                        </span>
                                    </div>
                                    <p class="text-xs text-gray-500 dark:text-gray-300">
                                        {{ formatDateRange(sched.start_time, sched.end_time) }}<span v-if="sched.company_name"> • {{ sched.company_name }}</span>
                                    </p>
                                </div>
                            </Link>
                        </li>
                    </ul>
                </div>

                <!-- Attendance -->
                <div v-if="showSection('attendance') && results.attendance.length > 0">
                    <div class="px-4 py-2 text-xs font-semibold text-gray-500 uppercase tracking-wider bg-gray-50 border-t border-gray-100 dark:bg-gray-900/50 dark:text-gray-300 dark:border-gray-700">
                        Attendance
                    </div>
                    <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                        <li v-for="(log, index) in results.attendance" :key="'att-' + index">
                            <Link
                                :href="route('attendance.index')"
                                class="flex items-center px-4 py-3 hover:bg-blue-50 transition-colors group"
                                :class="{ 'bg-blue-50': isSelected('attendance', index) }"
                                @click="closeSearch"
                            >
                                <div class="flex-shrink-0 w-8 h-8 bg-blue-100 text-blue-600 rounded-md flex items-center justify-center">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                    </svg>
                                </div>
                                <div class="ml-3 flex-1 min-w-0">
                                    <div class="flex items-center justify-between">
                                        <p class="text-sm font-medium text-gray-900 truncate group-hover:text-blue-600 dark:text-gray-100">{{ log.user_name }}</p>
                                        <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-700">
                                            {{ formatAttendanceType(log.type) }}
                                        </span>
                                    </div>
                                    <p class="text-xs text-gray-500 dark:text-gray-300">
                                        {{ log.log_time ? new Date(log.log_time).toLocaleString() : '' }}
                                        <span v-if="log.location_client" class="ml-1">• {{ log.location_client }}</span>
                                    </p>
                                </div>
                            </Link>
                        </li>
                    </ul>
                </div>

                <!-- Users -->
                <div v-if="showSection('users') && results.users.length > 0">
                    <div class="px-4 py-2 text-xs font-semibold text-gray-500 uppercase tracking-wider bg-gray-50 border-t border-gray-100 dark:bg-gray-900/50 dark:text-gray-300 dark:border-gray-700">
                        Users
                    </div>
                    <ul class="divide-y divide-gray-100 dark:divide-gray-700">
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
                                <div v-else class="flex-shrink-0 w-8 h-8 bg-gray-100 text-gray-600 rounded-full flex items-center justify-center dark:bg-gray-800 dark:text-gray-300">
                                    <UserIcon class="w-4 h-4" />
                                </div>
                                <div class="ml-3">
                                    <p class="text-sm font-medium text-gray-900 group-hover:text-blue-600 dark:text-gray-100">{{ user.name }}</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-300">{{ user.email }}</p>
                                </div>
                            </Link>
                        </li>
                    </ul>
                </div>

                <!-- No Results -->
                <div v-if="!loading && query.length >= 2 && totalResults === 0" class="px-4 py-8 text-center" role="status">
                    <MagnifyingGlassIcon class="mx-auto h-12 w-12 text-gray-400 dark:text-gray-400" />
                    <h3 class="mt-2 text-sm font-medium text-gray-900 dark:text-gray-100">No results found</h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-300">No matches found for "{{ query }}"</p>
                </div>

                <div v-if="query.length < 2 && query.length > 0" class="px-4 py-4 text-center text-sm text-gray-500 dark:text-gray-300">
                    Type at least 2 characters to search...
                </div>
            </div>

            <!-- Footer -->
            <div class="px-4 py-2 bg-gray-50 border-t border-gray-100 flex items-center justify-between text-[10px] text-gray-400 dark:bg-gray-900/50 dark:text-gray-400 dark:border-gray-700">
                <div class="flex items-center space-x-3">
                    <span><kbd class="px-1.5 py-0.5 border border-gray-300 rounded bg-white text-gray-600 font-sans dark:bg-gray-800 dark:text-gray-300 dark:border-gray-600">ESC</kbd> to close</span>
                    <span><kbd class="px-1.5 py-0.5 border border-gray-300 rounded bg-white text-gray-600 font-sans dark:bg-gray-800 dark:text-gray-300 dark:border-gray-600">↑↓</kbd> to navigate</span>
                    <span><kbd class="px-1.5 py-0.5 border border-gray-300 rounded bg-white text-gray-600 font-sans dark:bg-gray-800 dark:text-gray-300 dark:border-gray-600">ENTER</kbd> to select</span>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, watch, onMounted, onUnmounted, computed } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import {
    MagnifyingGlassIcon,
    TicketIcon,
    UserIcon,
    LinkIcon
} from '@heroicons/vue/24/outline';
import LoadingSpinner from '@/Components/LoadingSpinner.vue';

const query         = ref('');
const results       = ref({ menus: [], tickets: [], requests: [], users: [], forms: [], projects: [], inventory: [], schedules: [], attendance: [] });
const loading       = ref(false);
const isFocused     = ref(false);
const searchInput   = ref(null);
const selectedIndex = ref(-1);
const activeTab     = ref('all');
const activeSort    = ref('relevance');
const switchingResult = ref(false);
const page = usePage();
const activeCompanyId = computed(() => Number(page.props.activeCompany?.id || 0));
let debounceTimeout = null;

const tabs = [
    { key: 'all',        label: 'All'        },
    { key: 'tickets',    label: 'Tickets'    },
    { key: 'requests',   label: 'Requests'   },
    { key: 'forms',      label: 'Forms'      },
    { key: 'projects',   label: 'Projects'   },
    { key: 'inventory',  label: 'Inventory'  },
    { key: 'schedules',  label: 'Schedules'  },
    { key: 'attendance', label: 'Attendance' },
    { key: 'users',      label: 'Users'      },
    { key: 'navigation', label: 'Navigation' },
];

const sortOptions = [
    { value: 'relevance',     label: 'Relevance'      },
    { value: 'date_created',  label: 'Date Created'   },
    { value: 'last_modified', label: 'Last Modified'  },
];

// How many results does each tab have?
const tabCount = (key) => {
    if (key === 'all') return totalResults.value;
    if (key === 'navigation') return results.value.menus.length;
    if (key === 'requests')   return results.value.requests.length;
    return results.value[key]?.length ?? 0;
};

// Which sections to render (depends on active tab)
const showSection = (section) => {
    if (activeTab.value === 'all') return true;
    const map = {
        navigation: 'navigation',
        tickets:    'tickets',
        requests:   'requests',
        users:      'users',
        forms:      'forms',
        projects:   'projects',
        inventory:  'inventory',
        schedules:  'schedules',
        attendance: 'attendance',
    };
    return activeTab.value === map[section];
};

const totalResults = computed(() =>
    results.value.menus.length +
    results.value.tickets.length +
    results.value.requests.length +
    results.value.users.length +
    results.value.forms.length +
    results.value.projects.length +
    results.value.inventory.length +
    results.value.schedules.length +
    results.value.attendance.length
);

const showResults = computed(() =>
    isFocused.value && (query.value.length >= 2 || (query.value.length > 0 && loading.value))
);

const fetchResults = async () => {
    if (query.value.length < 2) {
        results.value = { menus: [], tickets: [], requests: [], users: [], forms: [], projects: [], inventory: [], schedules: [], attendance: [] };
        return;
    }
    loading.value = true;
    try {
        const response = await axios.get(route('global-search'), {
            params: { query: query.value, tab: activeTab.value, sort: activeSort.value }
        });
        results.value = response.data;
        selectedIndex.value = totalResults.value > 0 ? 0 : -1;
    } catch (error) {
        console.error('Search error:', error);
    } finally {
        loading.value = false;
    }
};

const handleInput = () => {
    if (debounceTimeout) clearTimeout(debounceTimeout);
    if (query.value.length < 2) {
        results.value = { menus: [], tickets: [], requests: [], users: [], forms: [], projects: [], inventory: [], schedules: [], attendance: [] };
        return;
    }
    debounceTimeout = setTimeout(fetchResults, 300);
};

const switchTab = (tab) => {
    activeTab.value = tab;
    selectedIndex.value = -1;
    if (query.value.length >= 2) {
        if (debounceTimeout) clearTimeout(debounceTimeout);
        fetchResults();
    }
};

const switchSort = (sort) => {
    activeSort.value = sort;
    selectedIndex.value = -1;
    if (query.value.length >= 2) {
        if (debounceTimeout) clearTimeout(debounceTimeout);
        fetchResults();
    }
};

const closeSearch = () => {
    isFocused.value = false;
    selectedIndex.value = -1;
};

const getStatusClass = (status) => {
    if (!status) return 'bg-gray-100 text-gray-800';
    switch (status.toLowerCase()) {
        case 'open':        return 'bg-blue-100 text-blue-800';
        case 'in progress': return 'bg-yellow-100 text-yellow-800';
        case 'resolved':    return 'bg-green-100 text-green-800';
        case 'closed':      return 'bg-gray-100 text-gray-800';
        case 'pending':     return 'bg-purple-100 text-purple-800';
        default:            return 'bg-gray-100 text-gray-800';
    }
};

const getProjectStatusClass = (status) => {
    if (!status) return 'bg-gray-100 text-gray-800';
    switch (status.toLowerCase()) {
        case 'planning':    return 'bg-blue-100 text-blue-800';
        case 'in progress': return 'bg-yellow-100 text-yellow-800';
        case 'completed':   return 'bg-green-100 text-green-800';
        case 'delayed':     return 'bg-red-100 text-red-800';
        default:            return 'bg-gray-100 text-gray-800';
    }
};

const getFormLink = (form) => {
    if (!form.request_for) return route('pos-requests.create');
    const types = Array.isArray(form.request_for) ? form.request_for : [form.request_for];
    if (types.includes('SAP') && !types.includes('POS')) return route('sap-requests.create');
    return route('pos-requests.create');
};

const getInventoryLink = (item) => {
    if (item.type === 'stock_in') return route('stock-ins.index');
    return route('assets.index');
};

const getResultUrl = (type, data) => {
    if (type === 'menu') return data.url;
    if (type === 'ticket') return route('tickets.show', data.id);
    if (type === 'request') return route(data.source === 'pos' ? 'pos-requests.show' : 'sap-requests.show', data.id);
    if (type === 'form') return getFormLink(data);
    if (type === 'project') return route('projects.show', data.id);
    if (type === 'inventory') return getInventoryLink(data);
    if (type === 'schedule') return route('schedules.index');
    if (type === 'attendance') return route('attendance.index');
    if (type === 'user') return route('users.index', { search: data.email });
    return null;
};

const openSearchResult = async (type, data) => {
    if (switchingResult.value) return;

    const targetUrl = getResultUrl(type, data);
    if (!targetUrl) return;

    const targetCompanyId = Number(data.company_id || 0);
    const requiresSwitch = targetCompanyId > 0 && targetCompanyId !== activeCompanyId.value;

    closeSearch();

    if (!requiresSwitch) {
        router.visit(targetUrl);
        return;
    }

    switchingResult.value = true;

    try {
        await axios.post(route('companies.switch'), { company_id: targetCompanyId });
        window.location.assign(targetUrl);
    } catch (error) {
        console.error('Unable to switch entity for the selected search result.', error);
        switchingResult.value = false;
    }
};

const formatDateRange = (start, end) => {
    if (!start) return '';
    const s = new Date(start).toLocaleDateString();
    const e = end ? new Date(end).toLocaleDateString() : null;
    return e && s !== e ? `${s} – ${e}` : s;
};

const formatAttendanceType = (type) => {
    if (!type) return '';
    return type.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
};

// Keyboard navigation — flat list of all visible results
const allResults = computed(() => {
    const list = [];
    if (showSection('navigation')) results.value.menus.forEach((item, index)      => list.push({ type: 'menu',       index, data: item }));
    if (showSection('tickets'))    results.value.tickets.forEach((item, index)     => list.push({ type: 'ticket',     index, data: item }));
    if (showSection('requests'))   results.value.requests.forEach((item, index)    => list.push({ type: 'request',    index, data: item }));
    if (showSection('forms'))      results.value.forms.forEach((item, index)       => list.push({ type: 'form',       index, data: item }));
    if (showSection('projects'))   results.value.projects.forEach((item, index)    => list.push({ type: 'project',    index, data: item }));
    if (showSection('inventory'))  results.value.inventory.forEach((item, index)   => list.push({ type: 'inventory',  index, data: item }));
    if (showSection('schedules'))  results.value.schedules.forEach((item, index)   => list.push({ type: 'schedule',   index, data: item }));
    if (showSection('attendance')) results.value.attendance.forEach((item, index)  => list.push({ type: 'attendance', index, data: item }));
    if (showSection('users'))      results.value.users.forEach((item, index)       => list.push({ type: 'user',       index, data: item }));
    return list;
});

const navigateResults = (direction) => {
    const total = allResults.value.length;
    if (total === 0) return;
    selectedIndex.value = (selectedIndex.value + direction + total) % total;
};

const isSelected = (type, index) => {
    const item = allResults.value[selectedIndex.value];
    return item && item.type === type && item.index === index;
};

const selectCurrentResult = () => {
    const item = allResults.value[selectedIndex.value];
    if (!item) return;
    openSearchResult(item.type, item.data);
};

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
