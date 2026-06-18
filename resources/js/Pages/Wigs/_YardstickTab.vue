<template>
    <div class="space-y-8">
        <!-- Toolbar -->
        <div class="flex items-center justify-between bg-white rounded-2xl border border-gray-100 shadow-sm px-5 py-3.5 dark:bg-gray-800 dark:border-gray-700">
            <div>
                <p class="text-sm font-black text-gray-800 dark:text-gray-200">Yardstick Configuration</p>
                <p class="text-xs text-gray-400 mt-0.5 dark:text-gray-400">Performance standards, TRACK values, rating definitions &amp; quarterly guidelines</p>
            </div>
            <div v-if="canEdit" class="flex items-center gap-2">
                <template v-if="!editing">
                    <button @click="startEdit"
                            class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 text-white text-sm font-bold rounded-xl hover:bg-blue-700 transition-colors shadow-sm">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                        </svg>
                        Edit Yardstick
                    </button>
                </template>
                <template v-else>
                    <button @click="cancelEdit"
                            class="px-4 py-2 bg-gray-100 text-gray-600 text-sm font-bold rounded-xl hover:bg-gray-200 transition-colors dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">
                        Cancel
                    </button>
                    <button @click="save" :disabled="saving"
                            class="inline-flex items-center gap-2 px-4 py-2 bg-green-600 text-white text-sm font-bold rounded-xl hover:bg-green-700 transition-colors shadow-sm disabled:opacity-50">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                        </svg>
                        {{ saving ? 'Saving…' : 'Save Changes' }}
                    </button>
                </template>
            </div>
        </div>

        <!-- Performance Standards -->
        <section class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden dark:bg-gray-800 dark:border-gray-700">
            <div class="px-5 py-3 bg-gray-50 border-b border-gray-100 flex items-center justify-between dark:bg-gray-900/50 dark:border-gray-700">
                <h2 class="text-sm font-black uppercase tracking-wider text-gray-700 dark:text-gray-300">Performance Standards</h2>
                <button v-if="editing" @click="addStandard" class="text-xs font-bold text-blue-600 hover:text-blue-800">+ Add Row</button>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="bg-gray-50 text-[11px] uppercase tracking-wider text-gray-500 dark:bg-gray-900/50 dark:text-gray-300">
                            <th class="px-3 py-2 text-left font-bold">General</th>
                            <th class="px-3 py-2 text-left font-bold">Specific</th>
                            <th class="px-3 py-2 text-left font-bold text-green-700">Rating 4</th>
                            <th class="px-3 py-2 text-left font-bold">Rating 3</th>
                            <th class="px-3 py-2 text-left font-bold">Rating 2</th>
                            <th class="px-3 py-2 text-left font-bold text-red-700">Rating 1</th>
                            <th v-if="editing" class="px-2 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        <tr v-for="(s, i) in form.standards" :key="i" class="align-top hover:bg-gray-50/60">
                            <template v-if="editing">
                                <td class="px-2 py-2"><textarea v-model="s.general" rows="2" class="w-40 text-xs border-gray-200 rounded dark:border-gray-700"></textarea></td>
                                <td class="px-2 py-2"><textarea v-model="s.specific" rows="2" class="w-48 text-xs border-gray-200 rounded dark:border-gray-700"></textarea></td>
                                <td class="px-2 py-2"><textarea v-model="s.rating_4" rows="2" class="w-44 text-xs border-gray-200 rounded dark:border-gray-700"></textarea></td>
                                <td class="px-2 py-2"><textarea v-model="s.rating_3" rows="2" class="w-44 text-xs border-gray-200 rounded dark:border-gray-700"></textarea></td>
                                <td class="px-2 py-2"><textarea v-model="s.rating_2" rows="2" class="w-44 text-xs border-gray-200 rounded dark:border-gray-700"></textarea></td>
                                <td class="px-2 py-2"><textarea v-model="s.rating_1" rows="2" class="w-44 text-xs border-gray-200 rounded dark:border-gray-700"></textarea></td>
                                <td class="px-2 py-2 text-center">
                                    <button @click="form.standards.splice(i, 1)" class="text-red-500 hover:text-red-700 text-xs font-bold">✕</button>
                                </td>
                            </template>
                            <template v-else>
                                <td class="px-3 py-2 font-bold text-gray-800 dark:text-gray-200">{{ s.general }}</td>
                                <td class="px-3 py-2 text-gray-600 dark:text-gray-300">{{ s.specific }}</td>
                                <td class="px-3 py-2 text-gray-600 bg-green-50/40 dark:text-gray-300">{{ s.rating_4 }}</td>
                                <td class="px-3 py-2 text-gray-600 dark:text-gray-300">{{ s.rating_3 }}</td>
                                <td class="px-3 py-2 text-gray-600 dark:text-gray-300">{{ s.rating_2 }}</td>
                                <td class="px-3 py-2 text-gray-600 bg-red-50/40 dark:text-gray-300">{{ s.rating_1 }}</td>
                            </template>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Quarter Guidelines -->
        <section class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden dark:bg-gray-800 dark:border-gray-700">
            <div class="px-5 py-3 bg-gray-50 border-b border-gray-100 dark:bg-gray-900/50 dark:border-gray-700">
                <h2 class="text-sm font-black uppercase tracking-wider text-gray-700 dark:text-gray-300">Quarterly Guideline</h2>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3 p-4">
                <div v-for="(g, i) in form.guidelines" :key="i" class="border border-gray-100 rounded-lg p-3 bg-gray-50/50 dark:border-gray-700">
                    <p class="text-xs font-black text-blue-600">Q{{ g.quarter }}</p>
                    <template v-if="editing">
                        <input v-model="g.value_name" placeholder="Value" class="w-full mt-1 text-xs border-gray-200 rounded dark:border-gray-700" />
                        <textarea v-model="g.description" rows="2" placeholder="Description" class="w-full mt-1 text-xs border-gray-200 rounded dark:border-gray-700"></textarea>
                    </template>
                    <template v-else>
                        <p class="text-sm font-bold text-gray-800 mt-0.5 dark:text-gray-200">{{ g.value_name }}</p>
                        <p class="text-xs text-gray-500 mt-1 dark:text-gray-300">{{ g.description }}</p>
                    </template>
                </div>
            </div>
        </section>

        <!-- TRACK Values & Guide Questions -->
        <section class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden dark:bg-gray-800 dark:border-gray-700">
            <div class="px-5 py-3 bg-gray-50 border-b border-gray-100 flex items-center justify-between dark:bg-gray-900/50 dark:border-gray-700">
                <h2 class="text-sm font-black uppercase tracking-wider text-gray-700 dark:text-gray-300">TRACK Questions &amp; Guide Questions</h2>
                <button v-if="editing" @click="addValue" class="text-xs font-bold text-blue-600 hover:text-blue-800">+ Add Value</button>
            </div>
            <div class="divide-y divide-gray-100 dark:divide-gray-700">
                <div v-for="(v, i) in form.values" :key="i" class="p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex-1">
                            <template v-if="editing">
                                <input v-model="v.name" placeholder="Value name" class="font-bold text-sm border-gray-200 rounded w-64 dark:border-gray-700" />
                                <textarea v-model="v.track_question" rows="2" placeholder="TRACK question" class="w-full mt-2 text-xs border-gray-200 rounded dark:border-gray-700"></textarea>
                            </template>
                            <template v-else>
                                <p class="text-sm font-black text-gray-800 dark:text-gray-200">{{ v.name }}</p>
                                <p class="text-xs text-gray-600 italic mt-1 dark:text-gray-300">{{ v.track_question }}</p>
                            </template>
                        </div>
                        <button v-if="editing" @click="form.values.splice(i, 1)" class="text-red-500 hover:text-red-700 text-xs font-bold">✕</button>
                    </div>
                    <ul class="mt-3 ml-1 space-y-1">
                        <li v-for="(q, qi) in v.guide_questions" :key="qi" class="flex items-start gap-2">
                            <span class="text-blue-400 text-xs mt-1">•</span>
                            <template v-if="editing">
                                <textarea v-model="v.guide_questions[qi]" rows="1" class="flex-1 text-xs border-gray-200 rounded dark:border-gray-700"></textarea>
                                <button @click="v.guide_questions.splice(qi, 1)" class="text-red-400 hover:text-red-600 text-xs">✕</button>
                            </template>
                            <span v-else class="text-xs text-gray-600 dark:text-gray-300">{{ q }}</span>
                        </li>
                    </ul>
                    <button v-if="editing" @click="v.guide_questions.push('')" class="mt-2 text-[11px] font-bold text-blue-600 hover:text-blue-800">+ Add guide question</button>
                </div>
            </div>
        </section>

        <!-- TRACK Rating Definitions -->
        <section class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden dark:bg-gray-800 dark:border-gray-700">
            <div class="px-5 py-3 bg-gray-50 border-b border-gray-100 dark:bg-gray-900/50 dark:border-gray-700">
                <h2 class="text-sm font-black uppercase tracking-wider text-gray-700 dark:text-gray-300">TRACK Rating Definition</h2>
            </div>
            <div class="divide-y divide-gray-100 dark:divide-gray-700">
                <div v-for="(r, i) in form.ratings" :key="i" class="flex items-start gap-4 p-4">
                    <span class="flex-shrink-0 w-9 h-9 rounded-full bg-blue-600 text-white font-black flex items-center justify-center text-sm">{{ r.rating }}</span>
                    <template v-if="editing">
                        <input v-model="r.rating" class="w-12 text-center text-sm border-gray-200 rounded font-bold dark:border-gray-700" />
                        <textarea v-model="r.description" rows="2" class="flex-1 text-xs border-gray-200 rounded dark:border-gray-700"></textarea>
                    </template>
                    <p v-else class="text-xs text-gray-600 leading-relaxed dark:text-gray-300">{{ r.description }}</p>
                </div>
            </div>
        </section>
    </div>
