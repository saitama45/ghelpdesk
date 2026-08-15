<template>
    <div class="space-y-5">
        <!-- Sub-navigation -->
        <div class="flex flex-wrap gap-2">
            <button v-for="pane in panes" :key="pane.id" @click="activePane = pane.id"
                    class="rounded-lg px-4 py-2 text-sm font-semibold transition-colors"
                    :class="activePane === pane.id
                        ? 'bg-blue-600 text-white shadow-sm'
                        : 'border border-gray-300 bg-white text-gray-600 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700'">
                {{ pane.label }}
            </button>
        </div>

        <!-- ============ PARTICIPANTS ============ -->
        <div v-if="activePane === 'participants'" class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-5 py-4 dark:border-gray-700">
                <div>
                    <h3 class="text-sm font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">Participants</h3>
                    <p class="mt-0.5 text-xs text-gray-400">
                        Each row is a column of the verdict matrix. Stakeholders can be given a no-login access link.
                    </p>
                </div>
                <button @click="openParticipant()"
                        class="inline-flex items-center gap-2 whitespace-nowrap rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition-colors hover:bg-blue-700">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                    </svg>
                    Add Participant
                </button>
            </div>

            <p v-if="!participants.length" class="px-5 py-10 text-center text-sm text-gray-400">
                No participants yet. Add the departments testing internally, and the clients who need to accept.
            </p>

            <table v-else class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-900">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-slate-300">Column</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-slate-300">Who</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-slate-300">Role</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-slate-300">Access Link</th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-slate-300">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    <tr v-for="participant in participants" :key="participant.id" class="transition-colors hover:bg-gray-50 dark:hover:bg-gray-700">
                        <td class="px-4 py-3">
                            <div class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ participant.label }}</div>
                            <div class="text-xs text-gray-400">
                                {{ participant.kind === 'stakeholder' ? 'Client / Stakeholder' : 'Department' }}
                                <span v-if="!participant.is_active" class="ml-1 font-bold text-amber-600">· Inactive</span>
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <div class="text-sm text-gray-700 dark:text-gray-200">{{ participant.display_name || '—' }}</div>
                            <div class="text-xs text-gray-400">{{ participant.display_email || '' }}</div>
                        </td>
                        <td class="px-4 py-3">
                            <span class="rounded-full px-2 py-1 text-[10px] font-bold uppercase tracking-wide" :class="roleClass(participant.role)">
                                {{ roleLabel(participant.role) }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <div v-if="participant.portal_url" class="flex items-center gap-2">
                                <input :value="participant.portal_url" readonly @focus="$event.target.select()"
                                       class="w-56 rounded border-gray-300 bg-gray-50 px-2 py-1 text-xs text-gray-600 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300">
                                <button @click="copyLink(participant)" title="Copy access link"
                                        class="rounded-full p-1.5 text-blue-600 transition-colors hover:bg-blue-50 dark:hover:bg-blue-900/30">
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                                    </svg>
                                </button>
                            </div>
                            <span v-else-if="participant.has_token" class="text-xs font-medium text-amber-600 dark:text-amber-400">
                                Expired
                            </span>
                            <span v-else class="text-xs text-gray-400">Not issued</span>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-right">
                            <div class="flex justify-end space-x-1">
                                <UatIconBtn kind="link" :title="participant.portal_url ? 'Re-issue access link' : 'Issue access link'"
                                            @click="issueToken(participant)" />
                                <UatIconBtn v-if="participant.has_token" kind="unlink" title="Revoke access link"
                                            @click="revokeToken(participant)" />
                                <UatIconBtn kind="edit" title="Edit participant" @click="openParticipant(participant)" />
                                <UatIconBtn kind="delete" title="Remove participant" @click="removeParticipant(participant)" />
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- ============ SECTIONS ============ -->
        <div v-if="activePane === 'sections'" class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-5 py-4 dark:border-gray-700">
                <div>
                    <h3 class="text-sm font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">Sections</h3>
                    <p class="mt-0.5 text-xs text-gray-400">
                        Functional areas that group the test cases. Non-critical sections are reported but never block go-live.
                    </p>
                </div>
                <button @click="openSection()"
                        class="inline-flex items-center gap-2 whitespace-nowrap rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition-colors hover:bg-blue-700">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                    </svg>
                    Add Section
                </button>
            </div>

            <p v-if="!sections.length" class="px-5 py-10 text-center text-sm text-gray-400">No sections yet.</p>

            <table v-else class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    <tr v-for="section in sections" :key="section.id" class="transition-colors hover:bg-gray-50 dark:hover:bg-gray-700">
                        <td class="px-4 py-3">
                            <div class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ section.name }}</div>
                            <div v-if="section.description" class="text-xs text-gray-400">{{ section.description }}</div>
                        </td>
                        <td class="px-4 py-3">
                            <span v-if="!section.is_critical" class="rounded bg-slate-100 px-2 py-1 text-[10px] font-bold uppercase text-slate-500 dark:bg-slate-700 dark:text-slate-300">
                                Non-critical
                            </span>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">
                            {{ caseCountFor(section.id) }} case(s)
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-right">
                            <div class="flex justify-end space-x-1">
                                <UatIconBtn kind="edit" title="Edit section" @click="openSection(section)" />
                                <UatIconBtn kind="delete" title="Remove section" @click="removeSection(section)" />
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- ============ CASES ============ -->
        <div v-if="activePane === 'cases'" class="space-y-4">
            <div class="flex flex-wrap items-end gap-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <div class="min-w-[220px] flex-1">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Search</label>
                    <input v-model="caseSearch" type="text" placeholder="Filter by ID, title or screen..."
                           class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                </div>
                <div class="w-52">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Section</label>
                    <Autocomplete v-model="caseSectionFilter" :options="sectionFilterOptions" placeholder="All sections" />
                </div>
                <button @click="openCase()"
                        class="ml-auto inline-flex items-center gap-2 whitespace-nowrap rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition-colors hover:bg-blue-700">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                    </svg>
                    Add Test Case
                </button>
            </div>

            <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <p v-if="!visibleCases.length" class="px-5 py-10 text-center text-sm text-gray-400">
                    {{ cases.length ? 'No cases match these filters.' : 'No test cases yet — add one, or import a workbook from the header.' }}
                </p>

                <table v-else class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-900">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-slate-300">ID</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-slate-300">Test Case</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-slate-300">Section</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-slate-300">Priority</th>
                            <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-slate-300">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        <tr v-for="testCase in visibleCases" :key="testCase.id" class="transition-colors hover:bg-gray-50 dark:hover:bg-gray-700">
                            <td class="whitespace-nowrap px-4 py-3 font-mono text-xs font-bold text-gray-500 dark:text-gray-300">
                                {{ testCase.case_key }}
                            </td>
                            <td class="px-4 py-3">
                                <div class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ testCase.title }}</div>
                                <div v-if="testCase.screen && testCase.screen !== testCase.title" class="text-xs text-gray-400">{{ testCase.screen }}</div>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">{{ sectionName(testCase.uat_section_id) }}</td>
                            <td class="whitespace-nowrap px-4 py-3">
                                <span class="rounded-full px-2 py-1 text-[10px] font-bold uppercase tracking-wide" :class="priorityClass(testCase.priority)">
                                    {{ testCase.priority }}
                                </span>
                                <span v-if="!testCase.is_critical" class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-[9px] font-bold uppercase text-slate-500 dark:bg-slate-700 dark:text-slate-300">
                                    Non-crit
                                </span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right">
                                <div class="flex justify-end space-x-1">
                                    <UatIconBtn kind="edit" title="Edit test case" @click="openCase(testCase)" />
                                    <UatIconBtn v-if="can('uat.delete')" kind="delete" title="Delete test case" @click="removeCase(testCase)" />
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Participant modal -->
        <Modal :show="participantModal.open" @close="participantModal.open = false" maxWidth="2xl">
            <div class="p-6">
                <h3 class="text-xl font-bold text-gray-900 dark:text-gray-100">
                    {{ participantModal.record ? 'Edit Participant' : 'Add Participant' }}
                </h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-300">
                    Departments test internally. Client/stakeholder rows can be sent a link and never need an account.
                </p>

                <form @submit.prevent="submitParticipant" class="mt-5 space-y-4">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Type</label>
                            <Autocomplete v-model="participantForm.kind" :options="options.participantKinds || []" placeholder="Select type..." />
                        </div>

                        <!-- Department columns are picked from /departments; the
                             department's code becomes the matrix column heading.
                             Free-text labels stay available for external clients. -->
                        <div v-if="isDepartmentKind">
                            <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Department</label>
                            <Autocomplete v-model="participantForm.department_id" :options="departmentPickerOptions" placeholder="Select department..." />
                            <p class="mt-1 text-xs text-gray-400">
                                Column heading will be
                                <span class="font-semibold text-gray-600 dark:text-gray-300">{{ participantForm.label || '—' }}</span>
                            </p>
                            <InputError :message="participantModal.errors.label" class="mt-1" />
                        </div>

                        <div v-else>
                            <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Column Label</label>
                            <input v-model="participantForm.label" type="text" required maxlength="80" placeholder="e.g. Client QA, Coffee Bean"
                                   class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                            <InputError :message="participantModal.errors.label" class="mt-1" />
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Role</label>
                            <Autocomplete v-model="participantForm.role" :options="options.participantRoles || []" placeholder="Select role..." />
                            <p class="mt-1 text-xs text-gray-400">
                                Only approvers appear on the acceptance roster and gate the final sign-off.
                            </p>
                        </div>
                        <div v-if="isDepartmentKind">
                            <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Representative</label>
                            <Autocomplete v-model="participantForm.user_id" :options="userOptions" placeholder="Search user..." />
                        </div>
                    </div>

                    <div v-if="!isDepartmentKind" class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Company</label>
                            <Autocomplete v-model="participantForm.company_id" :options="companyOptions" placeholder="Select entity..." />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Contact Name</label>
                            <input v-model="participantForm.contact_name" type="text" placeholder="e.g. Paola Malong"
                                   class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Contact Email</label>
                            <input v-model="participantForm.contact_email" type="email" placeholder="name@client.com"
                                   class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                            <InputError :message="participantModal.errors.contact_email" class="mt-1" />
                        </div>
                    </div>

                    <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                        <input v-model="participantForm.is_active" type="checkbox" class="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                        <span>
                            <span class="block text-sm font-medium text-gray-800 dark:text-gray-100">Active</span>
                            <span class="block text-xs text-gray-500 dark:text-gray-400">Inactive participants keep their verdicts but drop out of the matrix.</span>
                        </span>
                    </label>

                    <div class="flex justify-end gap-3 border-t border-gray-200 pt-4 dark:border-gray-700">
                        <button type="button" @click="participantModal.open = false"
                                class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                            Cancel
                        </button>
                        <button type="submit" :disabled="participantModal.saving"
                                class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-blue-700 disabled:opacity-50">
                            {{ participantModal.saving ? 'Saving...' : 'Save' }}
                        </button>
                    </div>
                </form>
            </div>
        </Modal>

        <!-- Section modal -->
        <Modal :show="sectionModal.open" @close="sectionModal.open = false" maxWidth="lg">
            <div class="p-6">
                <h3 class="text-xl font-bold text-gray-900 dark:text-gray-100">
                    {{ sectionModal.record ? 'Edit Section' : 'Add Section' }}
                </h3>

                <form @submit.prevent="submitSection" class="mt-5 space-y-4">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Name</label>
                        <input v-model="sectionForm.name" type="text" required placeholder="e.g. Billing, Scheduler, Issuances"
                               class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                        <InputError :message="sectionModal.errors.name" class="mt-1" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Description</label>
                        <input v-model="sectionForm.description" type="text"
                               class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                    </div>
                    <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                        <input v-model="sectionForm.is_critical" type="checkbox" class="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                        <span>
                            <span class="block text-sm font-medium text-gray-800 dark:text-gray-100">Critical for go-live</span>
                            <span class="block text-xs text-gray-500 dark:text-gray-400">Untick for scope deferred past this release.</span>
                        </span>
                    </label>

                    <div class="flex justify-end gap-3 border-t border-gray-200 pt-4 dark:border-gray-700">
                        <button type="button" @click="sectionModal.open = false"
                                class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                            Cancel
                        </button>
                        <button type="submit" :disabled="sectionModal.saving"
                                class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-blue-700 disabled:opacity-50">
                            {{ sectionModal.saving ? 'Saving...' : 'Save' }}
                        </button>
                    </div>
                </form>
            </div>
        </Modal>

        <!-- Case modal -->
        <Modal :show="caseModal.open" @close="caseModal.open = false" maxWidth="3xl">
            <div class="p-6">
                <h3 class="text-xl font-bold text-gray-900 dark:text-gray-100">
                    {{ caseModal.record ? `Edit ${caseModal.record.case_key}` : 'Add Test Case' }}
                </h3>

                <form @submit.prevent="submitCase" class="mt-5 space-y-4">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-4">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Case ID</label>
                            <input v-model="caseForm.case_key" type="text" maxlength="40" placeholder="Auto"
                                   class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                            <InputError :message="caseModal.errors.case_key" class="mt-1" />
                        </div>
                        <div class="sm:col-span-3">
                            <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Title</label>
                            <input v-model="caseForm.title" type="text" required
                                   class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                            <InputError :message="caseModal.errors.title" class="mt-1" />
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Section</label>
                            <Autocomplete v-model="caseForm.uat_section_id" :options="sectionSelectOptions" placeholder="Select section..." />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Screen</label>
                            <input v-model="caseForm.screen" type="text" placeholder="e.g. Issuances - Department Orders"
                                   class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Priority</label>
                            <Autocomplete v-model="caseForm.priority" :options="options.priorities || []" placeholder="Select priority..." />
                        </div>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Description</label>
                        <textarea v-model="caseForm.description" rows="2"
                                  class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"></textarea>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Test Steps</label>
                        <textarea v-model="caseForm.steps" rows="8" placeholder="1. Open the page&#10;   a. Launch your browser…&#10;2. Apply filters…"
                                  class="w-full rounded-lg border-gray-300 font-mono text-xs shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"></textarea>
                        <p class="mt-1 text-xs text-gray-400">Numbering and indentation are kept exactly as typed, so procedures can be pasted straight in.</p>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Expected Results</label>
                        <textarea v-model="caseForm.expected_results" rows="4" placeholder="* Records counter matches visible rows&#10;* PDF opens in a new tab"
                                  class="w-full rounded-lg border-gray-300 font-mono text-xs shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"></textarea>
                        <p class="mt-1 text-xs text-gray-400">One per line — the test runner turns these into a tick-list.</p>
                    </div>

                    <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                        <input v-model="caseForm.is_critical" type="checkbox" class="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                        <span>
                            <span class="block text-sm font-medium text-gray-800 dark:text-gray-100">Critical for go-live</span>
                            <span class="block text-xs text-gray-500 dark:text-gray-400">Untick for nice-to-have scope that must not block sign-off.</span>
                        </span>
                    </label>

                    <div class="flex justify-end gap-3 border-t border-gray-200 pt-4 dark:border-gray-700">
                        <button type="button" @click="caseModal.open = false"
                                class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                            Cancel
                        </button>
                        <button type="submit" :disabled="caseModal.saving"
                                class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-blue-700 disabled:opacity-50">
                            {{ caseModal.saving ? 'Saving...' : 'Save' }}
                        </button>
                    </div>
                </form>
            </div>
        </Modal>
    </div>
