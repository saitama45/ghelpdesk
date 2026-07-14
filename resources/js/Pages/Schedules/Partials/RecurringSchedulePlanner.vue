<template>
    <div class="fixed inset-0 z-[70] overflow-y-auto" @keydown.esc="requestClose">
        <div class="flex min-h-full items-center justify-center p-0 sm:p-4 lg:p-6">
            <button class="fixed inset-0 cursor-default bg-slate-950/55 backdrop-blur-sm" aria-label="Close planner" @click="requestClose"></button>

            <section
                ref="dialogRef"
                role="dialog"
                aria-modal="true"
                aria-labelledby="recurring-planner-title"
                tabindex="-1"
                class="relative flex min-h-screen w-full flex-col overflow-hidden bg-white shadow-2xl outline-none dark:bg-slate-900 sm:min-h-0 sm:max-w-6xl sm:rounded-3xl sm:border sm:border-slate-200 sm:dark:border-slate-700"
                @keydown="handleDialogKeydown"
            >
                <header class="border-b border-slate-200 bg-white px-4 py-4 dark:border-slate-700 dark:bg-slate-900 sm:px-7">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <div class="mb-1 flex items-center gap-2 text-xs font-black uppercase tracking-[0.18em] text-blue-600">
                                <span class="grid h-6 w-6 place-items-center rounded-lg bg-blue-100 dark:bg-blue-500/15">{{ step }}</span>
                                Schedule planning
                            </div>
                            <h2 id="recurring-planner-title" class="text-xl font-black text-slate-900 dark:text-white sm:text-2xl">{{ stepTitle }}</h2>
                            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ stepDescription }}</p>
                        </div>
                        <button type="button" class="rounded-xl p-2 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-slate-800 dark:hover:text-white" @click="requestClose">
                            <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18 18 6M6 6l12 12"/></svg>
                        </button>
                    </div>

                    <ol class="mt-5 grid grid-cols-3 gap-2" aria-label="Planner progress">
                        <li v-for="item in steps" :key="item.id" class="flex items-center gap-2">
                            <div :class="['h-1.5 flex-1 rounded-full transition-colors', step >= item.id ? 'bg-blue-600' : 'bg-slate-200 dark:bg-slate-700']"></div>
                            <span :class="['hidden text-[10px] font-black uppercase tracking-wider md:block', step === item.id ? 'text-blue-600' : 'text-slate-400']">{{ item.short }}</span>
                        </li>
                    </ol>
                </header>

                <main class="min-h-0 flex-1 overflow-y-auto bg-slate-50/70 px-4 py-5 dark:bg-slate-950/40 sm:px-7 sm:py-7">
                    <div v-if="errorMessage" class="mb-5 flex items-start gap-3 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-700 dark:border-rose-900/50 dark:bg-rose-950/30 dark:text-rose-300">
                        <svg class="mt-0.5 h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3m0 4h.01M10.3 3.7 2.2 18a2 2 0 0 0 1.8 3h16a2 2 0 0 0 1.8-3L13.7 3.7a2 2 0 0 0-3.4 0Z"/></svg>
                        <span>{{ errorMessage }}</span>
                    </div>

                    <div v-if="step === 1" class="grid gap-6 lg:grid-cols-[340px_minmax(0,1fr)]">
                        <aside class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                            <label class="mb-3 block text-xs font-black uppercase tracking-wider text-slate-500 dark:text-slate-400">Planning period</label>
                            <div class="grid gap-2">
                                <button type="button" :class="['rounded-xl border p-3 text-left transition-shadow focus:outline-none focus:ring-2 focus:ring-blue-500', periodType === 'month' ? 'border-blue-300 bg-blue-50 ring-1 ring-blue-100 dark:border-blue-700 dark:bg-blue-950/30 dark:ring-blue-900/40' : 'border-slate-200 bg-white hover:shadow-sm dark:border-slate-700 dark:bg-slate-800']" @click="periodType = 'month'">
                                    <span class="flex items-center justify-between gap-2"><span class="text-sm font-black text-slate-900 dark:text-white">One month</span><span v-if="periodType === 'month'" class="rounded-full bg-blue-600 px-2 py-0.5 text-[9px] font-black uppercase tracking-wider text-white">Selected</span></span>
                                    <span class="mt-1 block text-xs font-semibold text-slate-500 dark:text-slate-400">Plan only the selected calendar month.</span>
                                </button>
                                <button type="button" :class="['rounded-xl border p-3 text-left transition-shadow focus:outline-none focus:ring-2 focus:ring-blue-500', periodType === 'year' ? 'border-blue-300 bg-blue-50 ring-1 ring-blue-100 dark:border-blue-700 dark:bg-blue-950/30 dark:ring-blue-900/40' : 'border-slate-200 bg-white hover:shadow-sm dark:border-slate-700 dark:bg-slate-800']" @click="periodType = 'year'">
                                    <span class="flex items-center justify-between gap-2"><span class="text-sm font-black text-slate-900 dark:text-white">Full year</span><span v-if="periodType === 'year'" class="rounded-full bg-blue-600 px-2 py-0.5 text-[9px] font-black uppercase tracking-wider text-white">Selected</span></span>
                                    <span class="mt-1 block text-xs font-semibold text-slate-500 dark:text-slate-400">Apply the patterns from January through December.</span>
                                </button>
                            </div>

                            <label class="mb-2 mt-5 block text-xs font-black uppercase tracking-wider text-slate-500 dark:text-slate-400">{{ periodType === 'year' ? 'Planning year' : 'Planning month' }}</label>
                            <input v-if="periodType === 'month'" v-model="month" type="month" class="w-full rounded-xl border-slate-200 bg-white text-sm font-bold text-slate-800 focus:border-blue-500 focus:ring-blue-500 dark:border-slate-700 dark:bg-slate-800 dark:text-white">
                            <input v-else v-model.number="year" type="number" min="2020" max="2100" step="1" class="w-full rounded-xl border-slate-200 bg-white text-sm font-bold text-slate-800 focus:border-blue-500 focus:ring-blue-500 dark:border-slate-700 dark:bg-slate-800 dark:text-white">
                            <div class="mt-4 rounded-xl bg-blue-50 p-3 text-xs font-semibold leading-5 text-blue-700 dark:bg-blue-950/40 dark:text-blue-300">
                                {{ periodType === 'year' ? `Every matching weekday in ${year} will be suggested before you save.` : 'The planner starts on the month currently visible in your calendar.' }}
                            </div>
                        </aside>

                        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-900">
                            <div class="border-b border-slate-100 p-4 dark:border-slate-800 sm:flex sm:items-center sm:justify-between sm:gap-4">
                                <div>
                                    <h3 class="font-black text-slate-900 dark:text-white">Choose team members</h3>
                                    <p class="text-xs text-slate-500 dark:text-slate-400">The same weekly rules will apply to everyone selected.</p>
                                </div>
                                <span class="mt-2 inline-flex rounded-full bg-blue-100 px-3 py-1 text-xs font-black text-blue-700 dark:bg-blue-500/15 dark:text-blue-300 sm:mt-0">{{ selectedUserIds.length }} selected</span>
                            </div>
                            <div class="p-4">
                                <div class="flex flex-col gap-3 sm:flex-row">
                                    <div class="relative flex-1">
                                        <svg class="pointer-events-none absolute left-3 top-3 h-4 w-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m21 21-4.3-4.3M11 18a7 7 0 1 1 0-14 7 7 0 0 1 0 14Z"/></svg>
                                        <input v-model="userSearch" type="search" placeholder="Search employees..." class="w-full rounded-xl border-slate-200 pl-9 text-sm focus:border-blue-500 focus:ring-blue-500 dark:border-slate-700 dark:bg-slate-800 dark:text-white">
                                    </div>
                                    <button type="button" class="rounded-xl border border-slate-200 px-4 py-2 text-xs font-black text-slate-600 hover:border-blue-300 hover:text-blue-600 dark:border-slate-700 dark:text-slate-300" @click="toggleVisibleUsers">
                                        {{ allVisibleSelected ? 'Clear visible' : 'Select visible' }}
                                    </button>
                                </div>
                                <div class="mt-4 grid max-h-[390px] gap-2 overflow-y-auto pr-1 sm:grid-cols-2 xl:grid-cols-3">
                                    <label v-for="user in filteredUsers" :key="user.id" :class="['flex cursor-pointer items-center gap-3 rounded-xl border p-3 transition', isUserSelected(user.id) ? 'border-blue-300 bg-blue-50 ring-1 ring-blue-100 dark:border-blue-700 dark:bg-blue-950/30' : 'border-slate-200 hover:border-slate-300 dark:border-slate-700 dark:hover:border-slate-600']">
                                        <input :checked="isUserSelected(user.id)" type="checkbox" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500" @change="toggleUser(user.id)">
                                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-slate-100 text-xs font-black text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ initials(user.name) }}</span>
                                        <span class="min-w-0 text-sm font-bold text-slate-700 dark:text-slate-200"><span class="block truncate">{{ user.name }}</span></span>
                                    </label>
                                    <div v-if="filteredUsers.length === 0" class="col-span-full py-10 text-center text-sm font-semibold text-slate-400">No employees match your search.</div>
                                </div>
                            </div>
                        </section>
                    </div>

                    <div v-else-if="step === 2" class="space-y-5">
                        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900 sm:p-5">
                            <div>
                                <h3 class="font-black text-slate-900 dark:text-white">Add a schedule pattern</h3>
                                <p class="mt-1 text-xs font-semibold text-slate-500 dark:text-slate-400">Choose what you want to schedule. You can review and edit every pattern below.</p>
                            </div>
                            <div class="mt-4 grid gap-3 md:grid-cols-2">
                                <button
                                    type="button"
                                    :disabled="weekendRestRuleAdded || weekendShortcutBlocked"
                                    :class="['flex min-h-24 items-center gap-4 rounded-2xl border p-4 text-left shadow-sm transition-shadow focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:focus:ring-offset-slate-900', weekendRestRuleAdded ? 'cursor-default border-emerald-200 bg-emerald-50/70 dark:border-emerald-900/60 dark:bg-emerald-950/20' : weekendShortcutBlocked ? 'cursor-not-allowed border-amber-200 bg-amber-50/70 opacity-80 dark:border-amber-900/60 dark:bg-amber-950/20' : 'border-slate-200 bg-white hover:shadow-md dark:border-slate-700 dark:bg-slate-800']"
                                    @click="addWeekendRule"
                                >
                                    <span :class="['grid h-11 w-11 shrink-0 place-items-center rounded-xl', weekendRestRuleAdded ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300' : 'bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-200']">
                                        <svg v-if="weekendRestRuleAdded" class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="m5 12 4 4L19 6"/></svg>
                                        <svg v-else class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3M5 11h14M5 5h14a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2Z"/></svg>
                                    </span>
                                    <span class="min-w-0 flex-1">
                                        <span class="flex items-center justify-between gap-2">
                                            <span class="text-sm font-black text-slate-900 dark:text-white">{{ weekendRestRuleAdded ? 'Weekend Rest Days Added' : 'Add Weekend Rest Days' }}</span>
                                            <span :class="['rounded-full px-2 py-1 text-[9px] font-black uppercase tracking-wider', weekendRestRuleAdded ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300' : weekendShortcutBlocked ? 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300' : 'bg-blue-100 text-blue-700 dark:bg-blue-500/15 dark:text-blue-300']">{{ weekendRestRuleAdded ? 'Added' : weekendShortcutBlocked ? 'Review' : 'Add' }}</span>
                                        </span>
                                        <span class="mt-1 block text-xs font-semibold leading-5 text-slate-500 dark:text-slate-400">{{ weekendShortcutBlocked ? 'Saturday or Sunday is used by another pattern. Remove it there first.' : 'Schedules every Saturday and Sunday as an all-day Rest Day.' }}</span>
                                    </span>
                                </button>

                                <button type="button" class="flex min-h-24 items-center gap-4 rounded-2xl border border-slate-200 bg-white p-4 text-left shadow-sm transition-shadow hover:shadow-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:border-slate-700 dark:bg-slate-800 dark:focus:ring-offset-slate-900" @click="addRule">
                                    <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-blue-100 text-blue-700 dark:bg-blue-500/15 dark:text-blue-300">
                                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v12m6-6H6"/></svg>
                                    </span>
                                    <span class="min-w-0 flex-1">
                                        <span class="flex items-center justify-between gap-2"><span class="text-sm font-black text-slate-900 dark:text-white">Add a Work Schedule Pattern</span><span class="rounded-full bg-blue-100 px-2 py-1 text-[9px] font-black uppercase tracking-wider text-blue-700 dark:bg-blue-500/15 dark:text-blue-300">Add</span></span>
                                        <span class="mt-1 block text-xs font-semibold leading-5 text-slate-500 dark:text-slate-400">Choose weekdays, duty status, location, and the same start and end times.</span>
                                    </span>
                                </button>
                            </div>
                        </div>

                        <article v-for="(rule, ruleIndex) in rules" :key="rule.id" class="rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-900">
                            <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3 dark:border-slate-800 sm:px-5">
                                <div class="flex items-center gap-3"><span class="grid h-8 w-8 place-items-center rounded-xl bg-slate-100 text-xs font-black text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ ruleIndex + 1 }}</span><h3 class="font-black text-slate-900 dark:text-white">{{ rule.status === 'Restday' ? 'Rest day pattern' : 'Duty pattern' }}</h3></div>
                                <button v-if="rules.length > 1" type="button" class="rounded-lg p-2 text-slate-400 hover:bg-rose-50 hover:text-rose-600 dark:hover:bg-rose-950/30" title="Remove rule" @click="removeRule(ruleIndex)">
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18 18 6M6 6l12 12"/></svg>
                                </button>
                            </div>
                            <div class="grid gap-5 p-4 sm:p-5 lg:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)]">
                                <div>
                                    <label class="mb-2 block text-xs font-black uppercase tracking-wider text-slate-500 dark:text-slate-400">Repeat on</label>
                                    <div class="grid grid-cols-7 gap-1.5">
                                        <button v-for="day in weekdays" :key="day.value" type="button" :disabled="weekdayUsedElsewhere(day.value, ruleIndex)" :title="weekdayUsedElsewhere(day.value, ruleIndex) ? 'Already used by another rule' : day.label" :class="['rounded-xl py-3 text-xs font-black transition', rule.weekdays.includes(day.value) ? 'bg-blue-600 text-white shadow-md shadow-blue-100 dark:shadow-none' : 'bg-slate-100 text-slate-500 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300', weekdayUsedElsewhere(day.value, ruleIndex) ? 'cursor-not-allowed opacity-35' : '']" @click="toggleWeekday(rule, day.value)">{{ day.short }}</button>
                                    </div>
                                    <p class="mt-2 text-xs font-semibold text-slate-400">{{ ruleDateSummary(rule) }}</p>
                                </div>
                                <div>
                                    <label class="mb-2 block text-xs font-black uppercase tracking-wider text-slate-500 dark:text-slate-400">Duty status</label>
                                    <select v-model="rule.status" class="w-full rounded-xl border-slate-200 text-sm font-bold focus:border-blue-500 focus:ring-blue-500 dark:border-slate-700 dark:bg-slate-800 dark:text-white" @change="onRuleStatusChange(rule)">
                                        <option v-for="status in statuses" :key="status" :value="status">{{ statusLabel(status) }}</option>
                                    </select>
                                </div>

                                <div v-if="locationRequired(rule.status)">
                                    <label class="mb-2 block text-xs font-black uppercase tracking-wider text-slate-500 dark:text-slate-400">Location</label>
                                    <Autocomplete v-model="rule.store_id" :options="locationOptions(rule.status)" label-key="name" value-key="id" placeholder="Search location..." />
                                </div>
                                <div v-else class="rounded-xl bg-slate-50 p-3 text-sm font-semibold text-slate-500 dark:bg-slate-800/60 dark:text-slate-400">
                                    {{ rule.status === 'Restday' ? 'All-day status · No location needed' : 'No location needed for this status' }}
                                </div>

                                <div v-if="rule.status !== 'Restday'" class="grid grid-cols-2 gap-3">
                                    <div><label class="mb-2 block text-xs font-black uppercase tracking-wider text-slate-500 dark:text-slate-400">Start</label><input v-model="rule.start_time" type="time" class="w-full rounded-xl border-slate-200 text-sm font-bold focus:border-blue-500 focus:ring-blue-500 dark:border-slate-700 dark:bg-slate-800 dark:text-white"></div>
                                    <div><label class="mb-2 block text-xs font-black uppercase tracking-wider text-slate-500 dark:text-slate-400">End</label><input v-model="rule.end_time" type="time" class="w-full rounded-xl border-slate-200 text-sm font-bold focus:border-blue-500 focus:ring-blue-500 dark:border-slate-700 dark:bg-slate-800 dark:text-white"></div>
                                </div>

                                <div class="lg:col-span-2">
                                    <label class="mb-2 block text-xs font-black uppercase tracking-wider text-slate-500 dark:text-slate-400">Remarks <span class="font-semibold normal-case tracking-normal text-slate-400">(optional)</span></label>
                                    <input v-model="rule.remarks" maxlength="1000" placeholder="Add a note for every date in this pattern" class="w-full rounded-xl border-slate-200 text-sm focus:border-blue-500 focus:ring-blue-500 dark:border-slate-700 dark:bg-slate-800 dark:text-white">
                                </div>
                            </div>
                        </article>
                    </div>

                    <div v-else class="space-y-5">
                        <div v-if="previewLoading" class="grid min-h-[360px] place-items-center rounded-2xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900">
                            <div class="text-center"><span class="mx-auto block h-9 w-9 animate-spin rounded-full border-4 border-blue-100 border-t-blue-600"></span><p class="mt-3 text-sm font-bold text-slate-500">Checking every schedule…</p></div>
                        </div>
                        <template v-else-if="preview">
                            <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
                                <button v-for="summary in previewSummaries" :key="summary.key" type="button" :class="['rounded-2xl border bg-white p-4 text-left shadow-sm transition dark:bg-slate-900', reviewFilter === summary.key ? summary.activeClass : 'border-slate-200 dark:border-slate-700']" @click="reviewFilter = summary.key">
                                    <span :class="['text-2xl font-black', summary.textClass]">{{ summary.count }}</span><span class="mt-1 block text-xs font-black uppercase tracking-wider text-slate-500">{{ summary.label }}</span>
                                </button>
                            </div>

                            <div class="rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-900">
                                <div class="flex flex-col gap-3 border-b border-slate-100 p-4 dark:border-slate-800 sm:flex-row sm:items-center sm:justify-between">
                                    <div><h3 class="font-black text-slate-900 dark:text-white">{{ planningPeriodLabel }} preview</h3><p class="text-xs text-slate-500">Clear a checkbox to exclude an otherwise actionable date.</p></div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <select v-if="periodType === 'year'" v-model="previewMonth" class="rounded-xl border-slate-200 py-2 text-xs font-black text-slate-600 focus:border-blue-500 focus:ring-blue-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300">
                                            <option v-for="option in monthOptions" :key="option.value" :value="option.value">View {{ option.label }}</option>
                                        </select>
                                        <button type="button" class="rounded-xl border border-slate-200 px-3 py-2 text-xs font-black text-slate-600 dark:border-slate-700 dark:text-slate-300" @click="toggleAllActionable">{{ allActionableExcluded ? 'Include all' : 'Exclude all' }}</button>
                                    </div>
                                </div>
                                <div class="divide-y divide-slate-100 dark:divide-slate-800">
                                    <details v-for="group in groupedPreviewEntries" :key="group.user_id" open class="group">
                                        <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 hover:bg-slate-50 dark:hover:bg-slate-800/50 sm:px-5">
                                            <div class="flex min-w-0 items-center gap-3"><span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-slate-100 text-xs font-black text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ initials(group.user_name) }}</span><div class="min-w-0"><div class="truncate text-sm font-black text-slate-800 dark:text-white">{{ group.user_name }}</div><div class="text-xs font-semibold text-slate-400">{{ group.entries.length }} matching dates</div></div></div>
                                            <svg class="h-5 w-5 text-slate-400 transition group-open:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m19 9-7 7-7-7"/></svg>
                                        </summary>
                                        <div class="grid gap-2 bg-slate-50/70 p-3 dark:bg-slate-950/30 sm:grid-cols-2 xl:grid-cols-3 sm:p-4">
                                            <label v-for="entry in group.entries" :key="entry.key" :class="['flex items-start gap-3 rounded-xl border bg-white p-3 dark:bg-slate-900', entry.action === 'protected' ? 'border-amber-200 dark:border-amber-900/50' : excludedKeys.has(entry.key) ? 'border-slate-200 opacity-55 dark:border-slate-700' : entry.action === 'approval' ? 'border-violet-200 dark:border-violet-900/50' : entry.action === 'replace' ? 'border-blue-200 dark:border-blue-900/50' : 'border-emerald-200 dark:border-emerald-900/50']">
                                                <input v-if="entry.action !== 'protected'" type="checkbox" :checked="!excludedKeys.has(entry.key)" class="mt-1 rounded border-slate-300 text-blue-600 focus:ring-blue-500" @change="toggleExcluded(entry.key)">
                                                <svg v-else class="mt-0.5 h-5 w-5 shrink-0 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3m0 4h.01M10.3 3.7 2.2 18a2 2 0 0 0 1.8 3h16a2 2 0 0 0 1.8-3L13.7 3.7a2 2 0 0 0-3.4 0Z"/></svg>
                                                <div class="min-w-0 flex-1"><div class="flex items-center justify-between gap-2"><span class="text-sm font-black text-slate-800 dark:text-white">{{ formatPreviewDate(entry.date) }}</span><span :class="actionBadgeClass(entry.action)" class="rounded-full px-2 py-0.5 text-[9px] font-black uppercase tracking-wider">{{ actionLabel(entry.action) }}</span></div><div class="mt-1 truncate text-xs font-bold text-slate-500">{{ statusLabel(entry.status) }}<span v-if="entry.store_name"> · {{ entry.store_name }}</span></div><div v-if="entry.action === 'replace'" class="mt-1 text-[11px] font-semibold leading-4 text-blue-600 dark:text-blue-300">The existing {{ entry.existing_statuses.join(', ') }} schedule will be replaced when this plan is saved.</div><div v-if="entry.action === 'approval'" class="mt-1 text-[11px] font-semibold leading-4 text-violet-600 dark:text-violet-300">Manager approval required. The existing {{ entry.existing_statuses.join(', ') }} schedule stays active until approved.</div><div v-if="entry.protected_reason" class="mt-1 text-[11px] font-semibold leading-4 text-amber-700 dark:text-amber-400">{{ entry.protected_reason }}</div></div>
                                            </label>
                                        </div>
                                    </details>
                                    <div v-if="groupedPreviewEntries.length === 0" class="px-4 py-12 text-center text-sm font-semibold text-slate-400">No dates match this filter.</div>
                                </div>
                            </div>
                        </template>
                    </div>
                </main>

                <footer class="flex flex-col-reverse gap-3 border-t border-slate-200 bg-white px-4 py-4 dark:border-slate-700 dark:bg-slate-900 sm:flex-row sm:items-center sm:justify-between sm:px-7">
                    <button type="button" class="rounded-xl px-5 py-2.5 text-sm font-black text-slate-500 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800" :disabled="saving" @click="step === 1 ? requestClose() : previousStep()">{{ step === 1 ? 'Cancel' : 'Back' }}</button>
                    <div class="flex flex-col-reverse gap-2 sm:flex-row sm:items-center">
                        <span v-if="step === 3 && preview" class="text-center text-xs font-bold text-slate-500 sm:mr-2">{{ actionableCount }} dates will be saved</span>
                        <button v-if="step < 3" type="button" class="rounded-xl bg-blue-600 px-6 py-2.5 text-sm font-black text-white shadow-lg shadow-blue-200 transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50 dark:shadow-none" :disabled="!canContinue" @click="nextStep">{{ step === 2 ? 'Review plan' : 'Continue' }}</button>
                        <button v-else type="button" class="rounded-xl bg-blue-600 px-6 py-2.5 text-sm font-black text-white shadow-lg shadow-blue-200 transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50 dark:shadow-none" :disabled="saving || actionableCount === 0" @click="savePlan">{{ saving ? 'Saving plan…' : 'Save schedule plan' }}</button>
                    </div>
                </footer>
            </section>
        </div>
    </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import axios from 'axios'
