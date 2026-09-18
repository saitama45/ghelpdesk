<script setup>
import { computed, ref } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { usePermission } from '@/Composables/usePermission';
import Autocomplete from '@/Components/Autocomplete.vue';

/**
 * The service department that owns one catalogue row (cluster, category,
 * sub-category, item). Stores and vendors have no tag — they are shared by every
 * department of their entity — so this component is never used for them.
 */
const props = defineProps({ row: Object, type: String });
const page = usePage();
const { hasPermission } = usePermission();

const context = computed(() => page.props.departmentContext || {});
const departments = computed(() => (context.value.departments || []).filter(d => d.is_active !== false));
const canManage = id => hasPermission('departments.edit') || (id && String(context.value.home) === String(id));
const owner = computed(() => departments.value.find(d => String(d.id) === String(props.row.department_id)));
const options = computed(() => departments.value
    .filter(d => canManage(d.id) || String(d.id) === String(props.row.department_id))
    .map(d => ({ value: d.id, label: d.code ? `${d.name} (${d.code})` : d.name })));

const module = computed(() => (props.type === 'sub_categories' ? 'subcategories' : props.type));
const ownedHere = computed(() => !props.row.company_id
    || String(props.row.company_id) === String(page.props.activeCompany?.id));
const editable = computed(() => hasPermission(`${module.value}.edit`) && ownedHere.value
    && (!props.row.department_id || canManage(props.row.department_id))
    && options.value.length > 0);

// A row inherited from another entity is managed where it lives, and an entity
// with no service departments has no tag to show.
const visible = computed(() => ownedHere.value && departments.value.length > 0);

const editing = ref(false);
const form = useForm({ department_id: null });

const open = () => {
    form.clearErrors();
    form.department_id = props.row.department_id || context.value.viewed || null;
    editing.value = true;
};

const save = () => {
    if (!form.department_id) return;
    form.put(route('reference-departments.update', { type: props.type, id: props.row.id }), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => { editing.value = false; },
    });
};
</script>

<template>
    <div v-if="visible" class="mt-1 text-xs whitespace-normal">
        <template v-if="!editing">
            <span class="text-slate-500 dark:text-slate-400">
                Service department:
                <span v-if="owner" class="font-medium text-slate-700 dark:text-slate-200">{{ owner.name }}</span>
                <span v-else class="font-medium text-amber-600 dark:text-amber-400">Not tagged</span>
            </span>
            <button v-if="editable" type="button" @click.stop="open"
                class="ml-2 text-blue-600 underline hover:text-blue-800 dark:text-blue-400">
                {{ owner ? 'Change' : 'Tag department' }}
            </button>
        </template>
        <div v-else class="mt-1 flex flex-wrap items-center gap-2" @click.stop>
            <div class="w-56">
                <Autocomplete v-model="form.department_id" :options="options" size="sm"
                    placeholder="Select a department" />
            </div>
            <button type="button" :disabled="form.processing || !form.department_id" @click="save"
                class="rounded bg-blue-600 px-2 py-1 text-white disabled:opacity-50">Save</button>
            <button type="button" :disabled="form.processing" @click="editing = false"
                class="rounded border border-slate-300 px-2 py-1 text-slate-600 dark:border-slate-600 dark:text-slate-300">Cancel</button>
        </div>
        <p v-for="(error, key) in form.errors" :key="key" class="text-red-600">{{ error }}</p>
    </div>
</template>
