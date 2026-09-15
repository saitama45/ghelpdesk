<script setup>
/**
 * Marks a reference row inherited from a tagged entity (see useEntityOwnership).
 * variant="badge" -> amber "From: TGI" pill; variant="lock" -> read-only lock
 * shown in place of the row's edit/delete buttons.
 */
import { computed } from 'vue';
import { useEntityOwnership } from '@/Composables/useEntityOwnership';

const props = defineProps({
    row: { type: Object, required: true },
    variant: { type: String, default: 'badge' },
});

const { isInherited } = useEntityOwnership();
const inherited = computed(() => isInherited(props.row));
const ownerName = computed(() => props.row.company?.name || 'another entity');
</script>

<template>
    <span
        v-if="inherited && variant === 'badge'"
        class="inline-flex px-2 py-0.5 rounded-full text-[10px] font-semibold bg-amber-100 text-amber-800 border border-amber-200 uppercase tracking-wider"
        :title="`Shared from ${ownerName}; manage it by switching to that entity`"
    >
        From: {{ row.company?.code || row.company?.name || 'Entity' }}
    </span>
    <span
        v-else-if="inherited && variant === 'lock'"
        class="p-2 text-gray-400 dark:text-gray-500"
        :title="`Read-only: managed under ${ownerName}`"
    >
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
        </svg>
    </span>
</template>