import Autocomplete from '@/Components/Autocomplete.vue'

const props = defineProps({ users: { type: Array, default: () => [] }, stores: { type: Array, default: () => [] }, initialMonth: { type: String, required: true }, currentUserId: { type: [Number, String], default: null } })
const emit = defineEmits(['close', 'saved'])

const steps = [{ id: 1, short: 'People' }, { id: 2, short: 'Patterns' }, { id: 3, short: 'Review' }]
const weekdays = [{ value: 1, short: 'Mon', label: 'Monday' }, { value: 2, short: 'Tue', label: 'Tuesday' }, { value: 3, short: 'Wed', label: 'Wednesday' }, { value: 4, short: 'Thu', label: 'Thursday' }, { value: 5, short: 'Fri', label: 'Friday' }, { value: 6, short: 'Sat', label: 'Saturday' }, { value: 7, short: 'Sun', label: 'Sunday' }]
const statuses = ['On-site', 'Off-site', 'WFH', 'SL', 'VL', 'Restday', 'Offset', 'Holiday', 'N/A']
const monthOptions = Array.from({ length: 12 }, (_, index) => ({ value: String(index + 1).padStart(2, '0'), label: new Intl.DateTimeFormat('en-US', { month: 'long' }).format(new Date(2026, index, 1)) }))
const optionalLocationStatuses = new Set(['SL', 'VL', 'Restday', 'Holiday', 'Offset', 'N/A'])
const step = ref(1)
const periodType = ref('month')
const month = ref(props.initialMonth)
const year = ref(Number(props.initialMonth.slice(0, 4)))
const selectedUserIds = ref(props.currentUserId && props.users.some(user => Number(user.id) === Number(props.currentUserId)) ? [Number(props.currentUserId)] : [])
const userSearch = ref('')
let nextRuleId = 1
const rules = ref([makeRule('Restday', [6, 7])])
const preview = ref(null)
const previewLoading = ref(false)
const saving = ref(false)
const errorMessage = ref('')
const excludedKeys = ref(new Set())
const reviewFilter = ref('all')
const previewMonth = ref('01')
const dialogRef = ref(null)