</template>

<script setup>
import { ref, reactive, computed, watch, inject } from 'vue'
import { router } from '@inertiajs/vue3'
import Modal from '@/Components/Modal.vue'
import Autocomplete from '@/Components/Autocomplete.vue'
import InputError from '@/Components/InputError.vue'
import UatIconBtn from './UatIconBtn.vue'
import { useConfirm } from '@/Composables/useConfirm'
import { useToast } from '@/Composables/useToast'

const props = defineProps({
    cycle: Object,
    sections: Array,
    cases: Array,
    participants: Array,
    results: Array,
    findings: Array,
    signoffs: Array,
    statistics: Object,
    participantProgress: Array,
    sectionProgress: Object,
    readiness: Object,
    acceptance: Object,
    options: Object,
})

const can = inject('uatCan', () => false)
const { confirm } = useConfirm()
const { showSuccess, showError } = useToast()

const panes = [
    { id: 'participants', label: 'Participants' },
    { id: 'sections', label: 'Sections' },
    { id: 'cases', label: 'Test Cases' },
]
const activePane = ref('participants')

const caseSearch = ref('')
const caseSectionFilter = ref(null)

const participantModal = reactive({ open: false, record: null, saving: false, errors: {} })
const participantForm = reactive({
    kind: 'department', label: '', department_id: null, company_id: null, user_id: null,
    contact_name: '', contact_email: '', role: 'tester', is_active: true,
})

