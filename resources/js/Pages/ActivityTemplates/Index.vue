<template>
    <AppLayout title="Project Templates" content-class="w-full max-w-none px-2 sm:px-4 lg:px-6">
        <div class="py-6">
            <DataTable
                    title="Project Activity Blueprints"
                    subtitle="Manage predefined activity sets for different project types and store classes"
                    search-placeholder="Search templates by name..."
                    empty-message="No templates found. Create your first project blueprint to get started."
                    :search="pagination.search.value"
                    :data="displayedTemplates"
                    :current-page="pagination.currentPage.value"
                    :last-page="pagination.lastPage.value"
                    :per-page="pagination.perPage.value"
                    :showing-text="templatesShowingText"
                    :is-loading="pagination.isLoading.value"
                    infinite-scroll
                    :has-more="hasMoreTemplates"
                    :loading-more="loadingMoreTemplates"
                    @update:search="pagination.search.value = $event"
                    @load-more="loadMoreTemplates"
                >
                    <template #actions>
                        <div class="flex flex-wrap items-center justify-end gap-3">
                            <div class="w-48" data-testid="project-type-filter">
                                <Autocomplete
                                    :model-value="selectedProjectType"
                                    :options="projectTypeFilterOptions"
                                    size="sm"
                                    placeholder="All project types"
                                    @update:model-value="filterByProjectType"
                                />
                            </div>
                            <nav class="flex flex-wrap p-1 bg-gray-100 rounded-lg gap-0.5 dark:bg-gray-800">
                                <button
                                    v-for="cls in localStoreClasses"
                                    :key="cls.value"
                                    @click="filterByClass(cls.value)"
                                    :class="[selectedClass === cls.value ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-500 hover:text-gray-700', 'px-4 py-1.5 rounded-md text-xs font-bold transition-all whitespace-nowrap']"
                                >
                                    {{ cls.label }}
                                </button>
                            </nav>
                            <button
                                v-if="hasPermission('activity_templates.create')"
                                @click="openImportModal"
                                class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors flex items-center space-x-2 shadow-sm whitespace-nowrap"
                            >
                                <ArrowUpTrayIcon class="w-4 h-4" />
                                <span>Import Excel</span>
                            </button>
                            <button 
                                v-if="hasPermission('activity_templates.create')"
                                @click="openCreateModal" 
                                class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors flex items-center space-x-2 shadow-sm whitespace-nowrap"
                            >
                                <PlusIcon class="w-4 h-4" />
                                <span>Create Template</span>
                            </button>
                        </div>
                    </template>

                    <template #header>
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-slate-300">Template Name</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-slate-300">Project Type</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-slate-300">Class</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-slate-300">Activities</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-slate-300">Actions</th>
                        </tr>
                    </template>

                    <template #body="{ data }">
                        <tr v-for="template in data" :key="template.id" class="hover:bg-gray-50 transition-colors dark:hover:bg-gray-700">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-bold text-gray-900 dark:text-gray-100">{{ template.name }}</div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="px-2.5 py-1 bg-purple-50 text-purple-700 text-[10px] font-black uppercase tracking-widest rounded-full border border-purple-100 dark:bg-purple-900/10 dark:text-purple-400 dark:border-purple-900/30">
                                    {{ template.project_type }}
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span v-if="template.store_class === 'Kitchen'" class="px-2.5 py-1 bg-amber-50 text-amber-700 border-amber-100 text-[10px] font-black uppercase tracking-widest rounded-lg border flex items-center w-fit dark:bg-amber-900/10 dark:text-amber-400 dark:border-amber-900/30">
                                    <BeakerIcon class="w-3 h-3 mr-1" />
                                    Kitchen
                                </span>
                                <span v-else-if="template.store_class === 'Both'" class="px-2.5 py-1 bg-blue-50 text-blue-700 border-blue-100 text-[10px] font-black uppercase tracking-widest rounded-lg border flex items-center w-fit dark:bg-blue-900/10 dark:text-blue-400 dark:border-blue-900/30">
                                    <ArrowsPointingOutIcon class="w-3 h-3 mr-1" />
                                    Both
                                </span>
                                <span v-else-if="template.store_class === 'Office'" class="px-2.5 py-1 bg-indigo-50 text-indigo-700 border-indigo-100 text-[10px] font-black uppercase tracking-widest rounded-lg border flex items-center w-fit dark:bg-indigo-900/10 dark:text-indigo-400 dark:border-indigo-900/30">
                                    <BuildingOfficeIcon class="w-3 h-3 mr-1" />
                                    Office
                                </span>
                                <span v-else-if="template.store_class === 'Department Store (DS)'" class="px-2.5 py-1 bg-rose-50 text-rose-700 border-rose-100 text-[10px] font-black uppercase tracking-widest rounded-lg border flex items-center w-fit dark:bg-rose-900/10 dark:text-rose-400 dark:border-rose-900/30">
                                    <BuildingOfficeIcon class="w-3 h-3 mr-1" />
                                    DS
                                </span>
                                <span v-else class="px-2.5 py-1 bg-slate-50 text-slate-600 border-slate-100 text-[10px] font-black uppercase tracking-widest rounded-lg border flex items-center w-fit dark:bg-slate-800 dark:text-slate-400 dark:border-slate-700">
                                    <DocumentTextIcon class="w-3 h-3 mr-1" />
                                    Regular
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-xs font-bold text-gray-500 dark:text-gray-300">{{ template.activities?.length || 0 }} rows</div>
                                <div v-if="templateSubTaskCount(template)" class="text-[10px] font-black uppercase tracking-wider text-blue-500">{{ templateSubTaskCount(template) }} sub-tasks</div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <div class="flex justify-end space-x-1">
                                    <a
                                        :href="route('activity-templates.export', template.id)"
                                        class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-50 px-3 py-2 text-xs font-bold text-emerald-700 transition-colors hover:bg-emerald-100 hover:text-emerald-900 dark:bg-emerald-900/20 dark:text-emerald-300 dark:hover:bg-emerald-900/40"
                                        title="Export Template"
                                    >
                                        <ArrowDownTrayIcon class="w-4 h-4" />
                                        <span>Export</span>
                                    </a>
                                    <button 
                                        v-if="hasPermission('activity_templates.edit')"
                                        @click="editTemplate(template)" 
                                        class="p-2 text-blue-600 hover:text-blue-900 hover:bg-blue-50 rounded-full transition-colors"
                                        title="Edit Template"
                                    >
                                        <PencilSquareIcon class="w-4 h-4" />
                                    </button>
                                    <button 
                                        v-if="hasPermission('activity_templates.delete')"
                                        @click="deleteTemplate(template)" 
                                        class="p-2 text-red-600 hover:text-red-900 hover:bg-red-50 rounded-full transition-colors"
                                        title="Delete Template"
                                    >
                                        <TrashIcon class="w-4 h-4" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </template>
            </DataTable>
        </div>

        <!-- Create/Edit Modal -->
        <Modal :show="showModal" @close="closeModal" maxWidth="6xl" :closeable="false">
            <div class="p-6">
                <div class="flex justify-between items-center mb-6 border-b pb-4">
                    <div>
                        <h3 class="text-xl font-bold text-gray-900 dark:text-gray-100">
                            {{ isEditing ? 'Edit Project Template' : 'Create Project Template' }}
                        </h3>
                        <p class="text-xs text-gray-500 mt-1 uppercase font-black tracking-widest dark:text-gray-300">Template Blueprint</p>
                    </div>
                    <button @click="closeModal" class="text-gray-400 hover:text-gray-600 transition-colors dark:text-gray-400">
                        <XMarkIcon class="w-6 h-6" />
                    </button>
                </div>

                <form @submit.prevent="submitForm" class="space-y-8">
                    <!-- Header Info -->
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 bg-gray-50 p-4 rounded-xl border border-gray-100 dark:bg-gray-900/50 dark:border-gray-700">
                        <div>
                            <InputLabel for="name" value="Template Name" />
                            <TextInput 
                                id="name" 
                                type="text" 
                                v-model="form.name" 
                                class="w-full mt-1" 
                                placeholder="e.g. Standard NSO Blueprint"
                                required
                            />
                            <InputError :message="form.errors.name" class="mt-1" />
                        </div>

                        <div>
                            <InputLabel for="project_type" value="Project Type" />
                            <ManageableAutocomplete
                                id="project_type"
                                v-model="form.project_type"
                                :options="localProjectTypes"
                                option-type="project_type"
                                placeholder="Select project type..."
                                class="mt-1"
                                :can-create="hasPermission('reference_options.create')"
                                :can-edit="hasPermission('reference_options.edit')"
                                :can-delete="hasPermission('reference_options.delete')"
                                @options-changed="localProjectTypes = $event"
                            />
                            <InputError :message="form.errors.project_type" class="mt-1" />
                        </div>

                        <div>
                            <InputLabel for="store_class" value="Store Class" />
                            <ManageableAutocomplete
                                id="store_class"
                                v-model="form.store_class"
                                :options="localStoreClasses"
                                option-type="store_class"
                                placeholder="Select store class..."
                                class="mt-1"
                                :can-create="hasPermission('reference_options.create')"
                                :can-edit="hasPermission('reference_options.edit')"
                                :can-delete="hasPermission('reference_options.delete')"
                                @options-changed="localStoreClasses = $event"
                            />
                            <InputError :message="form.errors.store_class" class="mt-1" />
                        </div>

                        <div>
                            <InputLabel for="entity_company_id" value="Entity (optional applicability)" />
                            <select id="entity_company_id" v-model="form.entity_company_id" class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                                <option value="">All entities</option>
                                <option v-for="entity in entities" :key="entity.id" :value="entity.id">{{ entity.code }} · {{ entity.name }}</option>
                            </select>
                        </div>

                        <div>
                            <InputLabel for="brand_company_id" value="Brand (optional applicability)" />
                            <select id="brand_company_id" v-model="form.brand_company_id" class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                                <option value="">No brand / all brands</option>
                                <option v-for="brand in availableBrands" :key="brand.id" :value="brand.id">{{ brand.code }} · {{ brand.name }}</option>
                            </select>
                        </div>

                        <div>
                            <InputLabel for="project_name" value="Project Name" />
                            <TextInput id="project_name" v-model="form.project_name" class="mt-1 w-full" placeholder="e.g. DAVID, LINK HUB" />
                            <p class="mt-1 text-[10px] text-gray-500">Plain text; this is not a Solution dropdown.</p>
                        </div>
                    </div>

                    <!-- Details Repeater -->
                    <div class="space-y-4">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <h4 class="text-sm font-black text-gray-900 uppercase tracking-widest dark:text-gray-100">Milestone Activities / Sub-tasks</h4>
                                <span class="inline-flex items-center gap-1.5 rounded-lg border border-blue-100 bg-blue-50 px-2.5 py-1 text-[10px] font-black uppercase tracking-wider text-blue-700 dark:border-blue-900/30 dark:bg-blue-900/20 dark:text-blue-300">
                                    Grand Total
                                    <span class="font-mono text-xs">{{ grandTotalLeadTimeDays }}</span>
                                    days
                                </span>
                            </div>
                            <button
                                type="button" 
                                @click="addMilestone"
                                class="inline-flex items-center px-3 py-1.5 bg-blue-50 text-blue-700 text-xs font-bold rounded-lg border border-blue-100 hover:bg-blue-100 transition-colors dark:bg-blue-900/20 dark:text-blue-400 dark:border-blue-900/30 dark:hover:bg-blue-900/40"
                            >
                                <PlusIcon class="w-3.5 h-3.5 mr-1.5" />
                                Add Milestone
                            </button>
                        </div>

                        <div class="space-y-4">
                            <div v-for="(activities, milestone, milestoneIndex) in milestoneGroups" :key="milestoneIndex"
                                 class="overflow-hidden border rounded-xl shadow-sm bg-white dark:bg-gray-800 transition-all"
                                 :class="{ 'opacity-40': drag.type === 'milestone' && drag.key === milestone, 'ring-2 ring-blue-400 ring-offset-1': drag.type === 'milestone' && drag.overKey === milestone && drag.key !== milestone }"
                                 @dragover.prevent="drag.type === 'milestone' && onDragOver(milestone)"
                                 @drop.prevent="drag.type === 'milestone' && onDropMilestone(milestone)">
                                <div class="flex flex-wrap items-center justify-between gap-3 bg-gray-50 border-b px-4 py-3 dark:bg-gray-900/50 dark:border-gray-700">
                                    <div class="flex items-center gap-3 min-w-0 flex-1">
                                        <!-- Milestone drag handle -->
                                        <div draggable="true"
                                             @dragstart.stop="onDragStartMilestone($event, milestone)"
                                             @dragend.stop="onDragEnd"
                                             class="cursor-grab active:cursor-grabbing text-gray-300 hover:text-gray-500 shrink-0 select-none"
                                             title="Drag to reorder milestone">
                                            <EllipsisVerticalIcon class="w-4 h-4" />
                                        </div>
                                        <input
                                            :value="milestone"
                                            type="text"
                                            @input="renameMilestone(milestone, $event.target.value)"
                                            @dragstart.stop
                                            class="w-full max-w-sm text-xs border-gray-200 rounded-lg p-1.5 font-black text-gray-700 uppercase tracking-widest focus:ring-blue-500 focus:border-blue-500 dark:text-gray-300 dark:border-gray-700 dark:bg-gray-900"
                                            placeholder="Milestone name"
                                        >
                                        <span class="px-2 py-0.5 bg-gray-200 text-gray-500 rounded text-[9px] font-black uppercase whitespace-nowrap dark:bg-gray-700 dark:text-gray-300">
                                            {{ activities.reduce((count, activity) => count + 1 + subTasksFor(activity).length, 0) }} rows
                                        </span>
                                        <span class="px-2 py-0.5 bg-blue-100 text-blue-700 rounded text-[9px] font-black uppercase whitespace-nowrap dark:bg-blue-900/30 dark:text-blue-300">
                                            {{ milestoneLeadTimeSum(activities) }} days
                                        </span>
                                    </div>
                                    <button
                                        type="button"
                                        @click="addActivity(milestone)"
                                        class="inline-flex items-center px-2.5 py-1 bg-white text-blue-700 text-[10px] font-black uppercase tracking-wider rounded-lg border border-blue-100 hover:bg-blue-50 transition-colors dark:bg-gray-800 dark:text-blue-400 dark:border-gray-700 dark:hover:bg-gray-700"
                                    >
                                        <PlusIcon class="w-3.5 h-3.5 mr-1" />
                                        Add Activity
                                    </button>
                                </div>

                                <div class="overflow-x-auto">
                                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                        <thead class="bg-white dark:bg-gray-800">
                                            <tr>
                                                <th class="px-1 py-2 w-6"></th>
                                                <th class="px-3 py-2 text-left text-[10px] font-black text-gray-500 uppercase tracking-wider w-16 dark:text-slate-300">Ord</th>
                                                <th class="px-3 py-2 text-left text-[10px] font-black text-gray-500 uppercase tracking-wider min-w-[220px] dark:text-slate-300">Activity / Sub-task</th>
                                                <th class="px-3 py-2 text-left text-[10px] font-black text-gray-500 uppercase tracking-wider min-w-[150px] dark:text-slate-300">Department</th>
                                                <th class="px-3 py-2 text-left text-[10px] font-black text-gray-500 uppercase tracking-wider min-w-[150px] dark:text-slate-300">Sub-Unit</th>
                                                <th class="px-3 py-2 text-left text-[10px] font-black text-gray-500 uppercase tracking-wider w-28 dark:text-slate-300">Lead Time Days</th>
                                                <th class="px-3 py-2 text-center text-[10px] font-black text-gray-500 uppercase tracking-wider w-20 dark:text-slate-300" title="Calculated. Day 1 when nothing comes before it; otherwise Dependency Finish + 1, or the Dependency's own Start when Can Run Parallel is Yes.">Start<span class="block font-bold normal-case tracking-normal text-gray-400">(days)</span></th>
                                                <th class="px-3 py-2 text-center text-[10px] font-black text-gray-500 uppercase tracking-wider w-20 dark:text-slate-300" title="Calculated: Finish = Start + Lead Time - 1. A 10-day row starting on Day 1 finishes on Day 10.">Finish<span class="block font-bold normal-case tracking-normal text-gray-400">(days)</span></th>
                                                <th class="px-3 py-2 text-left text-[10px] font-black text-gray-500 uppercase tracking-wider min-w-[190px] dark:text-slate-300">Dependency<span class="block font-bold normal-case tracking-normal text-gray-400">(requisite)</span></th>
                                                <th class="px-3 py-2 text-center text-[10px] font-black text-gray-500 uppercase tracking-wider w-28 dark:text-slate-300">Can Run<br>Parallel?</th>
                                                <th class="px-3 py-2 text-center text-[10px] font-black text-gray-500 uppercase tracking-wider w-24 dark:text-slate-300"></th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                            <template v-for="act in activities" :key="act.client_key">
                                                <tr class="group hover:bg-slate-50 transition-colors dark:hover:bg-gray-700/50"
                                                    :class="{ 'opacity-40': drag.key === act.client_key, 'border-t-2 border-blue-400': drag.type === 'activity' && drag.overKey === act.client_key && drag.key !== act.client_key }"
                                                    @dragover.prevent="drag.type === 'activity' && onDragOver(act.client_key)"
                                                    @drop.prevent="drag.type === 'activity' && onDropActivity(milestone, act.client_key)">
                                                    <td class="px-1 py-2 w-6">
                                                        <div draggable="true"
                                                             @dragstart="onDragStartActivity($event, act.client_key)"
                                                             @dragend="onDragEnd"
                                                             class="cursor-grab active:cursor-grabbing text-gray-200 hover:text-gray-500 flex justify-center select-none"
                                                             title="Drag to reorder">
                                                            <EllipsisVerticalIcon class="w-4 h-4" />
                                                        </div>
                                                    </td>
                                                    <td class="px-2 py-2">
                                                        <input v-model="act.order" type="number" min="1" step="0.1" @keydown.backspace="preventLastDigitBackspace" @input="ensureNumericValue(act, 'order', $event)" class="w-full text-xs border-gray-200 rounded p-1 font-mono font-bold text-gray-400 focus:ring-blue-500 focus:border-blue-500 dark:text-gray-400 dark:border-gray-700 dark:bg-gray-900">
                                                    </td>
                                                    <td class="px-2 py-2">
                                                        <input 
                                                            ref="activityInputs"
                                                            v-model="act.activity" 
                                                            type="text" 
                                                            class="w-full text-xs border-gray-200 rounded p-1 font-bold text-gray-800 placeholder-gray-300 focus:ring-blue-500 focus:border-blue-500 dark:text-gray-200 dark:border-gray-700 dark:bg-gray-900" 
                                                            placeholder="Activity name..." 
                                                            required
                                                            @input="syncSubTaskMilestone(act)"
                                                        >
                                                        <div class="mt-1 grid grid-cols-3 gap-1">
                                                            <select v-model="act.activity_mode" class="rounded border-gray-200 p-1 text-[10px] dark:border-gray-700 dark:bg-gray-900">
                                                                <option value="standard">Standard</option>
                                                                <option value="per_store">Per Store</option>
                                                            </select>
                                                            <input v-model="act.milestone_weight" type="number" min="0" max="100" step="0.01" class="rounded border-gray-200 p-1 text-[10px] dark:border-gray-700 dark:bg-gray-900" placeholder="Milestone %">
                                                            <input v-model="act.activity_weight" type="number" min="0" max="100" step="0.01" class="rounded border-gray-200 p-1 text-[10px] dark:border-gray-700 dark:bg-gray-900" placeholder="Activity %">
                                                        </div>
                                                        <textarea v-model="act.acceptance_criteria" rows="1" class="mt-1 w-full rounded border-gray-200 p-1 text-[10px] dark:border-gray-700 dark:bg-gray-900" placeholder="Acceptance criteria"></textarea>
                                                    </td>
                                                    <td class="px-2 py-2">
                                                        <select v-model="act.department" class="w-full text-xs border-gray-200 rounded p-1 text-gray-600 focus:ring-blue-500 focus:border-blue-500 dark:text-gray-300 dark:border-gray-700 dark:bg-gray-900" @change="handleActivityDepartmentChange(act)">
                                                            <option value="">None</option>
                                                            <option v-for="department in departmentOptions" :key="department.name" :value="department.name">{{ department.name }}</option>
                                                        </select>
                                                    </td>
                                                    <td class="px-2 py-2">
                                                        <select v-model="act.sub_unit" class="w-full text-xs border-gray-200 rounded p-1 text-gray-600 focus:ring-blue-500 focus:border-blue-500 dark:text-gray-300 dark:border-gray-700 dark:bg-gray-900" :disabled="!act.department" @change="syncSubTaskOrganization(act)">
                                                            <option value="">None</option>
                                                            <option v-for="subUnit in subUnitsForDepartment(act.department)" :key="subUnit" :value="subUnit">{{ subUnit }}</option>
                                                        </select>
                                                    </td>
                                                    <td class="px-2 py-2">
                                                        <input v-model="act.default_duration_days" type="number" min="1" :disabled="subTasksFor(act).length > 0" @keydown.backspace="preventLastDigitBackspace" @input="ensureNumericValue(act, 'default_duration_days', $event)" class="w-full text-xs border-gray-200 rounded p-1 text-gray-600 focus:ring-blue-500 focus:border-blue-500 disabled:bg-gray-50 disabled:text-gray-400 dark:text-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:disabled:bg-gray-800">
                                                        <span v-if="subTasksFor(act).length" class="block mt-0.5 text-[9px] font-black text-blue-400 uppercase tracking-wider" :title="`Calculated: sum of the ${subTasksFor(act).length} sub-task lead times`">Sum of sub-tasks</span>
                                                    </td>
                                                    <td class="px-2 py-2 text-center">
                                                        <span class="text-xs font-mono font-bold" :class="offsetFor(act).start === 1 ? 'text-red-600 dark:text-red-400' : 'text-gray-500 dark:text-gray-400'">
                                                            {{ offsetFor(act).start === 1 ? 'Day 1' : offsetFor(act).start }}
                                                        </span>
                                                    </td>
                                                    <td class="px-2 py-2 text-center">
                                                        <span class="text-xs font-mono font-bold text-gray-500 dark:text-gray-400">{{ offsetFor(act).finish }}</span>
                                                    </td>
                                                    <td class="px-2 py-2">
                                                        <Autocomplete
                                                            :model-value="act.depends_on_client_key"
                                                            @update:model-value="value => act.depends_on_client_key = value"
                                                            :options="requisiteOptionsFor(act)"
                                                            size="sm"
                                                            placeholder="Previous row"
                                                        />
                                                    </td>
                                                    <td class="px-2 py-2">
                                                        <button type="button" @click="act.can_run_parallel = !act.can_run_parallel"
                                                                :title="act.can_run_parallel ? 'Yes — starts on the SAME day its dependency starts, running alongside it' : 'No — starts the day after its dependency finishes'"
                                                                class="w-full text-[10px] font-black uppercase tracking-wider rounded px-2 py-1 border transition-colors"
                                                                :class="act.can_run_parallel
                                                                    ? 'bg-emerald-50 text-emerald-700 border-emerald-200 hover:bg-emerald-100 dark:bg-emerald-900/30 dark:text-emerald-300 dark:border-emerald-800'
                                                                    : 'bg-gray-50 text-gray-500 border-gray-200 hover:bg-gray-100 dark:bg-gray-800 dark:text-gray-400 dark:border-gray-700'">
                                                            {{ act.can_run_parallel ? 'Yes' : 'No' }}
                                                        </button>
                                                    </td>
                                                    <td class="px-2 py-2">
                                                        <div class="flex justify-center gap-1">
                                                            <button
                                                                type="button"
                                                                @click="addSubActivity(act)"
                                                                class="text-blue-400 hover:text-blue-700 transition-colors p-1"
                                                                title="Add Sub-task"
                                                            >
                                                                <PlusIcon class="w-4 h-4" />
                                                            </button>
                                                            <button 
                                                                v-if="form.activities.length > 1"
                                                                type="button" 
                                                                @click="removeActivity(act)"
                                                                class="text-gray-300 hover:text-red-500 transition-colors p-1"
                                                                title="Delete Activity"
                                                            >
                                                                <TrashIcon class="w-4 h-4" />
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>

                                                <tr v-for="subTask in subTasksFor(act)" :key="subTask.client_key"
                                                    class="group bg-slate-50/70 hover:bg-slate-100 transition-colors dark:bg-gray-800/70 dark:hover:bg-gray-700"
                                                    :class="{ 'opacity-40': drag.key === subTask.client_key, 'border-t-2 border-blue-400': drag.type === 'subtask' && drag.overKey === subTask.client_key && drag.key !== subTask.client_key }"
                                                    @dragover.prevent.stop="drag.type === 'subtask' && onDragOver(subTask.client_key)"
                                                    @drop.prevent.stop="onDropSubTask(act.client_key, subTask.client_key)">
                                                    <td class="px-1 py-2 w-6">
                                                        <div draggable="true"
                                                             @dragstart="onDragStartSubTask($event, subTask.client_key)"
                                                             @dragend="onDragEnd"
                                                             class="cursor-grab active:cursor-grabbing text-gray-200 hover:text-gray-500 flex justify-center select-none"
                                                             title="Drag to reorder">
                                                            <EllipsisVerticalIcon class="w-4 h-4" />
                                                        </div>
                                                    </td>
                                                    <td class="px-2 py-2">
                                                        <input v-model="subTask.order" type="number" min="1" step="0.1" @keydown.backspace="preventLastDigitBackspace" @input="ensureNumericValue(subTask, 'order', $event)" class="w-full text-xs border-gray-200 rounded p-1 font-mono font-bold text-gray-400 focus:ring-blue-500 focus:border-blue-500 dark:text-gray-400 dark:border-gray-700 dark:bg-gray-900">
                                                    </td>
                                                    <td class="px-2 py-2">
                                                        <div class="flex items-center gap-2 pl-6">
                                                            <span class="text-[9px] font-black text-gray-400 uppercase tracking-widest dark:text-gray-400">Sub</span>
                                                            <input 
                                                                ref="activityInputs"
                                                                v-model="subTask.activity" 
                                                                type="text" 
                                                                class="w-full text-xs border-gray-200 rounded p-1 font-bold text-gray-700 placeholder-gray-300 focus:ring-blue-500 focus:border-blue-500 dark:text-gray-300 dark:border-gray-700 dark:bg-gray-900" 
                                                                placeholder="Sub-task name..." 
                                                                required
                                                            >
                                                        </div>
                                                        <div class="mt-1 grid grid-cols-[7rem_1fr] gap-1 pl-6">
                                                            <input v-model="subTask.sub_task_weight" type="number" min="0" max="100" step="0.01" class="rounded border-gray-200 p-1 text-[10px] dark:border-gray-700 dark:bg-gray-900" placeholder="Sub-task %">
                                                            <textarea v-model="subTask.acceptance_criteria" rows="1" class="rounded border-gray-200 p-1 text-[10px] dark:border-gray-700 dark:bg-gray-900" placeholder="Acceptance criteria"></textarea>
                                                        </div>
                                                    </td>
                                                    <td class="px-2 py-2">
                                                        <select v-model="subTask.department"
                                                                class="w-full text-xs border-gray-200 rounded p-1 text-gray-600 focus:ring-blue-500 focus:border-blue-500 dark:text-gray-300 dark:border-gray-700 dark:bg-gray-900"
                                                                @change="handleSubTaskDepartmentChange(subTask)">
                                                            <option value="">None</option>
                                                            <option v-for="department in departmentOptions" :key="department.name" :value="department.name">{{ department.name }}</option>
                                                        </select>
                                                    </td>
                                                    <td class="px-2 py-2">
                                                        <select v-model="subTask.sub_unit"
                                                                class="w-full text-xs border-gray-200 rounded p-1 text-gray-600 focus:ring-blue-500 focus:border-blue-500 dark:text-gray-300 dark:border-gray-700 dark:bg-gray-900"
                                                                :disabled="!subTask.department">
                                                            <option value="">None</option>
                                                            <option v-for="subUnit in subUnitsForDepartment(subTask.department)" :key="subUnit" :value="subUnit">{{ subUnit }}</option>
                                                        </select>
                                                    </td>
                                                    <td class="px-2 py-2">
                                                        <input v-model="subTask.default_duration_days" type="number" min="1" @keydown.backspace="preventLastDigitBackspace" @input="ensureNumericValue(subTask, 'default_duration_days', $event)" class="w-full text-xs border-gray-200 rounded p-1 text-gray-600 focus:ring-blue-500 focus:border-blue-500 dark:text-gray-300 dark:border-gray-700 dark:bg-gray-900">
                                                    </td>
                                                    <td class="px-2 py-2 text-center">
                                                        <span class="text-xs font-mono text-gray-400">{{ offsetFor(subTask).start }}</span>
                                                    </td>
                                                    <td class="px-2 py-2 text-center">
                                                        <span class="text-xs font-mono text-gray-400">{{ offsetFor(subTask).finish }}</span>
                                                    </td>
                                                    <td class="px-2 py-2">
                                                        <Autocomplete
                                                            :model-value="subTask.depends_on_client_key"
                                                            @update:model-value="value => subTask.depends_on_client_key = value"
                                                            :options="requisiteOptionsFor(subTask)"
                                                            size="sm"
                                                            placeholder="Previous sub-task"
                                                        />
                                                    </td>
                                                    <td class="px-2 py-2">
                                                        <button type="button" @click="subTask.can_run_parallel = !subTask.can_run_parallel"
                                                                :title="subTask.can_run_parallel ? 'Yes — starts on the SAME day its dependency starts, running alongside it' : 'No — starts the day after its dependency finishes'"
                                                                class="w-full text-[10px] font-black uppercase tracking-wider rounded px-2 py-1 border transition-colors"
                                                                :class="subTask.can_run_parallel
                                                                    ? 'bg-emerald-50 text-emerald-700 border-emerald-200 hover:bg-emerald-100 dark:bg-emerald-900/30 dark:text-emerald-300 dark:border-emerald-800'
                                                                    : 'bg-gray-50 text-gray-500 border-gray-200 hover:bg-gray-100 dark:bg-gray-800 dark:text-gray-400 dark:border-gray-700'">
                                                            {{ subTask.can_run_parallel ? 'Yes' : 'No' }}
                                                        </button>
                                                    </td>
                                                    <td class="px-2 py-2">
                                                        <div class="flex justify-center">
                                                            <button 
                                                                v-if="form.activities.length > 1"
                                                                type="button" 
                                                                @click="removeActivity(subTask)"
                                                                class="text-gray-300 hover:text-red-500 transition-colors p-1"
                                                                title="Delete Sub-task"
                                                            >
                                                                <TrashIcon class="w-4 h-4" />
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            </template>
                                        </tbody>
                                        <tfoot class="bg-gray-50 dark:bg-gray-900/50">
                                            <tr>
                                                <td colspan="5" class="px-3 py-2 text-right text-[10px] font-black text-gray-500 uppercase tracking-wider dark:text-slate-300">
                                                    Milestone Total
                                                </td>
                                                <td class="px-2 py-2">
                                                    <span class="block text-xs font-black text-blue-700 dark:text-blue-300">{{ milestoneLeadTimeSum(activities) }} days</span>
                                                </td>
                                                <td colspan="2" class="px-2 py-2 text-center text-[10px] font-black text-blue-700 dark:text-blue-300">
                                                    Day {{ milestoneSpan(activities).start }}–{{ milestoneSpan(activities).finish }}
                                                </td>
                                                <td colspan="3"></td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div v-if="form.errors.activities" class="text-sm text-red-600">{{ form.errors.activities }}</div>
                    </div>

                    <div class="flex justify-end pt-6 border-t mt-6">
                        <PrimaryButton type="submit" :disabled="form.processing" class="bg-blue-600 hover:bg-blue-700">
                            {{ isEditing ? 'Update Template' : 'Create Template' }}
                        </PrimaryButton>
                    </div>
                </form>
            </div>

            <!-- Scroll-to-top: fixed corner, always visible while modal is open -->
            <button type="button" @click="scrollModalTop"
                    title="Scroll to top"
                    class="fixed bottom-6 right-6 z-[9999] flex items-center justify-center w-10 h-10 rounded-full bg-blue-600 hover:bg-blue-700 text-white shadow-lg transition-colors">
                <ChevronUpIcon class="w-5 h-5" />
            </button>
        </Modal>

        <Modal :show="showImportModal" @close="closeImportModal" maxWidth="2xl">
            <div class="p-6">
                <div class="flex items-start justify-between border-b border-gray-200 pb-4 dark:border-gray-700">
                    <div>
                        <h3 class="text-xl font-bold text-gray-900 dark:text-gray-100">Import Activity Templates</h3>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-300">Create multiple project templates from one Excel workbook.</p>
                    </div>
                    <button type="button" @click="closeImportModal" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
                        <XMarkIcon class="h-6 w-6" />
                    </button>
                </div>

                <div class="mt-6 space-y-5">
                    <div class="rounded-lg border border-blue-100 bg-blue-50 p-4 dark:border-blue-900 dark:bg-blue-950/40">
                        <h4 class="text-xs font-bold uppercase tracking-wider text-blue-700 dark:text-blue-300">Instructions</h4>
                        <ul class="mt-2 list-disc space-y-1 pl-5 text-xs text-blue-700 dark:text-blue-300">
                            <li>Download and use the Excel template so the column mapping remains valid.</li>
                            <li>Repeat the template details on each activity row and use row keys to link sub-tasks.</li>
                            <li>Existing templates with the same name, project type, and store class will be skipped.</li>
                            <li>Valid template groups import even when another group contains errors.</li>
                        </ul>
                        <a
                            :href="route('activity-templates.template')"
                            class="mt-4 inline-flex items-center gap-2 text-xs font-black text-blue-700 underline hover:text-blue-800 dark:text-blue-300"
                        >
                            <ArrowDownTrayIcon class="h-4 w-4" />
                            Download Excel Template
                        </a>
                    </div>

                    <label class="block">
                        <span class="sr-only">Choose Excel file</span>
                        <input
                            ref="importFileInput"
                            type="file"
                            accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                            @change="handleImportFileChange"
                            class="block w-full cursor-pointer text-sm text-gray-500 file:mr-4 file:rounded-full file:border-0 file:bg-blue-50 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-blue-700 hover:file:bg-blue-100 dark:text-gray-300"
                        >
                    </label>

                    <div
                        v-if="importResults"
                        class="rounded-lg p-4"
                        :class="importResults.errors.length ? 'bg-amber-50 dark:bg-amber-950/30' : 'bg-green-50 dark:bg-green-950/30'"
                    >
                        <p class="text-sm font-semibold text-gray-800 dark:text-gray-100">
                            Imported {{ importResults.imported_templates }} template(s); skipped {{ importResults.skipped_templates }} template(s).
                        </p>
                        <div v-if="importResults.errors.length" class="mt-3">
                            <p class="text-xs font-black uppercase text-amber-700 dark:text-amber-300">Import details</p>
                            <ul class="mt-1 max-h-48 list-disc space-y-1 overflow-y-auto pl-5 text-xs text-amber-700 dark:text-amber-300">
                                <li v-for="(error, index) in importResults.errors" :key="index">{{ error }}</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <div class="mt-6 flex justify-end gap-3 border-t border-gray-200 pt-5 dark:border-gray-700">
                    <button type="button" @click="closeImportModal" class="rounded-lg bg-gray-100 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600">
                        Close
                    </button>
                    <button
                        type="button"
                        @click="submitImport"
                        :disabled="!selectedImportFile || importing"
                        class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-5 py-2 text-sm font-bold text-white shadow-sm hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        <span v-if="importing" class="h-4 w-4 animate-spin rounded-full border-2 border-white border-t-transparent"></span>
                        <ArrowUpTrayIcon v-else class="h-4 w-4" />
                        {{ importing ? 'Importing...' : 'Start Import' }}
                    </button>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>

