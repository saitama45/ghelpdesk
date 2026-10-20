import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Height that makes a full-page editor end exactly at the bottom of the window.
 *
 * A fixed `calc(100vh - Nrem)` cannot know how tall the strips above the page are (top bar,
 * department strip, module tabs — they vary by user and screen width), so the bottom of the
 * editor (page tabs, zoom controls) ended up below the fold. This measures instead.
 */
export function useFillViewport(target, { min = 480, bottom = 24, onResize = null } = {}) {
    const height = ref(null);

    const measure = () => {
        const el = target.value;
        if (!el || getComputedStyle(el).position === 'fixed') return;
        const scroller = el.closest('[scroll-region]');
        const top = el.getBoundingClientRect().top + (scroller?.scrollTop || 0);
        const next = Math.max(min, Math.floor(window.innerHeight - top - bottom));
        if (next !== height.value) {
            height.value = next;
            if (onResize) nextTick(onResize);
        }
    };

    onMounted(() => {
        nextTick(measure);
        window.addEventListener('resize', measure);
    });

    onBeforeUnmount(() => window.removeEventListener('resize', measure));

    return { height, measure };
}