const sectionModal = reactive({ open: false, record: null, saving: false, errors: {} })
const sectionForm = reactive({ name: '', description: '', is_critical: true })

const caseModal = reactive({ open: false, record: null, saving: false, errors: {} })
const caseForm = reactive({
    case_key: '', title: '', screen: '', description: '', steps: '', expected_results: '',
    uat_section_id: null, priority: 'medium', is_critical: true,
})

const departmentOptions = computed(() => [{ label: '—', value: null }, ...(props.options?.departments || [])])

const isDepartmentKind = computed(() => participantForm.kind === 'department')

/**
 * Department picker for the participant form. An imported column whose heading
 * matched no department (e.g. "QA", "Ops Support") keeps a synthetic entry so
 * editing that row does not silently wipe its label.
 */
const departmentPickerOptions = computed(() => {
    const base = (props.options?.departments || []).map(d => ({ ...d }))
    const record = participantModal.record

    if (record && !record.department_id && record.label) {
        base.unshift({ label: `${record.label} (custom column)`, value: null, code: record.label, name: record.label })
    }

    return base
})

/** Selecting a department sets the column heading to its code. */
watch(() => participantForm.department_id, (id) => {
    if (!isDepartmentKind.value) return

    const picked = departmentPickerOptions.value.find(d => d.value === id)
    if (picked) {
        participantForm.label = (picked.code || picked.name || '').slice(0, 80)
    }
})

