import { ref, computed } from 'vue';

const STORAGE_KEY = 'ghelpdesk.theme';

export const THEMES = {
    LIGHT: 'light',
    DARK: 'dark',
    SYSTEM: 'system',
};

const theme = ref(THEMES.SYSTEM);
const isSystemDark = ref(false);
let mediaQuery = null;
let mediaListener = null;
let initialized = false;

const resolveDark = () => {
    if (theme.value === THEMES.LIGHT) {
        return false;
    }
    if (theme.value === THEMES.DARK) {
        return true;
    }
    return isSystemDark.value;
};

const isDark = computed(() => resolveDark());
const resolvedTheme = computed(() => (resolveDark() ? THEMES.DARK : THEMES.LIGHT));

const applyToDom = () => {
    if (typeof document === 'undefined') {
        return;
    }
    document.documentElement.classList.toggle('dark', resolveDark());
};

const persist = (value) => {
    try {
        window.localStorage.setItem(STORAGE_KEY, value);
    } catch (error) {
        // Ignore storage failures so theming still works in restricted browsers.
    }
};

const loadStored = () => {
    try {
        const stored = window.localStorage.getItem(STORAGE_KEY);
        if (stored === THEMES.LIGHT || stored === THEMES.DARK || stored === THEMES.SYSTEM) {
            return stored;
        }
    } catch (error) {
        // Ignore read failures.
    }
    return THEMES.SYSTEM;
};

const onSystemChange = (event) => {
    isSystemDark.value = event.matches;
    if (theme.value === THEMES.SYSTEM) {
        applyToDom();
    }
};

const setTheme = (value) => {
    if (value !== THEMES.LIGHT && value !== THEMES.DARK && value !== THEMES.SYSTEM) {
        return;
    }
    theme.value = value;
    persist(value);
    applyToDom();
};

const toggle = () => {
    setTheme(resolveDark() ? THEMES.LIGHT : THEMES.DARK);
};

const init = () => {
    if (initialized || typeof window === 'undefined') {
        return;
    }
    initialized = true;

    theme.value = loadStored();

    if (window.matchMedia) {
        mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');
        isSystemDark.value = mediaQuery.matches;
        mediaListener = (event) => onSystemChange(event);
        if (mediaQuery.addEventListener) {
            mediaQuery.addEventListener('change', mediaListener);
        } else if (mediaQuery.addListener) {
            mediaQuery.addListener(mediaListener);
        }
    }

    applyToDom();
};

export function useTheme() {
    return {
        theme,
        isDark,
        resolvedTheme,
        THEMES,
        init,
        setTheme,
        toggle,
    };
}