<script setup>
import { ref, reactive, computed, onMounted, watch, nextTick } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import DataTable from '@/Components/DataTable.vue'
import Modal from '@/Components/Modal.vue'
import TextInput from '@/Components/TextInput.vue'
import InputLabel from '@/Components/InputLabel.vue'
import InputError from '@/Components/InputError.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import ManageableAutocomplete from '@/Components/ManageableAutocomplete.vue'
import Autocomplete from '@/Components/Autocomplete.vue'
import { useToast } from '@/Composables/useToast'
import { useConfirm } from '@/Composables/useConfirm'
import { usePagination } from '@/Composables/usePagination'
import { usePermission } from '@/Composables/usePermission'
import {
    PlusIcon,
    TrashIcon,
    PencilSquareIcon,
    XMarkIcon,
    ClockIcon,
    DocumentTextIcon,
    BeakerIcon,
    ArrowsPointingOutIcon,
    BuildingOfficeIcon,
    EllipsisVerticalIcon,
    ChevronUpIcon,
    ArrowUpTrayIcon,
    ArrowDownTrayIcon,
} from '@heroicons/vue/24/outline'

const props = defineProps({
    templates: Object,
    subUnits: Array,
    departmentOptions: Array,
    projectTypes: Array,
    storeClasses: Array,
    entities: { type: Array, default: () => [] },
    brands: { type: Array, default: () => [] },
    filters: Object
})