// Switching type clears the other branch's fields so a half-filled form cannot
// save a stakeholder with a department id, or vice versa.
watch(() => participantForm.kind, (kind, previous) => {
    if (kind === previous || previous === undefined) return

    if (kind === 'department') {
        participantForm.company_id = null
        participantForm.contact_name = ''
        participantForm.contact_email = ''
    } else {
        participantForm.department_id = null
        participantForm.user_id = null
    }
})
const companyOptions = computed(() => [{ label: '—', value: null }, ...(props.options?.companies || [])])
const userOptions = computed(() => [{ label: '—', value: null }, ...(props.options?.users || [])])

const sectionSelectOptions = computed(() => [
    { label: '— Ungrouped —', value: null },
    ...(props.sections || []).map(s => ({ label: s.name, value: s.id })),
])
const sectionFilterOptions = computed(() => [
    { label: 'All sections', value: null },
    ...(props.sections || []).map(s => ({ label: s.name, value: s.id })),
])

const sectionName = (id) => (props.sections || []).find(s => s.id === id)?.name || 'Ungrouped'
const caseCountFor = (sectionId) => (props.cases || []).filter(c => c.uat_section_id === sectionId).length

const roleLabel = (role) => (props.options?.participantRoles || []).find(r => r.value === role)?.label || role
const roleClass = (role) => ({
    tester: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-200',
    approver: 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-200',
    observer: 'bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-200',
}[role] || 'bg-gray-100 text-gray-600')

