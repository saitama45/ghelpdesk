<script setup>
import { ref, computed, nextTick, watch } from 'vue';

const props = defineProps({
    modelValue: { type: String, default: '' },
    users: { type: Array, default: () => [] }, // [{ id, name, email }]
    placeholder: { type: String, default: '' },
    rows: { type: [Number, String], default: 3 },
    disabled: { type: Boolean, default: false },
    inputClass: { type: String, default: '' },
});

const emit = defineEmits(['update:modelValue', 'update:mentions']);

const textareaRef = ref(null);
const showMenu = ref(false);
const query = ref('');
const activeIndex = ref(0);
const menuStyle = ref({ top: '0px', left: '0px' });

// Tracks users picked via the menu: token ("@Full Name") -> id
const picked = ref(new Map());

const text = computed({
    get: () => props.modelValue,
    set: (v) => emit('update:modelValue', v),
});

const filtered = computed(() => {
    const q = query.value.trim().toLowerCase();
    const list = props.users || [];
    if (!q) return list.slice(0, 6);
    return list
        .filter(u =>
            (u.name || '').toLowerCase().includes(q) ||
            (u.email || '').toLowerCase().includes(q))
        .slice(0, 6);
});

const emitMentions = () => {
    const body = text.value || '';
    const ids = [];
    picked.value.forEach((id, token) => {
        if (body.includes(token)) ids.push(id);
    });
    emit('update:mentions', [...new Set(ids)]);
};

const onInput = (e) => {
    text.value = e.target.value;
    detectQuery();
    emitMentions();
};

// Find an "@query" fragment immediately before the caret (query has no spaces).
const detectQuery = () => {
    const el = textareaRef.value;
    if (!el) return;
    const caret = el.selectionStart ?? text.value.length;
    const before = text.value.slice(0, caret);
    const match = before.match(/(?:^|\s)@(\S{0,30})$/);
    if (match) {
        query.value = match[1] || '';
        activeIndex.value = 0;
        showMenu.value = true;
        nextTick(positionMenu);
    } else {
        showMenu.value = false;
    }
};

const positionMenu = () => {
    // Simple anchored menu just under the textarea.
    menuStyle.value = { top: '100%', left: '0px' };
};

const selectUser = (user) => {
    const el = textareaRef.value;
    const caret = el?.selectionStart ?? text.value.length;
    const before = text.value.slice(0, caret);
    const after = text.value.slice(caret);
    const token = `@${user.name}`;

    const newBefore = before.replace(/(^|\s)@(\S{0,30})$/, (m, pre) => `${pre}${token} `);
    text.value = newBefore + after;

    picked.value.set(token, Number(user.id));
    showMenu.value = false;
    emitMentions();

    nextTick(() => {
        const pos = newBefore.length;
        el?.focus();
        el?.setSelectionRange(pos, pos);
    });
};

const onKeydown = (e) => {
    if (!showMenu.value || filtered.value.length === 0) return;
    if (e.key === 'ArrowDown') {
        e.preventDefault();
        activeIndex.value = (activeIndex.value + 1) % filtered.value.length;
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        activeIndex.value = (activeIndex.value - 1 + filtered.value.length) % filtered.value.length;
    } else if (e.key === 'Enter' || e.key === 'Tab') {
        e.preventDefault();
        selectUser(filtered.value[activeIndex.value]);
    } else if (e.key === 'Escape') {
        showMenu.value = false;
    }
};

// Reset tracked mentions when the field is cleared externally (e.g. after submit).
watch(() => props.modelValue, (v) => {
    if (!v) {
        picked.value.clear();
        emit('update:mentions', []);
    }
});
</script>

<template>
    <div class="relative">
        <textarea
            ref="textareaRef"
            :value="text"
            :rows="rows"
            :placeholder="placeholder"
            :disabled="disabled"
            :class="inputClass"
            @input="onInput"
            @keydown="onKeydown"
            @click="detectQuery"
            @blur="showMenu = false"
        ></textarea>

        <ul
            v-if="showMenu && filtered.length"
            :style="menuStyle"
            class="absolute z-50 mt-1 w-64 max-h-56 overflow-y-auto rounded-lg border border-gray-200 bg-white shadow-xl dark:bg-gray-800 dark:border-gray-700"
        >
            <li
                v-for="(u, i) in filtered"
                :key="u.id"
                @mousedown.prevent="selectUser(u)"
                class="flex cursor-pointer items-center gap-2 px-3 py-2 text-sm transition-colors"
                :class="i === activeIndex ? 'bg-blue-50 dark:bg-blue-500/15' : 'hover:bg-gray-50 dark:hover:bg-gray-700'"
            >
                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-blue-100 text-[10px] font-black text-blue-700 dark:bg-blue-500/20 dark:text-blue-300">
                    {{ (u.name || '?').charAt(0) }}
                </span>
                <span class="min-w-0">
                    <span class="block truncate font-semibold text-gray-800 dark:text-gray-200">{{ u.name }}</span>
                    <span v-if="u.email" class="block truncate text-[11px] text-gray-400">{{ u.email }}</span>
                </span>
            </li>
        </ul>
    </div>
</template>
