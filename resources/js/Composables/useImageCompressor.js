/**
 * Shrinks images client-side so an upload always fits the server's size rule.
 *
 * Rejecting a 20 MB screenshot is a poor trade: the user has already waited for
 * the upload, and the thing we actually want (a legible picture of a defect)
 * survives re-encoding perfectly well. Compressing in the browser also means an
 * oversized file never leaves the machine, so it can't hit PHP's
 * upload_max_filesize / post_max_size, which reject the request before Laravel
 * validation ever runs and produce a far more confusing failure.
 *
 * Keep MAX_UPLOAD_MB in step with the `max:` rule on the server (kilobytes).
 */
export const MAX_UPLOAD_MB = 10

/** Screens are rarely wider than this; going beyond it wastes bytes. */
const DEFAULT_MAX_DIMENSION = 2560

/** Tried in order until the blob fits. */
const QUALITY_STEPS = [0.92, 0.85, 0.75, 0.65, 0.55, 0.45, 0.35]

const bytesFor = (mb) => mb * 1024 * 1024

export const formatBytes = (bytes) => {
    if (!bytes && bytes !== 0) return '—'
    if (bytes < 1024) return `${bytes} B`
    if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

/** Swaps the extension when a PNG has been re-encoded as JPEG. */
const renameTo = (name, extension) => name.replace(/\.[^.]+$/, '') + extension

const canvasToBlob = (canvas, type, quality) =>
    new Promise((resolve) => canvas.toBlob(resolve, type, quality))

/**
 * Returns a File no larger than `maxMb`, re-encoding only when necessary.
 *
 * @returns {Promise<{file: File, compressed: boolean, originalSize: number, finalSize: number, note: string|null}>}
 */
export async function compressImage(file, options = {}) {
    const maxMb = options.maxMb ?? MAX_UPLOAD_MB
    const maxBytes = bytesFor(maxMb)
    const maxDimension = options.maxDimension ?? DEFAULT_MAX_DIMENSION
    const originalSize = file.size

    const unchanged = (note = null) => ({
        file, compressed: false, originalSize, finalSize: originalSize, note,
    })

    // Non-raster formats can't go through a canvas without losing their point.
    if (!file.type?.startsWith('image/') || file.type === 'image/svg+xml') {
        return unchanged()
    }

    let bitmap
    try {
        // createImageBitmap applies EXIF orientation for us.
        bitmap = await createImageBitmap(file)
    } catch {
        return unchanged('Could not read this image, so it was left as-is.')
    }

    const needsResize = Math.max(bitmap.width, bitmap.height) > maxDimension
    if (originalSize <= maxBytes && !needsResize) {
        bitmap.close?.()
        return unchanged()
    }

    // An animated GIF would be flattened to its first frame — say so rather than
    // silently destroying it.
    const animatedGif = file.type === 'image/gif'

    let width = bitmap.width
    let height = bitmap.height

    if (needsResize) {
        const scale = maxDimension / Math.max(width, height)
        width = Math.round(width * scale)
        height = Math.round(height * scale)
    }

    // Up to three passes: each halves the dimensions if quality alone wasn't enough.
    for (let pass = 0; pass < 3; pass++) {
        const canvas = document.createElement('canvas')
        canvas.width = Math.max(1, Math.round(width))
        canvas.height = Math.max(1, Math.round(height))

        const ctx = canvas.getContext('2d')
        // JPEG has no alpha; without this, transparency renders black.
        ctx.fillStyle = '#ffffff'
        ctx.fillRect(0, 0, canvas.width, canvas.height)
        ctx.drawImage(bitmap, 0, 0, canvas.width, canvas.height)

        for (const quality of QUALITY_STEPS) {
            const blob = await canvasToBlob(canvas, 'image/jpeg', quality)
            if (!blob) continue

            if (blob.size <= maxBytes) {
                bitmap.close?.()

                const rebuilt = new File([blob], renameTo(file.name, '.jpg'), {
                    type: 'image/jpeg',
                    lastModified: Date.now(),
                })

                return {
                    file: rebuilt,
                    compressed: true,
                    originalSize,
                    finalSize: rebuilt.size,
                    note: animatedGif
                        ? `${file.name} was compressed (animation not kept).`
                        : null,
                }
            }
        }

        width /= 2
        height /= 2
    }

    bitmap.close?.()

    return unchanged(
        `${file.name} is ${formatBytes(originalSize)} and could not be reduced below ${maxMb} MB.`
    )
}

/**
 * Compresses a picked FileList. Always resolves — files that cannot be shrunk
 * are returned untouched, with a note, so the caller decides what to say.
 *
 * @returns {Promise<{files: File[], notes: string[], savedBytes: number}>}
 */
export async function compressImages(fileList, options = {}) {
    const files = []
    const notes = []
    let savedBytes = 0

    for (const original of Array.from(fileList || [])) {
        const result = await compressImage(original, options)
        files.push(result.file)

        if (result.compressed) {
            savedBytes += result.originalSize - result.finalSize
            notes.push(
                `${original.name}: ${formatBytes(result.originalSize)} → ${formatBytes(result.finalSize)}`
            )
        }

        if (result.note) notes.push(result.note)
    }

    return { files, notes, savedBytes }
}

export function useImageCompressor(options = {}) {
    return {
        maxMb: options.maxMb ?? MAX_UPLOAD_MB,
        compressImage: (file) => compressImage(file, options),
        compressImages: (files) => compressImages(files, options),
        formatBytes,
    }
}
