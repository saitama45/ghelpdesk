<script setup>
import { computed, ref, onMounted, onUnmounted } from 'vue';
import { usePage, router } from '@inertiajs/vue3';
import { BuildingOffice2Icon, ChevronUpDownIcon, CheckIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    isCollapsed: {
        type: Boolean,
        default: false,
    },
});

const page = usePage();

const activeCompany = computed(() => page.props.activeCompany || null);
const availableCompanies = computed(() => page.props.availableCompanies || []);
const canSwitch = computed(() => availableCompanies.value.length > 1);

const logoUrl = (company) => (company && company.logo ? '/serve-storage/' + company.logo : null);

const open = ref(false);
const rootRef = ref(null);
const switching = ref(false);

const toggle = () => {
    if (!canSwitch.value) return;
    open.value = !open.value;
};

const close = () => {
    open.value = false;
};

const isActive = (company) => activeCompany.value && company.id === activeCompany.value.id;

const selectCompany = (company) => {
    if (isActive(company) || switching.value) {
        close();
        return;
    }

    switching.value = true;
    router.post(
        route('companies.switch'),
        { company_id: company.id },
        {
            preserveScroll: true,
            onFinish: () => {
                switching.value = false;
                close();
            },
        },
    );
};

const handleClickOutside = (event) => {
    if (rootRef.value && !rootRef.value.contains(event.target)) {
        close();
    }
};

onMounted(() => document.addEventListener('click', handleClickOutside));
onUnmounted(() => document.removeEventListener('click', handleClickOutside));
</script>

<template>
    <div v-if="activeCompany" ref="rootRef" class="relative">
        <!-- Trigger -->
        <button
            type="button"
            @click.stop="toggle"
            :class="[
                'w-full flex items-center rounded-lg border border-gray-200 bg-gray-50 transition-colors dark:border-gray-700 dark:bg-gray-800/60',
                canSwitch ? 'hover:bg-gray-100 cursor-pointer dark:hover:bg-gray-800' : 'cursor-default',
                isCollapsed ? 'justify-center p-2' : 'px-3 py-2',
            ]"
            :title="isCollapsed ? activeCompany.name : (canSwitch ? 'Switch entity' : activeCompany.name)"
        >
            <span
                class="h-8 w-8 rounded-md overflow-hidden flex items-center justify-center flex-shrink-0"
                :class="!logoUrl(activeCompany) ? 'bg-gray-600' : ''"
                :style="logoUrl(activeCompany) ? 'background-color: #ffffff;' : ''"
            >
                <img v-if="logoUrl(activeCompany)" :src="logoUrl(activeCompany)" :alt="activeCompany.name" class="h-8 w-8 object-contain" />
                <BuildingOffice2Icon v-else class="h-5 w-5 text-gray-300" />
            </span>
            <template v-if="!isCollapsed">
                <span class="ml-3 flex-1 min-w-0 text-left">
                    <span class="block text-[10px] font-semibold uppercase tracking-wider text-gray-500">Entity</span>
                    <span class="block text-sm font-semibold text-gray-800 truncate dark:text-white">{{ activeCompany.name }}</span>
                </span>
                <ChevronUpDownIcon v-if="canSwitch" class="ml-2 h-5 w-5 text-gray-400 flex-shrink-0" />
            </template>
        </button>

        <!-- Dropdown -->
        <div
            v-if="open && canSwitch"
            :class="[
                'absolute z-[90] bottom-full mb-2 rounded-lg border border-gray-700 bg-gray-900 shadow-2xl overflow-hidden',
                isCollapsed ? 'left-0 w-60' : 'left-0 right-0',
            ]"
        >
            <div class="px-3 py-2 border-b border-gray-800">
                <p class="text-[10px] font-black uppercase tracking-widest text-gray-500">Switch Entity</p>
            </div>
            <div class="max-h-72 overflow-y-auto py-1">
                <button
                    v-for="company in availableCompanies"
                    :key="company.id"
                    type="button"
                    @click="selectCompany(company)"
                    :class="[
                        'w-full flex items-center px-3 py-2 text-left transition-colors',
                        isActive(company) ? 'bg-gray-800' : 'hover:bg-gray-800',
                    ]"
                >
                    <span
                        class="h-7 w-7 rounded-md overflow-hidden flex items-center justify-center flex-shrink-0"
                        :class="!logoUrl(company) ? 'bg-gray-600' : ''"
                        :style="logoUrl(company) ? 'background-color: #ffffff;' : ''"
                    >
                        <img v-if="logoUrl(company)" :src="logoUrl(company)" :alt="company.name" class="h-7 w-7 object-contain" />
                        <BuildingOffice2Icon v-else class="h-4 w-4 text-gray-300" />
                    </span>
                    <span class="ml-3 flex-1 min-w-0">
                        <span class="block text-sm font-medium text-white truncate">{{ company.name }}</span>
                        <span v-if="company.code" class="block text-xs text-gray-500 truncate">{{ company.code }}</span>
                    </span>
                    <CheckIcon v-if="isActive(company)" class="ml-2 h-4 w-4 text-blue-400 flex-shrink-0" />
                </button>
            </div>
        </div>
    </div>
</template>