const priorityClass = (priority) => ({
    low: 'bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-200',
    medium: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-200',
    high: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-200',
    critical: 'bg-rose-100 text-rose-700 dark:bg-rose-900/40 dark:text-rose-200',
}[priority] || 'bg-gray-100 text-gray-600')

const visibleCases = computed(() => {
    const term = caseSearch.value.trim().toLowerCase()

    return (props.cases || []).filter(testCase => {
        if (caseSectionFilter.value && testCase.uat_section_id !== caseSectionFilter.value) return false
        if (!term) return true
        return [testCase.case_key, testCase.title, testCase.screen]
            .some(field => String(field || '').toLowerCase().includes(term))
    })
})

// ---- participants ----

const openParticipant = (record = null) => {
    participantModal.record = record
    participantModal.errors = {}
    Object.assign(participantForm, record ? {
        kind: record.kind,
        label: record.label,
        department_id: record.department_id,
        company_id: record.company_id,
        user_id: record.user_id,
        contact_name: record.contact_name || '',
        contact_email: record.contact_email || '',
        role: record.role,
        is_active: record.is_active,
    } : {
        kind: 'department', label: '', department_id: null, company_id: null, user_id: null,
        contact_name: '', contact_email: '', role: 'tester', is_active: true,
    })
    participantModal.open = true
}

const submitParticipant = () => {
    // A department column takes its heading from the picker, so guard the case
    // where nothing was chosen rather than posting an empty label.
    if (isDepartmentKind.value && !participantForm.label.trim()) {
        participantModal.errors = { label: 'Choose a department — its code becomes the column heading.' }
        return
    }

    participantModal.saving = true
    participantModal.errors = {}

    const done = {
        preserveScroll: true,
        onSuccess: () => { participantModal.open = false },
        onError: (e) => { participantModal.errors = e },
        onFinish: () => { participantModal.saving = false },
    }

    if (participantModal.record) {
        router.put(`/uat/${props.cycle.id}/participants/${participantModal.record.id}`, { ...participantForm }, done)
    } else {
        router.post(`/uat/${props.cycle.id}/participants`, { ...participantForm }, done)
    }
}

const removeParticipant = async (participant) => {
    const ok = await confirm({
        title: 'Remove Participant',
        message: `Remove the "${participant.label}" column? Every verdict and sign-off recorded against it is deleted too.`,
        confirmLabel: 'Remove',
        variant: 'danger',
    })
    if (!ok) return

    router.delete(`/uat/${props.cycle.id}/participants/${participant.id}`, { preserveScroll: true })
}

