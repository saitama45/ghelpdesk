import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { isOfferedByDepartment } from '@/Composables/useModuleRegistry';

/**
 * Reads the shared department axis (see App\Support\DepartmentContext) and turns
 * it into the handful of things the UI actually asks for: which department am I
 * looking at, am I its provider or its customer, and does a given module or
 * dynamic form belong to its catalogue.
 *
 * The provider/customer split is DERIVED, never assigned: you provide the
 * services of the department you belong to, and you are a customer of every
 * other department's services.
 */
export function useDepartmentContext() {
    const page = usePage();

    const ctx = computed(() => page.props.departmentContext || {
        home: null,
        viewed: null,
        accessView: 'customer',
        isExecutive: false,
        canSwitchHome: false,
        departments: [],
    });

    const departments = computed(() => ctx.value.departments || []);
    const viewedId = computed(() => ctx.value.viewed ?? null);
    const homeId = computed(() => ctx.value.home ?? null);

    const viewedDepartment = computed(
        () => departments.value.find((d) => d.id === viewedId.value) || null
    );
    const homeDepartment = computed(
        () => departments.value.find((d) => d.id === homeId.value) || null
    );

    const viewedCode = computed(() => viewedDepartment.value?.code || null);
    const viewedName = computed(() => viewedDepartment.value?.name || null);
    const homeName = computed(() => homeDepartment.value?.name || null);

    const isProvider = computed(() => ctx.value.accessView === 'provider');
    const isCustomer = computed(() => !isProvider.value);
    const isExecutive = computed(() => Boolean(ctx.value.isExecutive));

    /**
     * Does this registry module belong to the viewed department's catalogue?
     * Executive mode sees every department's modules — it sits above the axis.
     */
    const moduleInScope = (child) =>
        isExecutive.value || isOfferedByDepartment(child, viewedCode.value);

    /**
     * Does this dynamic form belong to the viewed department? Forms are owned by
     * exactly one department (form_definitions.department_id). An unassigned form
     * belongs to no catalogue and is surfaced for repair on /form-builder.
     */
    const formInScope = (form) => {
        if (isExecutive.value) return true;
        if (!viewedId.value) return true;
        return Number(form?.department_id) === Number(viewedId.value);
    };

    return {
        ctx,
        departments,
        viewedId,
        viewedCode,
        viewedName,
        viewedDepartment,
        homeId,
        homeName,
        homeDepartment,
        isProvider,
        isCustomer,
        isExecutive,
        moduleInScope,
        formInScope,
    };
}