const localProjectTypes = ref([...(props.projectTypes || [])])
const localStoreClasses = ref([...(props.storeClasses || [])])
const entities = computed(() => props.entities || [])
const availableBrands = computed(() => {
    if (!form.entity_company_id) return props.brands || []
    return (props.brands || []).filter(brand => (brand.entities || []).some(entity => Number(entity.id) === Number(form.entity_company_id)))
})

const { showSuccess, showError } = useToast()
const { confirm } = useConfirm()
const { hasPermission } = usePermission()

const selectedClass = ref(props.filters.store_class || 'Regular')
const selectedProjectType = ref(props.filters.project_type || '')

// Every list request (search, filter change, "load more") must carry the same
// filter set, otherwise a search would silently drop the active tab/type.
const templateFilterParams = () => {
    const params = { store_class: selectedClass.value }
    if (selectedProjectType.value) params.project_type = selectedProjectType.value
    return params
}

const pagination = usePagination(props.templates, 'activity-templates.index', templateFilterParams)
// usePagination defaults to 10; the server paginates 15. Keep them in step so
// the "load more" page numbers line up with the rows already on screen.
pagination.perPage.value = props.templates?.per_page || 15

const projectTypeFilterOptions = computed(() => [
    { value: '', label: 'All project types' },
    ...localProjectTypes.value.map(type => ({ value: type.value, label: type.label })),
])

