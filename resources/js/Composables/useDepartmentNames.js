/**
 * One department, one name.
 *
 * A task's department is resolved from free-text columns — `project_tasks.department`
 * for process accountability, with `users.department` as a fallback — so the same
 * department reaches the UI under more than one spelling. On the live data
 * "Technology and Solutions" and "Technology And Solutions" both exist on user
 * records, which made the project filter dropdowns offer the same department
 * twice.
 *
 * The `departments` table is this app's single source for the list (see the
 * project's "departments are a single source" rule), so it decides the canonical
 * spelling. A name with no match there keeps its own text, trimmed — the point is
 * to stop showing one department twice, not to hide departments the table has yet
 * to catch up with.
 */

/** Comparison key: trimmed, whitespace-collapsed, case-folded. */
export const departmentKey = (name) => String(name ?? '').trim().replace(/\s+/g, ' ').toLowerCase();

/** Do these two strings name the same department? Empty never matches anything. */
export const sameDepartment = (a, b) => {
    const key = departmentKey(a);
    return key !== '' && key === departmentKey(b);
};

const canonicalMap = (departments = []) => {
    const map = new Map();
    (departments || []).forEach((entry) => {
        const name = typeof entry === 'string' ? entry : entry?.name;
        const key = departmentKey(name);
        if (key && !map.has(key)) map.set(key, String(name).trim());
    });
    return map;
};

/**
 * The canonical spelling of one name, or the name itself (trimmed) when the
 * departments table has no match for it.
 */
export const canonicalDepartment = (name, departments = []) => {
    const key = departmentKey(name);
    if (!key) return '';
    return canonicalMap(departments).get(key) || String(name).trim().replace(/\s+/g, ' ');
};

/**
 * De-duplicated, canonicalised, alphabetically sorted department names.
 *
 * Where the departments table has no entry, the spelling used by the most rows
 * wins (ties broken alphabetically) so the label is stable between renders rather
 * than depending on which task happened to be read first.
 */
export const uniqueDepartmentNames = (names = [], departments = []) => {
    const canonical = canonicalMap(departments);
    const buckets = new Map();

    (names || []).forEach((name) => {
        const key = departmentKey(name);
        if (!key) return;

        if (!buckets.has(key)) buckets.set(key, new Map());
        const spellings = buckets.get(key);
        const spelling = String(name).trim().replace(/\s+/g, ' ');
        spellings.set(spelling, (spellings.get(spelling) || 0) + 1);
    });

    return [...buckets.entries()]
        .map(([key, spellings]) => canonical.get(key) || [...spellings.entries()]
            .sort((a, b) => (b[1] - a[1]) || a[0].localeCompare(b[0]))[0][0])
        .sort((a, b) => a.localeCompare(b));
};