const stepTitle = computed(() => ['Choose the period and people', 'Build the weekly pattern', 'Review every suggested date'][step.value - 1])
const stepDescription = computed(() => ['Select a month or full year and everyone who should receive the same plan.', 'Combine rest days and duty patterns before anything is changed.', 'Existing schedules are classified before you save.'][step.value - 1])
const planningPeriodLabel = computed(() => {
    if (periodType.value === 'year') return `Full year ${year.value}`
    if (!/^\d{4}-\d{2}$/.test(month.value)) return 'Selected month'
    return new Intl.DateTimeFormat('en-US', { month: 'long', year: 'numeric' }).format(new Date(`${month.value}-01T00:00:00`))
})
const homeStoreId = computed(() => props.stores.find(store => String(store.code || '').toLowerCase() === 'home' || String(store.name || '').toLowerCase() === 'home')?.id ?? null)
const filteredUsers = computed(() => { const query = userSearch.value.trim().toLowerCase(); return props.users.filter(user => !query || String(user.name).toLowerCase().includes(query)) })
const allVisibleSelected = computed(() => filteredUsers.value.length > 0 && filteredUsers.value.every(user => isUserSelected(user.id)))
const validRules = computed(() => rules.value.length > 0 && rules.value.every(rule => rule.weekdays.length && (rule.status === 'Restday' || (rule.start_time && rule.end_time)) && (!locationRequired(rule.status) || rule.store_id)))
const weekendRestRuleAdded = computed(() => [6, 7].every(day => rules.value.some(rule => rule.status === 'Restday' && rule.weekdays.includes(day))))
const weekendShortcutBlocked = computed(() => !weekendRestRuleAdded.value && [6, 7].some(day => rules.value.some(rule => rule.status !== 'Restday' && rule.weekdays.includes(day))))
const canContinue = computed(() => step.value === 1 ? Boolean((periodType.value === 'year' ? year.value >= 2020 && year.value <= 2100 : month.value) && selectedUserIds.value.length) : validRules.value)
const actionableEntries = computed(() => preview.value?.entries.filter(entry => entry.action !== 'protected') ?? [])
const actionableCount = computed(() => actionableEntries.value.filter(entry => !excludedKeys.value.has(entry.key)).length)
const allActionableExcluded = computed(() => actionableEntries.value.length > 0 && actionableEntries.value.every(entry => excludedKeys.value.has(entry.key)))
const previewSummaries = computed(() => [
    { key: 'all', label: 'Suggested', count: preview.value?.counts.total ?? 0, textClass: 'text-slate-800 dark:text-white', activeClass: 'border-blue-400 ring-2 ring-blue-100 dark:ring-blue-900/40' },
    { key: 'create', label: 'New', count: preview.value?.counts.create ?? 0, textClass: 'text-emerald-600', activeClass: 'border-emerald-400 ring-2 ring-emerald-100 dark:ring-emerald-900/40' },
    { key: 'replace', label: 'Will replace', count: preview.value?.counts.replace ?? 0, textClass: 'text-blue-600', activeClass: 'border-blue-400 ring-2 ring-blue-100 dark:ring-blue-900/40' },
    { key: 'approval', label: 'Needs approval', count: preview.value?.counts.approval ?? 0, textClass: 'text-violet-600', activeClass: 'border-violet-400 ring-2 ring-violet-100 dark:ring-violet-900/40' },
    { key: 'protected', label: 'Protected', count: preview.value?.counts.protected ?? 0, textClass: 'text-amber-600', activeClass: 'border-amber-400 ring-2 ring-amber-100 dark:ring-amber-900/40' },
])
const groupedPreviewEntries = computed(() => {
    const entries = (preview.value?.entries ?? []).filter(entry => (reviewFilter.value === 'all' || entry.action === reviewFilter.value) && (periodType.value !== 'year' || entry.date.slice(5, 7) === previewMonth.value))
    return Object.values(entries.reduce((groups, entry) => { if (!groups[entry.user_id]) groups[entry.user_id] = { user_id: entry.user_id, user_name: entry.user_name, entries: [] }; groups[entry.user_id].entries.push(entry); return groups }, {}))
})

