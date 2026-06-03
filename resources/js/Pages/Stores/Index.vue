<template>
    <AppLayout title="Stores">
        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <DataTable
                    title="Store Management"
                    subtitle="Manage physical store locations and their details"
                    search-placeholder="Search stores by name, code, area..."
                    empty-message="No stores found. Create your first store to get started."
                    :search="pagination.search.value"
                    :data="pagination.data.value"
                    :current-page="pagination.currentPage.value"
                    :last-page="pagination.lastPage.value"
                    :per-page="pagination.perPage.value"
                    :showing-text="pagination.showingText.value"
                    :is-loading="pagination.isLoading.value"
                    @update:search="pagination.search.value = $event"
                    @go-to-page="pagination.goToPage"
                    @change-per-page="pagination.changePerPage"
                >
                    <template #actions>
                        <div class="flex items-center space-x-2">
                            <select
                                v-model="filterSector"
                                class="border-gray-300 rounded-lg shadow-sm text-sm focus:ring-blue-500 focus:border-blue-500 h-[38px] pl-3 pr-8"
                            >
                                <option value="">All Sectors</option>
                                <option v-for="n in 9" :key="n" :value="n - 1">Sector {{ n - 1 }}</option>
                            </select>
                            <button
                                @click="openImportModal"
                                class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors flex items-center space-x-2 shadow-sm whitespace-nowrap"
                            >
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                </svg>
                                <span>Import</span>
                            </button>
                            <button 
                                v-if="hasPermission('stores.create')"
                                @click="openCreateModal" 
                                class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors flex items-center space-x-2 shadow-sm whitespace-nowrap"
                            >
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                                </svg>
                                <span>Create Store</span>
                            </button>
                        </div>
                    </template>

                    <template #header>
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Store</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Classification</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Sector</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Assigned Team</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Geofencing</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </template>

                    <template #body="{ data }">
                        <tr v-for="store in data" :key="store.id" class="hover:bg-gray-50 transition-colors">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex items-center">
                                    <div class="h-10 w-10 bg-gradient-to-br from-blue-500 to-indigo-600 rounded-full flex items-center justify-center shadow-sm">
                                        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                                        </svg>
                                    </div>
                                    <div class="ml-4">
                                        <div class="text-sm font-medium text-gray-900">{{ store.name }}</div>
                                        <div class="text-xs text-gray-500 font-mono tracking-tighter">{{ store.code }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="space-y-1">
                                    <div class="flex items-center space-x-2">
                                        <span class="px-2 py-0.5 rounded text-[10px] font-black uppercase tracking-wider bg-blue-50 text-blue-700 border border-blue-100">
                                            {{ store.area }}
                                        </span>
                                        <span class="px-2 py-0.5 rounded text-[10px] font-black uppercase tracking-wider bg-indigo-50 text-indigo-700 border border-indigo-100">
                                            {{ store.brand }}
                                        </span>
                                    </div>
                                    <div class="flex flex-wrap gap-1">
                                        <span v-for="cluster in store.clusters" :key="cluster.id" class="px-2 py-0.5 rounded text-[9px] font-bold uppercase bg-purple-50 text-purple-700 border border-purple-100">
                                            {{ cluster.name }}
                                        </span>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="px-2 py-0.5 rounded text-[10px] font-black uppercase tracking-wider bg-gray-50 text-gray-700 border border-gray-200">
                                    Sector {{ store.sector }}
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <button v-if="store.users.length > 0" 
                                        @click="openTeamModal(store)"
                                        class="flex -space-x-2 overflow-hidden hover:opacity-80 transition-opacity focus:outline-none"
                                        title="View Assigned Team">
                                    <div v-for="user in store.users.slice(0, 3)" :key="user.id" 
                                         class="inline-block h-8 w-8 rounded-full ring-2 ring-white bg-blue-100 flex items-center justify-center text-xs font-bold text-blue-700"
                                         :title="user.name">
                                        {{ user.name.charAt(0) }}
                                    </div>
                                    <div v-if="store.users.length > 3"
                                         class="inline-block h-8 w-8 rounded-full ring-2 ring-white bg-gray-100 flex items-center justify-center text-xs font-bold text-gray-600">
                                        +{{ store.users.length - 3 }}
                                    </div>
                                </button>
                                <div v-else class="text-xs text-gray-400 italic">Unassigned</div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div v-if="store.latitude && store.longitude" class="flex flex-col">
                                    <span class="text-xs font-medium text-gray-900 flex items-center">
                                        <svg class="w-3 h-3 mr-1 text-green-500" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M5.05 4.05a7 7 0 119.9 9.9L10 18.9l-4.95-4.95a7 7 0 010-9.9zM10 11a2 2 0 100-4 2 2 0 000 4z" clip-rule="evenodd" />
                                        </svg>
                                        Active
                                    </span>
                                    <span class="text-[10px] text-gray-500">Radius: {{ store.radius_meters }}m</span>
                                </div>
                                <span v-else class="text-xs text-gray-400">Not set</span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <div class="flex justify-end space-x-1">
                                    <button 
                                        v-if="hasPermission('stores.edit')"
                                        @click="editStore(store)" 
                                        class="p-2 text-blue-600 hover:text-blue-900 hover:bg-blue-50 rounded-full transition-colors"
                                        title="Edit Store"
                                    >
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                        </svg>
                                    </button>
                                    <button 
                                        v-if="hasPermission('stores.delete')"
                                        @click="deleteStore(store)" 
                                        class="p-2 text-red-600 hover:text-red-900 hover:bg-red-50 rounded-full transition-colors"
                                        title="Delete Store"
                                    >
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                        </svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </template>
                </DataTable>
            </div>
        </div>

        <!-- Create/Edit Modal -->
        <div v-if="showModal" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex items-center justify-center min-h-screen px-4 py-6">
                <div class="fixed inset-0 bg-black/20 backdrop-blur-md" @click="closeModal"></div>
                <div class="relative bg-white rounded-xl shadow-2xl w-full max-w-3xl p-6 border border-gray-100 transform transition-all">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-xl font-bold text-gray-900">
                            {{ isEditing ? 'Edit Store' : 'Create Store' }}
                        </h3>
                        <button @click="closeModal" class="text-gray-400 hover:text-gray-600 transition-colors">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <!-- Tabs -->
                    <div class="flex flex-wrap gap-1 border-b border-gray-200 mb-5">
                        <button
                            v-for="tab in storeTabs"
                            :key="tab.key"
                            type="button"
                            @click="activeTab = tab.key"
                            class="px-3 py-2 text-xs font-bold rounded-t-lg transition-colors -mb-px border-b-2"
                            :class="activeTab === tab.key ? 'border-blue-600 text-blue-700' : 'border-transparent text-gray-500 hover:text-gray-700'"
                        >{{ tab.label }}</button>
                    </div>

                    <form @submit.prevent="submitForm" class="space-y-5">
                        <div class="max-h-[60vh] overflow-y-auto pr-1 custom-scrollbar">

                            <!-- ── General ── -->
                            <div v-show="activeTab === 'general'" class="grid grid-cols-1 md:grid-cols-2 gap-5">
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Store Code</label>
                                    <input v-model="form.code" type="text" required
                                           class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Store Name</label>
                                    <input v-model="form.name" type="text" required
                                           class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Brand</label>
                                    <input v-model="form.brand" type="text" required
                                           class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Area</label>
                                    <input v-model="form.area" type="text" required
                                           class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Sector</label>
                                    <input v-model="form.sector" type="number" required min="0" max="8"
                                           class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Class</label>
                                    <ManageableAutocomplete
                                        v-model="form.class"
                                        :options="classOptionsLocal"
                                        option-type="store_class"
                                        placeholder="Select class..."
                                        :can-create="canCreateOption"
                                        :can-edit="canEditOption"
                                        :can-delete="canDeleteOption"
                                        @options-changed="classOptionsLocal = $event"
                                    />
                                </div>
                                <div class="md:col-span-2">
                                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Clusters</label>
                                    <MultiAutocomplete
                                        v-model="form.cluster_ids"
                                        :options="clusters"
                                        label-key="name"
                                        value-key="id"
                                        placeholder="Select one or more clusters..."
                                    />
                                </div>
                                <div class="md:col-span-2">
                                    <div class="flex items-center justify-between mb-1">
                                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider">Assigned Team members</label>
                                        <button type="button" @click="toggleAllTeamMembers"
                                            class="text-[10px] font-black uppercase tracking-widest text-blue-600 hover:text-blue-700 transition-colors">
                                            {{ form.user_ids.length === props.users.length ? 'Deselect All' : 'Select All' }}
                                        </button>
                                    </div>
                                    <MultiAutocomplete
                                        v-model="form.user_ids"
                                        :options="users"
                                        label-key="name"
                                        value-key="id"
                                        placeholder="Select one or more team members..."
                                    />
                                </div>
                                <div class="md:col-span-2 flex items-center space-x-2">
                                    <input v-model="form.is_active" type="checkbox" id="is_active" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                    <label for="is_active" class="text-sm text-gray-700 font-medium">Active Store</label>
                                </div>
                            </div>

                            <!-- ── Contact ── -->
                            <div v-show="activeTab === 'contact'" class="grid grid-cols-1 md:grid-cols-2 gap-5">
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Contact Person (AOM)</label>
                                    <input v-model="form.contact_person" type="text" placeholder="Area Operations Manager name"
                                           class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Contact Details</label>
                                    <input v-model="form.contact_details" type="text" placeholder="Mobile / phone number"
                                           class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                                </div>
                                <div class="md:col-span-2">
                                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Email Address</label>
                                    <input v-model="form.email" type="email"
                                           class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                                </div>
                            </div>

                            <!-- ── Connectivity & Systems ── -->
                            <div v-show="activeTab === 'connectivity'" class="grid grid-cols-1 md:grid-cols-2 gap-5">
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Hookup</label>
                                    <ManageableAutocomplete
                                        v-model="form.hookup"
                                        :options="hookupOptionsLocal"
                                        option-type="store_hookup"
                                        placeholder="Select hookup..."
                                        :can-create="canCreateOption"
                                        :can-edit="canEditOption"
                                        :can-delete="canDeleteOption"
                                        @options-changed="hookupOptionsLocal = $event"
                                    />
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Telco</label>
                                    <ManageableMultiAutocomplete
                                        v-model="form.telcos"
                                        :options="telcoOptionsLocal"
                                        option-type="store_telco"
                                        placeholder="Select telco(s)..."
                                        :can-create="canCreateOption"
                                        :can-edit="canEditOption"
                                        :can-delete="canDeleteOption"
                                        @options-changed="telcoOptionsLocal = $event"
                                    />
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Connectivity Type</label>
                                    <ManageableMultiAutocomplete
                                        v-model="form.connectivity_types"
                                        :options="connectivityOptionsLocal"
                                        option-type="store_connectivity_type"
                                        placeholder="Select connectivity type(s)..."
                                        :can-create="canCreateOption"
                                        :can-edit="canEditOption"
                                        :can-delete="canDeleteOption"
                                        @options-changed="connectivityOptionsLocal = $event"
                                    />
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Systems Deployed</label>
                                    <ManageableMultiAutocomplete
                                        v-model="form.systems"
                                        :options="systemOptionsLocal"
                                        option-type="store_system"
                                        placeholder="Select system(s)..."
                                        :can-create="canCreateOption"
                                        :can-edit="canEditOption"
                                        :can-delete="canDeleteOption"
                                        @options-changed="systemOptionsLocal = $event"
                                    />
                                </div>
                                <div class="md:col-span-2">
                                    <div class="flex items-center justify-between mb-1">
                                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider">Remote Apps</label>
                                        <button type="button" @click="addRemoteApp"
                                            class="text-[10px] font-black uppercase tracking-widest text-blue-600 hover:text-blue-700 transition-colors">
                                            + Add Remote App
                                        </button>
                                    </div>
                                    <div v-if="form.remote_apps.length === 0" class="text-[11px] text-gray-400 italic bg-gray-50 rounded-lg p-2 border border-dashed border-gray-200">
                                        No remote apps. Add Teamviewer/Anydesk/etc. with their ID.
                                    </div>
                                    <div v-else class="space-y-2">
                                        <div v-for="(remote, idx) in form.remote_apps" :key="idx" class="flex items-start gap-2">
                                            <div class="w-2/5 shrink-0">
                                                <ManageableAutocomplete
                                                    v-model="remote.app"
                                                    :options="remoteAppOptionsLocal"
                                                    option-type="store_remote_app"
                                                    placeholder="App..."
                                                    :can-create="canCreateOption"
                                                    :can-edit="canEditOption"
                                                    :can-delete="canDeleteOption"
                                                    @options-changed="remoteAppOptionsLocal = $event"
                                                />
                                            </div>
                                            <input v-model="remote.id" type="text" placeholder="ID value"
                                                   class="flex-1 border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                                            <button type="button" @click="removeRemoteApp(idx)"
                                                class="p-2 text-red-500 hover:bg-red-50 rounded-lg shrink-0" title="Remove">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- ── Files & Location ── -->
                            <div v-show="activeTab === 'files'" class="space-y-5">
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                                    <div>
                                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Opening Date</label>
                                        <input v-model="form.opening_date" type="date"
                                               class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                                    </div>
                                </div>

                                <!-- Blueprint -->
                                <div class="p-4 bg-gray-50 rounded-xl border border-gray-100 space-y-3">
                                    <div class="flex items-center justify-between">
                                        <h4 class="text-xs font-black text-gray-400 uppercase tracking-widest">Blueprints (PDF / Image)</h4>
                                        <label class="inline-flex items-center space-x-1.5 text-[10px] font-black uppercase tracking-widest text-blue-600 hover:text-blue-700 cursor-pointer">
                                            <input ref="blueprintInput" type="file" multiple accept=".pdf,.jpg,.jpeg,.png,.webp" class="hidden" @change="handleBlueprintSelect">
                                            <span>+ Add Files</span>
                                        </label>
                                    </div>

                                    <!-- Existing (uploaded) -->
                                    <div v-if="existingBlueprints.length" class="space-y-1.5">
                                        <div v-for="bp in existingBlueprints" :key="bp.id" class="flex items-center justify-between gap-2 bg-white rounded-lg border border-gray-200 px-3 py-2">
                                            <a :href="blueprintDownloadUrl(bp)" target="_blank" rel="noopener noreferrer" class="text-xs font-bold text-blue-600 hover:underline truncate">
                                                {{ bp.file_name }}
                                            </a>
                                            <div class="flex items-center gap-2 shrink-0">
                                                <span class="text-[10px] text-gray-400">{{ formatBytes(bp.file_size_bytes) }}</span>
                                                <button type="button" @click="deleteExistingBlueprint(bp)" class="p-1 text-red-500 hover:bg-red-50 rounded" title="Delete">
                                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                                </button>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Staged (pending upload) -->
                                    <div v-if="stagedBlueprints.length" class="space-y-1.5">
                                        <div v-for="(file, idx) in stagedBlueprints" :key="idx" class="flex items-center justify-between gap-2 bg-blue-50/50 rounded-lg border border-blue-100 px-3 py-2">
                                            <span class="text-xs font-semibold text-gray-700 truncate">{{ file.name }}</span>
                                            <div class="flex items-center gap-2 shrink-0">
                                                <span class="text-[10px] text-gray-400">{{ formatBytes(file.size) }} · pending</span>
                                                <button type="button" @click="removeStagedBlueprint(idx)" class="p-1 text-red-500 hover:bg-red-50 rounded" title="Remove">
                                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                                </button>
                                            </div>
                                        </div>
                                    </div>

                                    <p v-if="!existingBlueprints.length && !stagedBlueprints.length" class="text-[11px] text-gray-400 italic">
                                        No blueprint files yet. Max 25MB per file.
                                    </p>
                                </div>

                                <!-- Geofencing -->
                                <div class="p-4 bg-gray-50 rounded-xl border border-gray-100 space-y-4">
                                    <div class="flex items-center justify-between">
                                        <h4 class="text-xs font-black text-gray-400 uppercase tracking-widest">Geofencing (Optional)</h4>
                                        <button type="button" @click="getCurrentLocation"
                                            class="inline-flex items-center space-x-1.5 text-[10px] font-black uppercase tracking-widest text-blue-600 hover:text-blue-700 transition-colors">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                                            </svg>
                                            <span>Get Current Location</span>
                                        </button>
                                    </div>
                                    <div class="grid grid-cols-2 gap-4">
                                        <div>
                                            <label class="block text-[10px] font-bold text-gray-500 uppercase mb-1">Latitude</label>
                                            <input v-model="form.latitude" type="number" step="any"
                                                   class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-xs">
                                        </div>
                                        <div>
                                            <label class="block text-[10px] font-bold text-gray-500 uppercase mb-1">Longitude</label>
                                            <input v-model="form.longitude" type="number" step="any"
                                                   class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-xs">
                                        </div>
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-bold text-gray-500 uppercase mb-1">Radius (Meters)</label>
                                        <input v-model="form.radius_meters" type="number"
                                               class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-xs"
                                               placeholder="Default: 150">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="flex justify-end space-x-3 pt-4 border-t">
                            <button type="button" @click="closeModal"
                                    class="px-4 py-2 text-sm font-semibold text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors">
                                Cancel
                            </button>
                            <button type="submit" :disabled="blueprintUploading"
                                    class="px-6 py-2 bg-blue-600 text-white text-sm font-bold rounded-lg hover:bg-blue-700 shadow-md transition-all disabled:opacity-60">
                                {{ blueprintUploading ? 'Saving...' : (isEditing ? 'Update Store' : 'Create Store') }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Assigned Team Modal -->
        <div v-if="showTeamModal" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex items-center justify-center min-h-screen px-4 py-6">
                <div class="fixed inset-0 bg-black/20 backdrop-blur-md" @click="showTeamModal = false"></div>
                <div class="relative bg-white rounded-xl shadow-2xl w-full max-w-md p-6 border border-gray-100 transform transition-all">
                    <div class="flex justify-between items-center mb-4">
                        <div>
                            <h3 class="text-xl font-bold text-gray-900">Assigned Team</h3>
                            <p class="text-xs text-gray-500 font-medium mt-1">{{ selectedTeamStore?.name }}</p>
                        </div>
                        <button @click="showTeamModal = false" class="text-gray-400 hover:text-gray-600 transition-colors">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <div class="max-h-[60vh] overflow-y-auto custom-scrollbar pr-2 space-y-3">
                        <div v-for="user in selectedTeamStore?.users" :key="user.id" class="flex items-center p-3 bg-gray-50 rounded-lg border border-gray-100">
                            <div class="h-10 w-10 rounded-full bg-blue-100 flex items-center justify-center text-sm font-bold text-blue-700 shrink-0">
                                {{ user.name.charAt(0) }}
                            </div>
                            <div class="ml-3 overflow-hidden">
                                <div class="text-sm font-bold text-gray-900 truncate">{{ user.name }}</div>
                                <div class="text-xs text-gray-500 truncate">{{ user.email }}</div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-6 flex justify-end">
                        <button type="button" @click="showTeamModal = false" 
                                class="px-4 py-2 text-sm font-semibold text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors">
                            Close
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Import Modal -->
        <div v-if="showImportModal" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex items-center justify-center min-h-screen px-4 py-6">
                <div class="fixed inset-0 bg-black/20 backdrop-blur-md" @click="showImportModal = false"></div>
                <div class="relative bg-white rounded-xl shadow-2xl w-full max-w-xl p-6 border border-gray-100 transform transition-all">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="text-xl font-bold text-gray-900">Import Stores</h3>
                        <button @click="showImportModal = false" class="text-gray-400 hover:text-gray-600 transition-colors">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <div class="space-y-6">
                        <div class="p-4 bg-blue-50 rounded-lg border border-blue-100">
                            <h4 class="text-xs font-bold text-blue-700 uppercase tracking-wider mb-2">Instructions</h4>
                            <ul class="text-xs text-blue-600 space-y-1 list-disc pl-4">
                                <li>Download the template to ensure correct column mapping.</li>
                                <li>The "cluster" column can contain multiple cluster names/codes separated by semicolon (;).</li>
                                <li>The "users" column should contain technician emails separated by semicolon (;).</li>
                                <li>If a store code already exists, it will be updated.</li>
                            </ul>
                            <div class="mt-4">
                                <a :href="route('stores.template')" 
                                   class="text-xs font-black text-blue-700 underline hover:text-blue-800">
                                    Download Excel Template
                                </a>
                            </div>
                        </div>

                        <div class="space-y-4">
                            <label class="block">
                                <span class="sr-only">Choose file</span>
                                <input type="file" @change="handleFileChange" accept=".xlsx,.csv"
                                       class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 transition-all cursor-pointer">
                            </label>

                            <div v-if="importResults" class="p-4 rounded-lg" :class="importResults.errors.length > 0 ? 'bg-amber-50' : 'bg-green-50'">
                                <p class="text-sm font-bold" :class="importResults.errors.length > 0 ? 'text-amber-800' : 'text-green-800'">
                                    Successfully imported {{ importResults.imported }} stores.
                                </p>
                                <div v-if="importResults.errors.length > 0" class="mt-2">
                                    <p class="text-xs font-black text-amber-700 uppercase mb-1">Issues encountered:</p>
                                    <ul class="text-[10px] text-amber-600 max-h-32 overflow-y-auto custom-scrollbar list-disc pl-4">
                                        <li v-for="(err, i) in importResults.errors" :key="i">{{ err }}</li>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        <div class="flex justify-end space-x-3 pt-6 border-t mt-6">
                            <button type="button" @click="showImportModal = false" 
                                    class="px-4 py-2 text-sm font-semibold text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors">
                                Close
                            </button>
                            <button @click="submitImport" :disabled="!selectedFile || importing"
                                    class="px-6 py-2 bg-emerald-600 text-white text-sm font-bold rounded-lg hover:bg-emerald-700 shadow-md transition-all disabled:opacity-50 flex items-center space-x-2">
                                <svg v-if="importing" class="animate-spin h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 6.477 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                <span>{{ importing ? 'Importing...' : 'Start Import' }}</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, reactive, onMounted, watch, computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import DataTable from '@/Components/DataTable.vue'
import MultiAutocomplete from '@/Components/MultiAutocomplete.vue'
import ManageableAutocomplete from '@/Components/ManageableAutocomplete.vue'
import ManageableMultiAutocomplete from '@/Components/ManageableMultiAutocomplete.vue'
import { useToast } from '@/Composables/useToast'
import { useConfirm } from '@/Composables/useConfirm'
import { useErrorHandler } from '@/Composables/useErrorHandler'
import { usePagination } from '@/Composables/usePagination'
import { usePermission } from '@/Composables/usePermission'
import axios from 'axios'

const props = defineProps({
    stores: Object,
    users: Array,
    clusters: Array,
    settings: Object,
    classOptions: { type: Array, default: () => [] },
    hookupOptions: { type: Array, default: () => [] },
    systemOptions: { type: Array, default: () => [] },
    telcoOptions: { type: Array, default: () => [] },
    connectivityOptions: { type: Array, default: () => [] },
    remoteAppOptions: { type: Array, default: () => [] },
})

const page = usePage()

const { showSuccess, showError } = useToast()
const { confirm } = useConfirm()
const { post, put, destroy } = useErrorHandler()
const { hasPermission } = usePermission()

const filterSector = ref('')
const pagination = usePagination(props.stores, 'stores.index', () => ({
    sector: filterSector.value || undefined,
}))

watch(filterSector, () => {
    pagination.currentPage.value = 1
    pagination.performSearch()
})

const showModal = ref(false)
const showImportModal = ref(false)
const isEditing = ref(false)
const currentStore = ref(null)
const importing = ref(false)
const selectedFile = ref(null)
const importResults = ref(null)

const form = reactive({
    code: '',
    name: '',
    brand: '',
    area: '',
    sector: 1,
    class: 'Regular',
    cluster_ids: [],
    user_ids: [],
    email: '',
    contact_person: '',
    contact_details: '',
    opening_date: '',
    hookup: '',
    systems: [],
    telcos: [],
    connectivity_types: [],
    remote_apps: [],
    latitude: '',
    longitude: '',
    radius_meters: '',
    is_active: true
})

// Modal tabs
const activeTab = ref('general')
const storeTabs = [
    { key: 'general', label: 'General' },
    { key: 'contact', label: 'Contact' },
    { key: 'connectivity', label: 'Connectivity & Systems' },
    { key: 'files', label: 'Files & Location' },
]

// Local copies of managed reference-option lists (kept in sync via @options-changed)
const classOptionsLocal = ref([...props.classOptions])
const hookupOptionsLocal = ref([...props.hookupOptions])
const systemOptionsLocal = ref([...props.systemOptions])
const telcoOptionsLocal = ref([...props.telcoOptions])
const connectivityOptionsLocal = ref([...props.connectivityOptions])
const remoteAppOptionsLocal = ref([...props.remoteAppOptions])

// Reference-option management permissions
const canCreateOption = computed(() => hasPermission('reference_options.create'))
const canEditOption = computed(() => hasPermission('reference_options.edit'))
const canDeleteOption = computed(() => hasPermission('reference_options.delete'))

// Blueprint state
const blueprintInput = ref(null)
const stagedBlueprints = ref([])      // newly selected files awaiting upload
const existingBlueprints = ref([])    // already-uploaded blueprints (edit mode)
const blueprintUploading = ref(false)

const showTeamModal = ref(false)
const selectedTeamStore = ref(null)

const openTeamModal = (store) => {
    selectedTeamStore.value = store
    showTeamModal.value = true
}

onMounted(() => {
    pagination.updateData(props.stores)
})

watch(() => props.stores, (newStores) => {
    pagination.updateData(newStores)
}, { deep: true })

const openCreateModal = () => {
    isEditing.value = false
    currentStore.value = null
    resetForm()
    activeTab.value = 'general'
    showModal.value = true
}

const groupStoreOptions = (options = [], type) =>
    options.filter(o => o.type === type).map(o => o.value)

const editStore = (store) => {
    isEditing.value = true
    currentStore.value = store
    form.code = store.code
    form.name = store.name
    form.brand = store.brand
    form.area = store.area
    form.sector = store.sector
    form.class = store.class || ''
    form.cluster_ids = store.clusters ? store.clusters.map(c => c.id) : []
    form.user_ids = store.users ? store.users.map(u => u.id) : []
    form.email = store.email || ''
    form.contact_person = store.contact_person || ''
    form.contact_details = store.contact_details || ''
    form.opening_date = store.opening_date || ''
    form.hookup = store.hookup || ''
    form.systems = groupStoreOptions(store.options, 'system')
    form.telcos = groupStoreOptions(store.options, 'telco')
    form.connectivity_types = groupStoreOptions(store.options, 'connectivity_type')
    form.remote_apps = (store.options || [])
        .filter(o => o.type === 'remote_app')
        .map(o => ({ app: o.value, id: o.meta || '' }))
    form.latitude = store.latitude || ''
    form.longitude = store.longitude || ''
    form.radius_meters = store.radius_meters || ''
    form.is_active = !!store.is_active

    existingBlueprints.value = store.blueprints ? [...store.blueprints] : []
    stagedBlueprints.value = []
    activeTab.value = 'general'
    showModal.value = true
}

const resetForm = () => {
    form.code = ''
    form.name = ''
    form.brand = ''
    form.area = ''
    form.sector = 1
    form.class = 'Regular'
    form.cluster_ids = []
    form.user_ids = []
    form.email = ''
    form.contact_person = ''
    form.contact_details = ''
    form.opening_date = ''
    form.hookup = ''
    form.systems = []
    form.telcos = []
    form.connectivity_types = []
    form.remote_apps = []
    form.latitude = ''
    form.longitude = ''
    form.radius_meters = ''
    form.is_active = true
    existingBlueprints.value = []
    stagedBlueprints.value = []
}

const closeModal = () => {
    showModal.value = false
}

// ── Remote apps (repeatable app + id rows) ──────────────────────────────
const addRemoteApp = () => {
    form.remote_apps.push({ app: '', id: '' })
}
const removeRemoteApp = (index) => {
    form.remote_apps.splice(index, 1)
}

// ── Blueprint files ─────────────────────────────────────────────────────
const handleBlueprintSelect = (e) => {
    const files = Array.from(e.target.files || [])
    const maxSize = 25 * 1024 * 1024 // 25MB
    files.forEach(file => {
        if (file.size > maxSize) {
            showError(`${file.name} exceeds the 25MB limit.`)
            return
        }
        stagedBlueprints.value.push(file)
    })
    if (blueprintInput.value) blueprintInput.value.value = ''
}

const removeStagedBlueprint = (index) => {
    stagedBlueprints.value.splice(index, 1)
}

const blueprintDownloadUrl = (bp) =>
    route('stores.blueprints.download', [currentStore.value.id, bp.id])

const uploadStagedBlueprints = async (storeId) => {
    if (!stagedBlueprints.value.length) return
    blueprintUploading.value = true
    const formData = new FormData()
    stagedBlueprints.value.forEach(file => formData.append('files[]', file))
    try {
        const { data } = await axios.post(route('stores.blueprints.store', storeId), formData, {
            headers: { 'Content-Type': 'multipart/form-data' }
        })
        existingBlueprints.value = data.blueprints || []
        stagedBlueprints.value = []
    } catch (err) {
        showError(err.response?.data?.message || 'Failed to upload blueprint(s).')
    } finally {
        blueprintUploading.value = false
    }
}

const deleteExistingBlueprint = async (bp) => {
    const confirmed = await confirm({
        title: 'Delete Blueprint',
        message: `Delete "${bp.file_name}"? This cannot be undone.`
    })
    if (!confirmed) return
    try {
        await axios.delete(route('stores.blueprints.destroy', [currentStore.value.id, bp.id]))
        existingBlueprints.value = existingBlueprints.value.filter(b => b.id !== bp.id)
        showSuccess('Blueprint deleted.')
    } catch (err) {
        showError(err.response?.data?.message || 'Failed to delete blueprint.')
    }
}

const formatBytes = (bytes) => {
    if (!bytes) return ''
    const k = 1024
    const sizes = ['B', 'KB', 'MB', 'GB']
    const i = Math.floor(Math.log(bytes) / Math.log(k))
    return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i]
}

const toggleAllTeamMembers = () => {
    if (form.user_ids.length === props.users.length) {
        form.user_ids = []
    } else {
        form.user_ids = props.users.map(u => u.id)
    }
}

const getCurrentLocation = () => {
    if (!navigator.geolocation) {
        showError('Geolocation is not supported by your browser');
        return;
    }

    navigator.geolocation.getCurrentPosition(
        (position) => {
            // Round to 8 decimal places to match DB precision (decimal 10,8 / 11,8)
            form.latitude = parseFloat(position.coords.latitude.toFixed(8));
            form.longitude = parseFloat(position.coords.longitude.toFixed(8));
            showSuccess('Coordinates updated from your current location');
        },
        (error) => {
            let msg = 'Failed to get location';
            if (error.code === error.PERMISSION_DENIED) msg = 'Location permission denied';
            showError(msg);
        }
    );
};

const submitForm = () => {
    const editing = isEditing.value
    const url = editing ? `/stores/${currentStore.value.id}` : '/stores'
    const requestMethod = editing ? put : post
    const hasStaged = stagedBlueprints.value.length > 0

    requestMethod(url, form, {
        preserveScroll: true,
        onSuccess: async () => {
            // Upload any staged blueprints to the (possibly newly created) store.
            if (hasStaged) {
                const storeId = editing
                    ? currentStore.value.id
                    : page.props.flash?.created_store_id
                if (storeId) {
                    await uploadStagedBlueprints(storeId)
                }
            }
            closeModal()
            showSuccess(editing ? 'Store updated successfully' : 'Store created successfully')
        },
        onError: (errors) => {
            const errorMessage = Object.values(errors).flat().join(', ') || 'An error occurred'
            showError(errorMessage)
        }
    })
}

const deleteStore = async (store) => {
    const confirmed = await confirm({
        title: 'Delete Store',
        message: `Are you sure you want to delete "${store.name}"? This action cannot be undone.`
    })

    if (confirmed) {
        destroy(`/stores/${store.id}`, {
            onSuccess: () => showSuccess('Store deleted successfully'),
            onError: (errors) => {
                const errorMessage = Object.values(errors).flat().join(', ') || 'Cannot delete store'
                showError(errorMessage)
            }
        })
    }
}

const openImportModal = () => {
    selectedFile.value = null
    importResults.value = null
    showImportModal.value = true
}

const handleFileChange = (e) => {
    selectedFile.value = e.target.files[0]
}

const submitImport = async () => {
    if (!selectedFile.value) return

    importing.value = true
    importResults.value = null

    const formData = new FormData()
    formData.append('file', selectedFile.value)

    try {
        const response = await axios.post(route('stores.import'), formData, {
            headers: { 'Content-Type': 'multipart/form-data' }
        })
        importResults.value = response.data
        if (response.data.imported > 0) {
            // Force reload stores to show new data
            post(route('stores.index'), {}, { preserveScroll: true, only: ['stores'] })
        }
    } catch (err) {
        showError(err.response?.data?.message || 'Import failed')
    } finally {
        importing.value = false
    }
}
</script>