// --- Infinite scroll accumulation ---
// Rows accumulate client-side across pages: the watcher on props.templates
// replaces the buffer whenever a first page arrives (filter/search change) and
// appends, deduped, for any subsequent "load more" page.
const accumulatedTemplates = ref([...(props.templates?.data || [])])
const templatesMeta = ref({
    current_page: props.templates?.current_page || 1,
    last_page: props.templates?.last_page || 1,
    total: props.templates?.total || 0,
})
const loadingMoreTemplates = ref(false)

const mergeTemplatePage = (payload) => {
    if (!payload) return
    const incoming = payload.data || []
    if ((payload.current_page || 1) <= 1) {
        accumulatedTemplates.value = [...incoming]
    } else {
        const incomingById = new Map(incoming.map(t => [t.id, t]))
        const seen = new Set(accumulatedTemplates.value.map(t => t.id))
        accumulatedTemplates.value = [
            ...accumulatedTemplates.value.map(t => incomingById.get(t.id) || t),
            ...incoming.filter(t => !seen.has(t.id)),
        ]
    }
    templatesMeta.value = {
        current_page: payload.current_page || 1,
        last_page: payload.last_page || 1,
        total: payload.total || 0,
    }
}

const displayedTemplates = computed(() => accumulatedTemplates.value)

