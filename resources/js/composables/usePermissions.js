import { usePage } from '@inertiajs/vue3';

export function usePermissions() {
    const page = usePage();

    const can = (permission) => {
        const perms = page.props.auth?.permissions || [];
        return page.props.auth?.isAdmin || perms.includes(permission);
    };

    const canAny = (module) => {
        const perms = page.props.auth?.permissions || [];
        if (page.props.auth?.isAdmin) return true;
        return perms.some(p => p.startsWith(module + '.'));
    };

    const isAdmin = page.props.auth?.isAdmin === true;

    return { can, canAny, isAdmin };
}
