// Export an SVG content group (pure SVG, no foreignObject) as a standalone SVG or PNG.
// The renderers keep every style in attributes, so the group's markup is self-contained —
// except pictures, which point at /serve-storage and are inlined as data URIs on export
// (an SVG drawn as an image cannot fetch anything, and a downloaded SVG must stand alone).

const PAD = 40;

const frame = (bounds) => {
    const box = bounds && bounds.w > 0 && bounds.h > 0 ? bounds : { x: 0, y: 0, w: 800, h: 600 };
    return { x: box.x - PAD, y: box.y - PAD, w: box.w + PAD * 2, h: box.h + PAD * 2 };
};

export const blobToDataUrl = (blob) => new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(reader.result);
    reader.onerror = reject;
    reader.readAsDataURL(blob);
});

/** Same-origin picture URL → data URI (null when it cannot be fetched). */
export const urlToDataUrl = async (url) => {
    try {
        const response = await fetch(url, { credentials: 'same-origin' });
        if (!response.ok) return null;
        return await blobToDataUrl(await response.blob());
    } catch {
        return null;
    }
};

const inlineImages = async (markup) => {
    const urls = [...new Set([...markup.matchAll(/href="(\/serve-storage\/[^"]+)"/g)].map((match) => match[1]))];
    let result = markup;
    for (const url of urls) {
        const data = await urlToDataUrl(url);
        if (data) result = result.split(`href="${url}"`).join(`href="${data}"`);
    }
    return result;
};

export const groupToSvgMarkup = async (group, bounds, background = '#ffffff') => {
    const vb = frame(bounds);
    const backdrop = background ? `<rect x="${vb.x}" y="${vb.y}" width="${vb.w}" height="${vb.h}" fill="${background}"/>` : '';
    const content = await inlineImages(group.innerHTML);
    return `<svg xmlns="http://www.w3.org/2000/svg" width="${vb.w}" height="${vb.h}" viewBox="${vb.x} ${vb.y} ${vb.w} ${vb.h}">${backdrop}${content}</svg>`;
};

export const downloadBlob = (blob, filename) => {
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = filename;
    link.click();
    setTimeout(() => URL.revokeObjectURL(link.href), 1000);
};

export const exportGroupToSvg = async (group, bounds, filename, background = '#ffffff') => {
    const markup = await groupToSvgMarkup(group, bounds, background);
    downloadBlob(new Blob([markup], { type: 'image/svg+xml' }), filename);
};

export const exportGroupToPng = async (group, bounds, filename, background = '#ffffff') => {
    const vb = frame(bounds);
    const scale = Math.min(2, 8000 / Math.max(vb.w, vb.h));

    const image = new Image();
    image.src = `data:image/svg+xml;charset=utf-8,${encodeURIComponent(await groupToSvgMarkup(group, bounds, background))}`;
    await image.decode();

    const canvas = document.createElement('canvas');
    canvas.width = Math.ceil(vb.w * scale);
    canvas.height = Math.ceil(vb.h * scale);
    const context = canvas.getContext('2d');
    context.scale(scale, scale);
    context.drawImage(image, 0, 0, vb.w, vb.h);

    const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/png'));
    downloadBlob(blob, filename);
};