const hasMoreTemplates = computed(
    () => templatesMeta.value.current_page < templatesMeta.value.last_page
)

const templatesShowingText = computed(() => {
    const total = templatesMeta.value.total || 0
    if (total === 0) return 'No records found'
    return `Showing ${accumulatedTemplates.value.length} of ${total} records`
})

const loadMoreTemplates = () => {
    if (loadingMoreTemplates.value || !hasMoreTemplates.value) return
    loadingMoreTemplates.value = true
    router.reload({
        only: ['templates'],
        data: {
            ...templateFilterParams(),
            search: pagination.search.value,
            per_page: pagination.perPage.value,
            page: templatesMeta.value.current_page + 1,
        },
        preserveScroll: true,
        preserveState: true,
        onFinish: () => {
            loadingMoreTemplates.value = false
        },
    })
}

watch(() => props.templates, (newTemplates) => {
    pagination.updateData(newTemplates)
    mergeTemplatePage(newTemplates)
}, { deep: true })

/* ---- Drag-to-sort ---- */
const drag = reactive({ type: null, key: null, overKey: null })

const onDragOver = (key) => { drag.overKey = key }
const onDragEnd = () => { drag.type = null; drag.key = null; drag.overKey = null }

const onDragStartMilestone = (e, milestone) => {
    e.dataTransfer.effectAllowed = 'move'
    drag.type = 'milestone'
    drag.key = milestone
}
const onDragStartActivity = (e, key) => {
    e.dataTransfer.effectAllowed = 'move'
    drag.type = 'activity'
    drag.key = key
}
const onDragStartSubTask = (e, key) => {
    e.dataTransfer.effectAllowed = 'move'
    drag.type = 'subtask'
    drag.key = key
}