</template>

<script setup>
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { useToast } from '@/Composables/useToast'

const props = defineProps({
    yardstick: { type: Object, required: true },
    canEdit: { type: Boolean, default: false },
})

const { showSuccess, showError } = useToast()

const editing = ref(false)
const saving = ref(false)

const clone = (obj) => JSON.parse(JSON.stringify(obj))

const buildForm = () => ({
    standards: clone(props.yardstick.standards || []),
    values: (props.yardstick.values || []).map(v => ({
        name: v.name,
        track_question: v.track_question,
        guide_questions: [...(v.guide_questions || [])],
    })),
    ratings: clone(props.yardstick.ratings || []),
    guidelines: clone(props.yardstick.guidelines || []),
})

const form = ref(buildForm())

const startEdit = () => { form.value = buildForm(); editing.value = true }
const cancelEdit = () => { form.value = buildForm(); editing.value = false }

const addStandard = () => form.value.standards.push({ general: '', specific: '', rating_4: '', rating_3: '', rating_2: '', rating_1: '' })
const addValue = () => form.value.values.push({ name: '', track_question: '', guide_questions: [''] })

const save = () => {
    saving.value = true
    router.put(route('wigs.yardstick.save'), form.value, {
        preserveScroll: true,
        onSuccess: () => { editing.value = false; showSuccess('Yardstick configuration saved.') },
        onError: () => showError('Could not save the Yardstick. Please review the fields.'),
        onFinish: () => { saving.value = false },
    })
}
</script>