const issueToken = (participant) => {
    router.post(`/uat/${props.cycle.id}/participants/${participant.id}/token`, { valid_days: 60 }, { preserveScroll: true })
}

const revokeToken = async (participant) => {
    const ok = await confirm({
        title: 'Revoke Access Link',
        message: `Revoke the access link for "${participant.label}"? Anyone holding the old link loses access immediately.`,
        confirmLabel: 'Revoke',
        variant: 'danger',
    })
    if (!ok) return

    router.delete(`/uat/${props.cycle.id}/participants/${participant.id}/token`, { preserveScroll: true })
}

const copyLink = async (participant) => {
    try {
        await navigator.clipboard.writeText(participant.portal_url)
        showSuccess('Access link copied to clipboard.')
    } catch {
        showError('Could not copy — select the link and copy it manually.')
    }
}

// ---- sections ----

const openSection = (record = null) => {
    sectionModal.record = record
    sectionModal.errors = {}
    Object.assign(sectionForm, record ? {
        name: record.name,
        description: record.description || '',
        is_critical: record.is_critical,
    } : { name: '', description: '', is_critical: true })
    sectionModal.open = true
}

const submitSection = () => {
    sectionModal.saving = true
    sectionModal.errors = {}

    const done = {
        preserveScroll: true,
        onSuccess: () => { sectionModal.open = false },
        onError: (e) => { sectionModal.errors = e },
        onFinish: () => { sectionModal.saving = false },
    }

    if (sectionModal.record) {
        router.put(`/uat/${props.cycle.id}/sections/${sectionModal.record.id}`, { ...sectionForm }, done)
    } else {
        router.post(`/uat/${props.cycle.id}/sections`, { ...sectionForm }, done)
    }
}

const removeSection = async (section) => {
    const ok = await confirm({
        title: 'Remove Section',
        message: `Remove "${section.name}"? Its ${caseCountFor(section.id)} test case(s) are kept and become ungrouped.`,
        confirmLabel: 'Remove',
        variant: 'danger',
    })
    if (!ok) return

    router.delete(`/uat/${props.cycle.id}/sections/${section.id}`, { preserveScroll: true })
}

// ---- cases ----

const openCase = (record = null) => {
    caseModal.record = record
    caseModal.errors = {}

    if (!record) {
        Object.assign(caseForm, {
            case_key: '', title: '', screen: '', description: '', steps: '', expected_results: '',
            uat_section_id: caseSectionFilter.value, priority: 'medium', is_critical: true,
        })
        caseModal.open = true
        return
    }

    // The list payload omits the long text columns, so the full record is
    // fetched before the form opens.
    Object.assign(caseForm, {
        case_key: record.case_key,
        title: record.title,
        screen: record.screen || '',
        description: '',
        steps: '',
        expected_results: '',
        uat_section_id: record.uat_section_id,
        priority: record.priority,
        is_critical: record.is_critical,
    })
    caseModal.open = true

    fetch(`/uat/${props.cycle.id}/cases/${record.id}`, {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
    })
        .then(response => (response.ok ? response.json() : null))
        .then(payload => {
            if (!payload?.case || caseModal.record?.id !== record.id) return
            caseForm.description = payload.case.description || ''
            caseForm.steps = payload.case.steps || ''
            caseForm.expected_results = payload.case.expected_results || ''
        })
        .catch(() => {})
}

const submitCase = () => {
    caseModal.saving = true
    caseModal.errors = {}

    const done = {
        preserveScroll: true,
        onSuccess: () => { caseModal.open = false },
        onError: (e) => { caseModal.errors = e },
        onFinish: () => { caseModal.saving = false },
    }

    if (caseModal.record) {
        router.put(`/uat/${props.cycle.id}/cases/${caseModal.record.id}`, { ...caseForm }, done)
    } else {
        router.post(`/uat/${props.cycle.id}/cases`, { ...caseForm }, done)
    }
}

const removeCase = async (testCase) => {
    const ok = await confirm({
        title: 'Delete Test Case',
        message: `Delete ${testCase.case_key} — "${testCase.title}"? Every verdict recorded against it is deleted too.`,
        confirmLabel: 'Delete',
        variant: 'danger',
    })
    if (!ok) return

    router.delete(`/uat/${props.cycle.id}/cases/${testCase.id}`, { preserveScroll: true })
}
</script>