const onDropMilestone = (targetMilestone) => {
    if (!drag.key || drag.key === targetMilestone || drag.type !== 'milestone') { onDragEnd(); return }
    const milestones = Object.keys(milestoneGroups.value)
    const fromIdx = milestones.indexOf(drag.key)
    const toIdx = milestones.indexOf(targetMilestone)
    if (fromIdx === -1 || toIdx === -1) { onDragEnd(); return }
    milestones.splice(fromIdx, 1)
    milestones.splice(toIdx, 0, drag.key)
    milestones.forEach((ms, i) => {
        form.activities.forEach(a => {
            if ((a.milestone || 'General') === ms) a.milestone_order = i + 1
        })
    })
    onDragEnd()
}

const onDropActivity = (milestone, targetKey) => {
    if (!drag.key || drag.key === targetKey || drag.type !== 'activity') { onDragEnd(); return }
    const fromAct = form.activities.find(a => a.client_key === drag.key)
    if (!fromAct) { onDragEnd(); return }

    // Gather the activity and all its sub-tasks as a unit
    const subKeys = new Set(form.activities.filter(a => a.parent_client_key === fromAct.client_key).map(a => a.client_key))
    const allKeys = new Set([fromAct.client_key, ...subKeys])
    const draggedItems = form.activities.filter(a => allKeys.has(a.client_key))

    // Build new array without the dragged unit, then insert before target
    const newArr = form.activities.filter(a => !allKeys.has(a.client_key))
    const insertIdx = newArr.findIndex(a => a.client_key === targetKey)
    if (insertIdx === -1) { onDragEnd(); return }
    newArr.splice(insertIdx, 0, ...draggedItems)

    // Apply back — this drives the visual order (milestoneGroups iterates form.activities in order)
    form.activities.splice(0, form.activities.length, ...newArr)

    // Recalculate order values within the milestone
    newArr.filter(a => !a.parent_client_key && (a.milestone || 'General') === milestone)
          .forEach((a, i) => { a.order = i + 1 })

    onDragEnd()
}

const onDropSubTask = (parentKey, targetKey) => {
    if (!drag.key || drag.key === targetKey || drag.type !== 'subtask') { onDragEnd(); return }
    const subs = form.activities
        .filter(a => a.parent_client_key === parentKey)
        .sort((a, b) => (a.order || 0) - (b.order || 0))
    const fromIdx = subs.findIndex(a => a.client_key === drag.key)
    const toIdx = subs.findIndex(a => a.client_key === targetKey)
    if (fromIdx === -1 || toIdx === -1) { onDragEnd(); return }
    const [moved] = subs.splice(fromIdx, 1)
    subs.splice(toIdx, 0, moved)
    subs.forEach((a, i) => { a.order = i + 1 })
    onDragEnd()
}

/* ---- Modal scroll-to-top — targets scroll-region inside the <dialog> element ---- */
const scrollModalTop = () => {
    const el = document.querySelector('dialog [scroll-region]')
    if (el) el.scrollTop = 0
}

const applyFilters = () => {
    pagination.currentPage.value = 1
    router.get(route('activity-templates.index'), {
        ...templateFilterParams(),
        search: pagination.search.value,
        per_page: pagination.perPage.value,
        page: 1
    }, {
        preserveState: true,
        replace: true
    })
}

const filterByClass = (className) => {
    selectedClass.value = className
    applyFilters()
}

const filterByProjectType = (projectType) => {
    selectedProjectType.value = projectType || ''
    applyFilters()
}

const showModal = ref(false)
const showImportModal = ref(false)
const isEditing = ref(false)
const currentTemplate = ref(null)
const activityInputs = ref([])
const importFileInput = ref(null)
const selectedImportFile = ref(null)
const importing = ref(false)
const importResults = ref(null)
let clientKeySequence = 1


const makeClientKey = () => `activity-${Date.now()}-${clientKeySequence++}`

const createActivityRow = (overrides = {}) => ({
    id: null,
    client_key: makeClientKey(),
    parent_client_key: null,
    activity: '',
    milestone: 'General',
    milestone_order: 1,
    asset_item: '',
    model_specs: '',
    qty: 1,
    responsible: null,
    department: '',
    sub_unit: '',
    default_duration_days: 1,
    depends_on_client_key: null,
    can_run_parallel: false,
    activity_mode: 'standard',
    milestone_weight: null,
    activity_weight: null,
    sub_task_weight: null,
    acceptance_criteria: '',
    order: 1,
    ...overrides
})

const form = useForm({
    name: '',
    project_type: 'NSO',
    store_class: 'Regular',
    entity_company_id: '',
    brand_company_id: '',
    project_name: '',
    activities: [
        createActivityRow()
    ]
})

onMounted(() => {
    pagination.updateData(props.templates)
})

watch(() => props.templates, (newTemplates) => {
    pagination.updateData(newTemplates)
}, { deep: true })

const openCreateModal = () => {
    isEditing.value = false
    currentTemplate.value = null
    form.reset()
    form.store_class = selectedClass.value
    form.activities = [createActivityRow()]

    showModal.value = true
}

const openImportModal = () => {
    selectedImportFile.value = null
    importResults.value = null
    if (importFileInput.value) importFileInput.value.value = ''
    showImportModal.value = true
}

const closeImportModal = () => {
    if (importing.value) return
    showImportModal.value = false
    selectedImportFile.value = null
    if (importFileInput.value) importFileInput.value.value = ''
}

const handleImportFileChange = (event) => {
    selectedImportFile.value = event.target.files?.[0] || null
    importResults.value = null
}

const submitImport = async () => {
    if (!selectedImportFile.value || importing.value) return

    importing.value = true
    importResults.value = null
    const payload = new FormData()
    payload.append('file', selectedImportFile.value)

    try {
        const { data } = await window.axios.post(route('activity-templates.import'), payload, {
            headers: { 'Content-Type': 'multipart/form-data' }
        })
        importResults.value = data

        if (data.imported_templates > 0) {
            showSuccess(`Imported ${data.imported_templates} activity template(s) successfully`)
            applyFilters()
        }
    } catch (error) {
        const validationMessage = error.response?.data?.errors?.file?.[0]
        showError(validationMessage || error.response?.data?.message || 'Activity template import failed')
    } finally {
        importing.value = false
    }
}

const editTemplate = (template) => {
    isEditing.value = true
    currentTemplate.value = template
    form.name = template.name
    form.project_type = template.project_type || 'NSO'
    form.store_class = template.store_class
    form.entity_company_id = template.entity_company_id || ''
    form.brand_company_id = template.brand_company_id || ''
    form.project_name = template.project_name || ''
    form.activities = normalizeTemplateActivities(template.activities || [])
    if (form.activities.length === 0) {
        form.activities = [createActivityRow()]
    }
    showModal.value = true
}

const closeModal = () => {
    showModal.value = false
    form.reset()
    form.activities = [createActivityRow()]
}

const normalizeTemplateActivities = (activities) => {
    const keyById = new Map()
    const milestoneOrderByName = new Map()
    let nextMilestoneOrder = 1

    activities.forEach(activity => {
        keyById.set(activity.id, makeClientKey())
    })

    return [...activities]
        .sort((a, b) => {
            const aIsSubTask = a.parent_activity_template_id ? 1 : 0
            const bIsSubTask = b.parent_activity_template_id ? 1 : 0
            const aMilestoneOrder = Number.isFinite(Number(a.milestone_order)) ? Number(a.milestone_order) : Number.MAX_SAFE_INTEGER
            const bMilestoneOrder = Number.isFinite(Number(b.milestone_order)) ? Number(b.milestone_order) : Number.MAX_SAFE_INTEGER

            if (aMilestoneOrder !== bMilestoneOrder) return aMilestoneOrder - bMilestoneOrder
            if (aIsSubTask !== bIsSubTask) return aIsSubTask - bIsSubTask
            return (Number(a.order) || 0) - (Number(b.order) || 0)
        })
        .map(activity => {
            const milestone = activity.milestone || 'General'
            if (!milestoneOrderByName.has(milestone)) {
                const explicitOrder = Number(activity.milestone_order)
                milestoneOrderByName.set(milestone, Number.isFinite(explicitOrder) ? explicitOrder : nextMilestoneOrder)
                nextMilestoneOrder = Math.max(nextMilestoneOrder, milestoneOrderByName.get(milestone) + 1)
            }

            return createActivityRow({
                id: activity.id,
                client_key: keyById.get(activity.id),
                parent_client_key: activity.parent_activity_template_id ? keyById.get(activity.parent_activity_template_id) : null,
                activity: activity.activity,
                milestone,
                milestone_order: milestoneOrderByName.get(milestone),
                asset_item: activity.asset_item,
                model_specs: activity.model_specs,
                qty: activity.qty,
                responsible: activity.responsible,
                department: activity.department || '',
                sub_unit: activity.sub_unit || '',
                default_duration_days: activity.default_duration_days,
                depends_on_client_key: activity.depends_on_template_id ? (keyById.get(activity.depends_on_template_id) || null) : null,
                can_run_parallel: Boolean(activity.can_run_parallel),
                activity_mode: activity.activity_mode || 'standard',
                milestone_weight: activity.milestone_weight,
                activity_weight: activity.activity_weight,
                sub_task_weight: activity.sub_task_weight,
                acceptance_criteria: activity.acceptance_criteria || '',
                order: activity.order
            })
        })
}

