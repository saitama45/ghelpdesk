// Text measurement and wrapping for SVG canvases. SVG <text> does not wrap, so lines are
// broken here with the same font the renderer uses (canvas measureText).

export const FONT_FAMILY = 'ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif';

let measureContext = null;
const wrapCache = new Map();

export const measureText = (text, size, weight = 400, style = 'normal') => {
    if (typeof document === 'undefined') return String(text).length * size * 0.55;
    if (!measureContext) measureContext = document.createElement('canvas').getContext('2d');
    measureContext.font = `${style} ${weight} ${size}px ${FONT_FAMILY}`;
    return measureContext.measureText(text).width;
};

export const wrapText = (text, maxWidth, size, weight = 400, style = 'normal') => {
    const key = `${size}|${weight}|${style}|${Math.round(maxWidth)}|${text}`;
    if (wrapCache.has(key)) return wrapCache.get(key);
    if (wrapCache.size > 3000) wrapCache.clear();

    const lines = [];
    for (const paragraph of String(text ?? '').split('\n')) {
        let line = '';
        for (const word of paragraph.split(/(\s+)/)) {
            const candidate = line + word;
            if (!line || measureText(candidate, size, weight, style) <= maxWidth) {
                line = candidate;
                continue;
            }
            lines.push(line.trimEnd());
            line = word.trimStart();
            // A single word wider than the box is broken by characters.
            while (line && measureText(line, size, weight, style) > maxWidth) {
                let cut = line.length - 1;
                while (cut > 1 && measureText(line.slice(0, cut), size, weight, style) > maxWidth) cut--;
                lines.push(line.slice(0, cut));
                line = line.slice(cut);
            }
        }
        lines.push(line);
    }

    wrapCache.set(key, lines);
    return lines;
};
