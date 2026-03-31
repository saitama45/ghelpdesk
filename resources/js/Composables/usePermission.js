import { usePage } from '@inertiajs/vue3';

export function usePermission() {
    const user = usePage().props.auth.user || {};
    const userRoles = user.roles?.map(r => r.name) || [];

    const hasPermission = (name) => {
        const permissions = usePage().props.auth.permissions || [];
        return permissions.includes(name);
    };

    const hasAnyPermission = (names) => {
        return names.some(name => hasPermission(name));
    };

    const hasRole = (name) => {
        return userRoles.includes(name);
    };

    const hasAnyRole = (names) => {
        return names.some(name => userRoles.includes(name));
    };

    return { hasPermission, hasAnyPermission, hasRole, hasAnyRole };
}
