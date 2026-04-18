import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

export function usePermission() {
    const page = usePage();
    const user = computed(() => page.props.auth?.user || {});
    const userRoles = computed(() => user.value.roles?.map(r => r.name) || []);
    const permissions = computed(() => page.props.auth?.permissions || []);

    const hasPermission = (name) => {
        return permissions.value.includes(name);
    };

    const hasAnyPermission = (names) => {
        return names.some(name => hasPermission(name));
    };

    const hasRole = (name) => {
        return userRoles.value.includes(name);
    };

    const hasAnyRole = (names) => {
        return names.some(name => userRoles.value.includes(name));
    };

    return { hasPermission, hasAnyPermission, hasRole, hasAnyRole, user, userRoles };
}