const departmentOptions = computed(() => props.departmentOptions || [])

const subUnitsForDepartment = (departmentName) => {
    return departmentOptions.value.find(department => department.name === departmentName)?.sub_units || []
}

const syncSubTaskOrganization = (parentActivity) => {
    form.activities.forEach(activity => {
        if (activity.parent_client_key === parentActivity.client_key) {
            activity.department = parentActivity.department || ''
            activity.sub_unit = parentActivity.sub_unit || ''
        }
    })
}

const handleActivityDepartmentChange = (activity) => {
    if (!subUnitsForDepartment(activity.department).includes(activity.sub_unit)) {
        activity.sub_unit = ''
    }

    syncSubTaskOrganization(activity)
}

const milestoneGroups = computed(() => {
    const groups = {}

    form.activities.forEach(activity => {
        if (activity.parent_client_key) return

        const milestone = activity.milestone || 'General'
        if (!groups[milestone]) groups[milestone] = []
        groups[milestone].push(activity)
    })

    const sorted = Object.entries(groups).sort(([, a], [, b]) => {
        const aMilestoneOrder = Math.min(...a.map(act => Number.isFinite(Number(act.milestone_order)) ? Number(act.milestone_order) : Number.MAX_SAFE_INTEGER))
        const bMilestoneOrder = Math.min(...b.map(act => Number.isFinite(Number(act.milestone_order)) ? Number(act.milestone_order) : Number.MAX_SAFE_INTEGER))
        if (aMilestoneOrder !== bMilestoneOrder) return aMilestoneOrder - bMilestoneOrder

        const aMin = Math.min(...a.map(act => Number(act.order) || 0))
        const bMin = Math.min(...b.map(act => Number(act.order) || 0))
        return aMin - bMin
    })

    return Object.fromEntries(sorted)
})

const templateSubTaskCount = (template) => {
    return (template.activities || []).filter(activity => activity.parent_activity_template_id).length
}

const subTasksFor = (activity) => {
    return form.activities
        .filter(candidate => candidate.parent_client_key === activity.client_key)
        .sort((a, b) => (Number(a.order) || 0) - (Number(b.order) || 0))
}

const nextOrderFor = (milestone, parentClientKey = null) => {
    const siblings = form.activities.filter(activity => {
        if ((activity.parent_client_key || null) !== (parentClientKey || null)) return false
        if (parentClientKey) return true

        return (activity.milestone || 'General') === (milestone || 'General')
    })

    if (!siblings.length) return 1

    return Math.max(...siblings.map(activity => Number(activity.order) || 0)) + 1
}

const milestoneOrderFor = (milestone) => {
    const normalizedMilestone = milestone || 'General'
    const existing = form.activities
        .filter(activity => !activity.parent_client_key && (activity.milestone || 'General') === normalizedMilestone)
        .map(activity => Number(activity.milestone_order))
        .filter(Number.isFinite)

    return existing.length ? Math.min(...existing) : nextMilestoneOrder()
}

const nextMilestoneOrder = () => {
    const orders = form.activities
        .filter(activity => !activity.parent_client_key)
        .map(activity => Number(activity.milestone_order))
        .filter(Number.isFinite)

    return orders.length ? Math.max(...orders) + 1 : 1
}

const focusLastActivityInput = () => {
    nextTick(() => {
        const lastInput = activityInputs.value[activityInputs.value.length - 1]
        if (lastInput) lastInput.focus()
    })
}

const addMilestone = () => {
    const milestoneName = `Milestone ${Object.keys(milestoneGroups.value).length + 1}`
    form.activities.push(createActivityRow({
        milestone: milestoneName,
        milestone_order: nextMilestoneOrder(),
        order: nextOrderFor(milestoneName)
    }))
    focusLastActivityInput()
}

const addActivity = (milestone = 'General') => {
    const lastRow = [...form.activities].reverse().find(activity => !activity.parent_client_key && (activity.milestone || 'General') === (milestone || 'General'))

    form.activities.push(createActivityRow({
        milestone: milestone || 'General',
        milestone_order: milestoneOrderFor(milestone),
        responsible: lastRow ? lastRow.responsible : null,
        department: lastRow ? lastRow.department : '',
        sub_unit: lastRow ? lastRow.sub_unit : '',
        default_duration_days: lastRow ? lastRow.default_duration_days : 1,
        order: nextOrderFor(milestone)
    }))

    focusLastActivityInput()
}

const addSubActivity = (parentActivity) => {
    form.activities.push(createActivityRow({
        parent_client_key: parentActivity.client_key,
        milestone: parentActivity.milestone || 'General',
        milestone_order: parentActivity.milestone_order ?? milestoneOrderFor(parentActivity.milestone),
        responsible: parentActivity.responsible,
        department: parentActivity.department || '',
        sub_unit: parentActivity.sub_unit || '',
        default_duration_days: parentActivity.default_duration_days || 1,
        order: nextOrderFor(parentActivity.milestone, parentActivity.client_key)
    }))

    focusLastActivityInput()
}

const renameMilestone = (currentMilestone, nextMilestone) => {
    const order = milestoneOrderFor(currentMilestone)

    form.activities.forEach(activity => {
        if ((activity.milestone || 'General') === currentMilestone) {
            activity.milestone = nextMilestone || 'General'
            activity.milestone_order = order
        }
    })
}

const syncSubTaskMilestone = (parentActivity) => {
    form.activities.forEach(activity => {
        if (activity.parent_client_key === parentActivity.client_key) {
            activity.milestone = parentActivity.milestone || 'General'
            activity.milestone_order = parentActivity.milestone_order ?? milestoneOrderFor(parentActivity.milestone)
        }
    })
}

const removeActivity = (activity) => {
    const keysToRemove = new Set([activity.client_key])

    if (!activity.parent_client_key) {
        form.activities
            .filter(candidate => candidate.parent_client_key === activity.client_key)
            .forEach(candidate => keysToRemove.add(candidate.client_key))
    }

    form.activities = form.activities.filter(candidate => !keysToRemove.has(candidate.client_key))

    // Rows queued behind a deleted one fall back to following whatever now sits
    // above them, rather than keeping a pointer at something that is gone.
    form.activities.forEach(candidate => {
        if (keysToRemove.has(candidate.depends_on_client_key)) {
            candidate.depends_on_client_key = null
        }
    })

    if (form.activities.length === 0) {
        form.activities = [createActivityRow()]
    }
}

