import axios from 'axios';
import { compressImage } from '@/Composables/useImageCompressor';

/** Drag payload for the Elements panel: JSON { kind: 'icon', key } or { kind: 'image', url, width, height }. */
export const ASSET_DRAG_TYPE = 'application/x-canvas-asset';

export const RASTER_IMAGE = /^image\/(png|jpe?g|gif|webp)$/;

/**
 * Add a picture to the image library (auto-compressed first). `shared` puts it in the
 * library everyone sees; otherwise only the uploader sees it. Resolves to the asset.
 */
export const uploadCanvasAsset = async (file, { shared = true, name = null } = {}) => {
    const { file: compressed } = await compressImage(file);
    const form = new FormData();
    form.append('image', compressed, file.name || compressed.name);
    form.append('shared', shared ? '1' : '0');
    if (name) form.append('name', name);
    const { data } = await axios.post(route('canvas-assets.store'), form);
    return data.asset;
};

/** Size a picture for the canvas: natural size, capped at `max` on its longer side. */
export const fitPicture = (width, height, max = 320) => {
    const w = width || 160;
    const h = height || 120;
    const scale = Math.min(1, max / Math.max(w, h));
    return { w: Math.round(w * scale), h: Math.round(h * scale) };
};