function makeRule(status = 'On-site', selectedDays = []) { return { id: nextRuleId++, weekdays: selectedDays, status, store_id: status === 'WFH' ? homeStoreId.value : null, start_time: '07:00', end_time: '17:00', grace_period_minutes: 30, remarks: '' } }
function locationRequired(status) { return !optionalLocationStatuses.has(status) }
function locationOptions(status) { const homeId = homeStoreId.value; if (status === 'WFH') return props.stores.filter(store => Number(store.id) === Number(homeId)); return props.stores.filter(store => Number(store.id) !== Number(homeId)).map(store => ({ ...store, name: store.code ? `${store.code} - ${store.name}` : store.name })) }
function statusLabel(status) { return status === 'Restday' ? 'Rest Day' : status }
function initials(name) { return String(name || '?').split(/\s+/).slice(0, 2).map(part => part[0]).join('').toUpperCase() }
function isUserSelected(id) { return selectedUserIds.value.includes(Number(id)) }
function toggleUser(id) { const value = Number(id); selectedUserIds.value = isUserSelected(value) ? selectedUserIds.value.filter(item => item !== value) : [...selectedUserIds.value, value] }
function toggleVisibleUsers() { const visible = filteredUsers.value.map(user => Number(user.id)); selectedUserIds.value = allVisibleSelected.value ? selectedUserIds.value.filter(id => !visible.includes(id)) : [...new Set([...selectedUserIds.value, ...visible])] }
function addRule() { rules.value.push(makeRule()) }
function addWeekendRule() { if (rules.value.some(rule => rule.status === 'Restday' && rule.weekdays.includes(6) && rule.weekdays.includes(7))) return; const available = [6, 7].filter(day => !rules.value.some(rule => rule.weekdays.includes(day))); if (available.length) rules.value.push(makeRule('Restday', available)) }
function removeRule(index) { rules.value.splice(index, 1) }
function weekdayUsedElsewhere(day, currentIndex) { return rules.value.some((rule, index) => index !== currentIndex && rule.weekdays.includes(day)) }
function toggleWeekday(rule, day) { rule.weekdays = rule.weekdays.includes(day) ? rule.weekdays.filter(value => value !== day) : [...rule.weekdays, day].sort() }
function ruleDateSummary(rule) { if (!rule.weekdays.length) return 'Choose at least one weekday'; return rule.weekdays.map(value => weekdays.find(day => day.value === value)?.label).join(', ') }
function onRuleStatusChange(rule) { if (rule.status === 'WFH') rule.store_id = homeStoreId.value; else if (!locationRequired(rule.status) || Number(rule.store_id) === Number(homeStoreId.value)) rule.store_id = null }
function payload() { return { period_type: periodType.value, month: periodType.value === 'month' ? month.value : null, year: periodType.value === 'year' ? year.value : null, user_ids: selectedUserIds.value, rules: rules.value.map(({ weekdays, status, store_id, start_time, end_time, grace_period_minutes, remarks }) => ({ weekdays, status, store_id, start_time: status === 'Restday' ? null : start_time, end_time: status === 'Restday' ? null : end_time, grace_period_minutes, remarks })) } }
function parseError(error) { const errors = error?.response?.data?.errors; if (errors) return Object.values(errors).flat().join(' '); return error?.response?.data?.message || 'The planner could not complete this request.' }
function requestClose() { if (saving.value) return; if (step.value > 1 && !window.confirm('Close the planner? Your unsaved schedule plan will be discarded.')) return; emit('close') }
function handleDialogKeydown(event) { if (event.key !== 'Tab') return; const focusable = [...dialogRef.value.querySelectorAll('button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])')].filter(element => element.offsetParent !== null); if (!focusable.length) return; const first = focusable[0]; const last = focusable[focusable.length - 1]; if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus() } else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus() } }
function previousStep() { errorMessage.value = ''; step.value-- }
async function nextStep() { errorMessage.value = ''; if (step.value === 1) { step.value = 2; return } await loadPreview() }
async function loadPreview() { previewLoading.value = true; step.value = 3; preview.value = null; excludedKeys.value = new Set(); previewMonth.value = '01'; try { const response = await axios.post('/schedules/recurring/preview', payload()); preview.value = response.data } catch (error) { errorMessage.value = parseError(error); step.value = 2 } finally { previewLoading.value = false } }
function toggleExcluded(key) { const next = new Set(excludedKeys.value); next.has(key) ? next.delete(key) : next.add(key); excludedKeys.value = next }
function toggleAllActionable() { excludedKeys.value = allActionableExcluded.value ? new Set() : new Set(actionableEntries.value.map(entry => entry.key)) }
function formatPreviewDate(date) { return new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric', weekday: 'short' }).format(new Date(`${date}T00:00:00`)) }
function actionLabel(action) { return { create: 'New', replace: 'Will replace', approval: 'Needs approval', protected: 'Protected' }[action] ?? action }
function actionBadgeClass(action) { return { create: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300', replace: 'bg-blue-100 text-blue-700 dark:bg-blue-500/15 dark:text-blue-300', approval: 'bg-violet-100 text-violet-700 dark:bg-violet-500/15 dark:text-violet-300', protected: 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300' }[action] }
async function savePlan() { saving.value = true; errorMessage.value = ''; try { const response = await axios.post('/schedules/recurring', { ...payload(), excluded_keys: [...excludedKeys.value] }); emit('saved', response.data) } catch (error) { errorMessage.value = parseError(error) } finally { saving.value = false } }

onMounted(() => { document.body.style.overflow = 'hidden'; dialogRef.value?.focus() })
onBeforeUnmount(() => { document.body.style.overflow = '' })
</script>