// ── Start / Finish day numbers ────────────────────────────────────────────────
// Plain day numbers counted off Day 1 — a template has no calendar of its own.
// The arithmetic follows References/Business_Requirement_Milestone_Schedule_
// Computation.xlsx ("Developer Logic" sheet) to the letter:
//
//   1. Dependency blank        → Start = Day 1 (a sub-task falls back to its
//                                milestone's own start day)
//   2. Dependency, Parallel No → Start = Dependency Finish + 1
//   3. Dependency, Parallel Yes→ Start = Dependency Start (runs alongside it)
//   4. Finish = Start + Lead Time - 1   ← lead time is INCLUSIVE of the start
//   5. Milestone Lead Time = sum of its sub-task lead times
//   6. Milestone Finish = latest sub-task finish
//
// The Dependency column's "Previous row" / "Previous sub-task" placeholder is
// the implicit dependency: an unset requisite means the row above, which is what
// makes "Milestone Start = Previous Finish + 1" fall out of rule 2.
const scheduleOffsets = computed(() => {
    const rows = form.activities
    const byKey = new Map(rows.map(row => [row.client_key, row]))

    const byOrder = (a, b) => (Number(a.order) || 0) - (Number(b.order) || 0)
    const roots = rows
        .filter(row => !row.parent_client_key || !byKey.has(row.parent_client_key))
        .sort((a, b) => {
            const am = Number(a.milestone_order) || 0
            const bm = Number(b.milestone_order) || 0
            return am !== bm ? am - bm : byOrder(a, b)
        })

    const childrenByParent = new Map()
    rows.forEach(row => {
        if (!row.parent_client_key || !byKey.has(row.parent_client_key)) return
        if (!childrenByParent.has(row.parent_client_key)) childrenByParent.set(row.parent_client_key, [])
        childrenByParent.get(row.parent_client_key).push(row)
    })
    childrenByParent.forEach(list => list.sort(byOrder))

    // The row each one queues behind: previous root, or previous sibling.
    const predecessor = new Map()
    roots.forEach((row, index) => predecessor.set(row.client_key, index ? roots[index - 1].client_key : null))
    childrenByParent.forEach(list => {
        list.forEach((row, index) => predecessor.set(row.client_key, index ? list[index - 1].client_key : null))
    })

    // The one row this one hangs off: the chosen requisite, or — when the
    // Dependency cell is left on "Previous row" — the row above it.
    const dependencyOf = (row) => {
        const key = row.depends_on_client_key

        if (key && key !== row.client_key && byKey.has(key)) return key

        return predecessor.get(row.client_key) || null
    }

    const resolved = {}
    const leadTimeOf = (row) => Math.max(1, Number(row.default_duration_days) || 1)

    const startFor = (row, fallback) => {
        const dependency = resolved[dependencyOf(row)]

        if (!dependency) return fallback

        return row.can_run_parallel ? dependency.start : dependency.finish + 1
    }

    const isReady = (row) => {
        const dependency = dependencyOf(row)
        return !dependency || Boolean(resolved[dependency])
    }

    const place = (row, fallback) => {
        const start = startFor(row, fallback)
        resolved[row.client_key] = { start, finish: start + leadTimeOf(row) - 1 }
    }

    const resolveGroup = (root) => {
        place(root, 1)
        const children = childrenByParent.get(root.client_key) || []
        if (!children.length) return

        // Sub-tasks may point at a later sibling, so sweep rather than march.
        const parentStart = resolved[root.client_key].start
        let pending = [...children]
        let guard = children.length + 1

        while (pending.length && guard--) {
            const ready = pending.filter(isReady)
            if (!ready.length) break
            ready.forEach(child => place(child, parentStart))
            pending = pending.filter(child => !resolved[child.client_key])
        }
        pending.forEach(child => place(child, parentStart))

        // Rule 6 — a parent owns no span of its own: it covers whatever its
        // sub-tasks span, so its finish is the latest sub-task finish.
        resolved[root.client_key] = {
            start: Math.min(...children.map(child => resolved[child.client_key].start)),
            finish: Math.max(...children.map(child => resolved[child.client_key].finish)),
        }
    }

    // Dependency-first, not top-to-bottom: a row may depend on one further down.
    let pending = [...roots]
    let guard = roots.length + 1

    while (pending.length && guard--) {
        const ready = pending.filter(root => {
            const children = childrenByParent.get(root.client_key) || []
            const inGroup = (key) => key === root.client_key
                || children.some(child => child.client_key === key)

            return [root, ...children].every(row => {
                const dependency = dependencyOf(row)
                return !dependency || resolved[dependency] || inGroup(dependency)
            })
        })

        if (!ready.length) break
        ready.forEach(resolveGroup)
        pending = pending.filter(root => !resolved[root.client_key])
    }
    // Anything left is in a cycle — lay it down in plain list order.
    pending.forEach(resolveGroup)

    return resolved
})

const offsetFor = (activity) => scheduleOffsets.value[activity.client_key] || { start: 1, finish: 1 }

/** Every other row, so a requisite can point anywhere in the template. */
const requisiteOptionsFor = (activity) => {
    return form.activities
        .filter(candidate => candidate.client_key !== activity.client_key)
        .map(candidate => ({
            value: candidate.client_key,
            label: `${candidate.parent_client_key ? '↳ ' : ''}${candidate.activity || '(unnamed)'} · ${candidate.milestone || 'General'}`,
        }))
}

/** The day range a whole milestone covers, parallel rows included. */
const milestoneSpan = (activities) => {
    const keys = activities.flatMap(activity => [activity.client_key, ...subTasksFor(activity).map(sub => sub.client_key)])
    const spans = keys.map(key => scheduleOffsets.value[key]).filter(Boolean)

    if (!spans.length) return { start: 1, finish: 1 }

    return {
        start: Math.min(...spans.map(span => span.start)),
        finish: Math.max(...spans.map(span => span.finish)),
    }
}

/** Rule 5 — a milestone's lead time is the sum of its sub-task lead times. */
const subTaskLeadTimeSum = (activity) => {
    return subTasksFor(activity).reduce((sum, subTask) => sum + Math.max(1, Number(subTask.default_duration_days) || 1), 0)
}

// Only root activities are counted: a parent's lead time is already the sum of
// its sub-tasks (kept in sync by the watcher below), so adding sub-task days on
// top would double-count them.
const milestoneLeadTimeSum = (activities) => {
    return activities.reduce((sum, activity) => sum + (Number(activity.default_duration_days) || 0), 0)
}

/**
 * How long the whole plan runs. Day numbers are inclusive, so a plan finishing
 * on day 10 is 10 days long — no -1 correction.
 */
const grandTotalLeadTimeDays = computed(() => {
    const spans = Object.values(scheduleOffsets.value)

    return spans.length ? Math.max(...spans.map(span => span.finish)) : 0
})

watch(
    () => form.activities.map(a => ({
        key: a.parent_client_key,
        days: a.default_duration_days,
        dep: a.depends_on_client_key,
        parallel: a.can_run_parallel,
        order: a.order,
    })),
    () => {
        // Rule 5 — a parent's lead time is the SUM of its sub-task lead times,
        // never the stretch they happen to cover. Parallel sub-tasks shorten the
        // milestone's span but not the effort it totals.
        form.activities.forEach(activity => {
            if (activity.parent_client_key) return

            if (!subTasksFor(activity).length) return

            const days = subTaskLeadTimeSum(activity)
            if (Number(activity.default_duration_days) !== days) {
                activity.default_duration_days = days
            }
        })
    },
    { deep: true }
)

const handleSubTaskDepartmentChange = (subTask) => {
    if (!subUnitsForDepartment(subTask.department).includes(subTask.sub_unit)) {
        subTask.sub_unit = ''
    }
}

const preventLastDigitBackspace = (event) => {
    if (String(event.currentTarget.value).length <= 1) {
        event.preventDefault()
    }
}

const ensureNumericValue = (activity, field, event) => {
    const value = event.currentTarget.value
    // Data Dictionary: Lead Time must be > 0, and Ord is 1-based.
    const isBelowMinimum = !Number.isFinite(Number(value)) || Number(value) < 1
    const isInvalid = (field === 'order' || field === 'default_duration_days') && isBelowMinimum

    if (value === '' || isInvalid) {
        event.currentTarget.value = '1'
        activity[field] = 1
    }
}


const submitForm = () => {
    const transformPayload = (data) => ({
        ...data,
        activities: data.activities.map(({ subTasks, ...activity }) => activity)
    })

    if (isEditing.value) {
        form.transform(transformPayload).put(route('activity-templates.update', currentTemplate.value.id), {
            onSuccess: () => {
                closeModal()
                applyFilters()
            }
        })
    } else {
        form.transform(transformPayload).post(route('activity-templates.store'), {
            onSuccess: () => {
                closeModal()
                applyFilters()
            }
        })
    }
}

const deleteTemplate = async (template) => {
    const confirmed = await confirm({
        title: 'Delete Project Template',
        message: `Are you sure you want to delete "${template.name}"? All associated activities will also be removed.`,
        confirmLabel: 'Delete',
        cancelLabel: 'Cancel',
        variant: 'danger'
    })
    
    if (confirmed) {
        router.delete(route('activity-templates.destroy', template.id), {
            onSuccess: () => applyFilters()
        })
    }
}
</script>

<style scoped>
.custom-scrollbar::-webkit-scrollbar {
    height: 6px;
    width: 6px;
}
.custom-scrollbar::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 10px;
}
.custom-scrollbar::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 10px;
}
.custom-scrollbar::-webkit-scrollbar-thumb:hover {
    background: #94a3b8;
}
</style>
