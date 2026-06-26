<script setup>
import { computed, watch } from 'vue'
import { Head, useForm } from '@inertiajs/vue3'
import Autocomplete from '@/Components/Autocomplete.vue'

const props = defineProps({
    token: { type: String, required: true },
    orgName: { type: String, default: 'Support Queue' },
    stores: { type: Array, default: () => [] },
    items: { type: Array, default: () => [] },
    requireEmail: { type: Boolean, default: false },
})

const route = window.route

const form = useForm({
    sender_name: '',
    sender_email: '',
    department: '',
    store_id: null,
    item_id: null,
    title: '',
    description: '',
})

const storeOptions = computed(() => props.stores.map(s => ({ label: s.name, value: s.id })))
const itemOptions = computed(() => props.items.map(i => ({ label: i.name, value: i.id })))

// Prefill the summary from the chosen concern if the visitor hasn't typed one.
watch(() => form.item_id, (id) => {
    if (!id) return
    const item = props.items.find(i => i.id === id)
    if (item && !form.title.trim()) form.title = item.name
})

const submit = () => {
    form.post(route('public.queue.kiosk.store', props.token), { preserveScroll: true })
}
</script>

<template>
    <Head title="Walk-in Check-in" />

    <div class="min-h-screen bg-gray-50 flex flex-col items-center justify-center p-4 dark:bg-gray-900/50">
        <div class="max-w-xl w-full bg-white rounded-3xl shadow-xl overflow-hidden border border-gray-100 dark:bg-gray-800 dark:border-gray-700">
            <!-- Header -->
            <div class="p-6 text-center border-b border-gray-50 bg-blue-50/40 dark:border-gray-700">
                <img src="/images/company_logo.png" alt="Logo" class="h-12 mx-auto mb-3 object-contain">
                <h1 class="text-xl font-bold text-gray-900 dark:text-gray-100">{{ orgName }} — Check in</h1>
                <p class="text-sm text-gray-500 dark:text-gray-300">Fill this in to join the queue. You'll get a ticket number to track your place.</p>
            </div>

            <!-- Form -->
            <form @submit.prevent="submit" class="p-6 sm:p-8 space-y-5">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1 dark:text-gray-300">Your name <span class="text-red-500">*</span></label>
                    <input v-model="form.sender_name" type="text" placeholder="Full name"
                           class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl focus:ring-4 focus:ring-blue-500/10 focus:border-blue-500 transition-all text-gray-800 dark:bg-gray-900 dark:text-gray-200 dark:border-gray-700" />
                    <p v-if="form.errors.sender_name" class="text-red-500 text-xs mt-1">{{ form.errors.sender_name }}</p>
                </div>

                <div class="grid sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1 dark:text-gray-300">
                            Email <span v-if="requireEmail" class="text-red-500">*</span><span v-else class="text-gray-400 font-normal">(optional)</span>
                        </label>
                        <input v-model="form.sender_email" type="email" placeholder="you@email.com"
                               class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl focus:ring-4 focus:ring-blue-500/10 focus:border-blue-500 transition-all text-gray-800 dark:bg-gray-900 dark:text-gray-200 dark:border-gray-700" />
                        <p v-if="form.errors.sender_email" class="text-red-500 text-xs mt-1">{{ form.errors.sender_email }}</p>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1 dark:text-gray-300">Department <span class="text-gray-400 font-normal">(optional)</span></label>
                        <input v-model="form.department" type="text" placeholder="e.g. Operations"
                               class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl focus:ring-4 focus:ring-blue-500/10 focus:border-blue-500 transition-all text-gray-800 dark:bg-gray-900 dark:text-gray-200 dark:border-gray-700" />
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1 dark:text-gray-300">Your store / branch</label>
                    <Autocomplete v-model="form.store_id" :options="storeOptions" placeholder="Search your store..." />
                    <p v-if="form.errors.store_id" class="text-red-500 text-xs mt-1">{{ form.errors.store_id }}</p>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1 dark:text-gray-300">What is this about?</label>
                    <Autocomplete v-model="form.item_id" :options="itemOptions" placeholder="Search a concern (e.g. POS down)..." />
                    <p class="text-xs text-gray-400 mt-1">This helps us route you to the right team faster.</p>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1 dark:text-gray-300">Brief summary <span class="text-red-500">*</span></label>
                    <input v-model="form.title" type="text" placeholder="One line about your concern"
                           class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl focus:ring-4 focus:ring-blue-500/10 focus:border-blue-500 transition-all text-gray-800 dark:bg-gray-900 dark:text-gray-200 dark:border-gray-700" />
                    <p v-if="form.errors.title" class="text-red-500 text-xs mt-1">{{ form.errors.title }}</p>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1 dark:text-gray-300">Details <span class="text-gray-400 font-normal">(optional)</span></label>
                    <textarea v-model="form.description" rows="3" placeholder="Anything that helps us prepare..."
                              class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl focus:ring-4 focus:ring-blue-500/10 focus:border-blue-500 transition-all resize-none text-gray-800 dark:bg-gray-900 dark:text-gray-200 dark:border-gray-700"></textarea>
                </div>

                <button type="submit" :disabled="form.processing"
                        class="w-full py-4 bg-blue-600 hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed text-white font-bold text-lg rounded-xl shadow-lg shadow-blue-500/30 transition-all active:scale-[0.98]">
                    <span v-if="form.processing">Joining the queue...</span>
                    <span v-else>Get my number</span>
                </button>
            </form>
        </div>
    </div>
</template>
