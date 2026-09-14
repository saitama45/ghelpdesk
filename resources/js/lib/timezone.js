/**
 * Timezone maths for pages that show company times (schedules, DTR).
 *
 * The server stores and validates every schedule time as Asia/Manila wall-clock
 * time. A browser, left alone, reads a zone-less "2026-09-13 07:00" in the
 * DEVICE's zone, so anyone outside the Philippines saw shifted times and dates.
 * These helpers never use the device zone implicitly: every conversion names the
 * zone it reads from and the zone it writes to.
 */

export const APP_TIMEZONE = 'Asia/Manila'

const NAIVE_DATETIME = /^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})(:\d{2})?(?:\.\d+)?$/
const DATE_ONLY = /^\d{4}-\d{2}-\d{2}$/

const formatters = new Map()

const partsFormatter = (timezone) => {
    if (!formatters.has(timezone)) {
        formatters.set(timezone, new Intl.DateTimeFormat('en-CA', {
            timeZone: timezone,
            hourCycle: 'h23',
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
        }))
    }

    return formatters.get(timezone)
}

const wallParts = (date, timezone) => {
    const parts = {}
    for (const part of partsFormatter(timezone).formatToParts(date)) {
        parts[part.type] = part.value
    }
    if (parts.hour === '24') parts.hour = '00'

    return parts
}

export const isValidTimezone = (timezone) => {
    if (!timezone || typeof timezone !== 'string') return false

    try {
        new Intl.DateTimeFormat('en-US', { timeZone: timezone })
        return true
    } catch {
        return false
    }
}

export const deviceTimezone = () => {
    try {
        return Intl.DateTimeFormat().resolvedOptions().timeZone || APP_TIMEZONE
    } catch {
        return APP_TIMEZONE
    }
}

/** Milliseconds the zone's wall clock is ahead of UTC at that instant. */
const offsetMs = (date, timezone) => {
    const p = wallParts(date, timezone)
    const wallAsUtc = Date.UTC(+p.year, +p.month - 1, +p.day, +p.hour, +p.minute, +p.second)

    return wallAsUtc - Math.floor(date.getTime() / 1000) * 1000
}

/** The instant a wall-clock date + time in `timezone` refers to. */
export const zonedWallToDate = (dateKey, time, timezone) => {
    const [year, month, day] = dateKey.split('-').map(Number)
    const [hour = 0, minute = 0, second = 0] = String(time || '00:00').split(':').map(Number)
    const wallAsUtc = Date.UTC(year, month - 1, day, hour, minute, second)

    // Two passes settle the offset on either side of a DST change.
    let timestamp = wallAsUtc - offsetMs(new Date(wallAsUtc), timezone)
    const corrected = wallAsUtc - offsetMs(new Date(timestamp), timezone)
    if (corrected !== timestamp) timestamp = corrected

    return new Date(timestamp)
}

/**
 * Any schedule value → Date. Values with an offset ("…+08:00", "…Z") are exact;
 * zone-less strings are read as wall-clock time in `naiveTimezone`.
 */
export const toInstant = (value, naiveTimezone = APP_TIMEZONE) => {
    if (value === null || value === undefined || value === '') return null
    if (value instanceof Date) return Number.isNaN(value.getTime()) ? null : new Date(value.getTime())

    const text = String(value).trim()
    const naive = NAIVE_DATETIME.exec(text)
    if (naive) return zonedWallToDate(naive[1], naive[2] + (naive[3] || ''), naiveTimezone)
    if (DATE_ONLY.test(text)) return zonedWallToDate(text, '00:00', naiveTimezone)

    const parsed = new Date(text)
    return Number.isNaN(parsed.getTime()) ? null : parsed
}

/** "YYYY-MM-DD" of the value as seen in `timezone`. */
export const dateKeyIn = (value, timezone, naiveTimezone = APP_TIMEZONE) => {
    const date = toInstant(value, naiveTimezone)
    if (!date) return null
    const p = wallParts(date, timezone)

    return `${p.year}-${p.month}-${p.day}`
}

/** "YYYY-MM-DDTHH:mm" for a datetime-local input showing `timezone`. */
export const inputValueIn = (value, timezone, naiveTimezone = APP_TIMEZONE) => {
    const date = toInstant(value, naiveTimezone)
    if (!date) return ''
    const p = wallParts(date, timezone)

    return `${p.year}-${p.month}-${p.day}T${p.hour}:${p.minute}`
}

/** Re-express a datetime-local value typed in `fromTimezone` as wall time in `toTimezone`. */
export const convertInputValue = (value, fromTimezone, toTimezone) => {
    if (!value) return value
    if (fromTimezone === toTimezone) return value

    const date = toInstant(value, fromTimezone)
    return date ? inputValueIn(date, toTimezone) : value
}

/** Minutes since midnight of the value's wall clock in `timezone`. */
export const minutesOfDayIn = (value, timezone, naiveTimezone = APP_TIMEZONE) => {
    const date = toInstant(value, naiveTimezone)
    if (!date) return 0
    const p = wallParts(date, timezone)

    return (+p.hour * 60) + +p.minute
}

export const formatIn = (value, timezone, options = {}, naiveTimezone = APP_TIMEZONE) => {
    const date = toInstant(value, naiveTimezone)
    if (!date) return ''

    return new Intl.DateTimeFormat('en-US', { ...options, timeZone: timezone }).format(date)
}

/** Calendar arithmetic on a "YYYY-MM-DD" key, independent of any zone. */
export const addDaysToKey = (dateKey, days) => {
    const [year, month, day] = dateKey.split('-').map(Number)
    const date = new Date(Date.UTC(year, month - 1, day + days))

    return date.toISOString().slice(0, 10)
}

/** "YYYY-MM-DD" of a calendar cell Date built with local getters (new Date(y, m, d)). */
export const localDateKey = (date) => {
    const year = date.getFullYear()
    const month = String(date.getMonth() + 1).padStart(2, '0')
    const day = String(date.getDate()).padStart(2, '0')

    return `${year}-${month}-${day}`
}

/** Every date key an interval touches in `timezone`, first to last inclusive. */
export const dateKeysBetweenIn = (start, end, timezone, naiveTimezone = APP_TIMEZONE) => {
    const startKey = dateKeyIn(start, timezone, naiveTimezone)
    const endKey = dateKeyIn(end, timezone, naiveTimezone) || startKey
    if (!startKey) return []

    const keys = []
    for (let key = startKey; key <= endKey && keys.length < 370; key = addDaysToKey(key, 1)) {
        keys.push(key)
    }

    return keys
}

/** "GMT+8" style offset for the zone at `at`. */
export const timezoneOffsetLabel = (timezone, at = new Date()) => {
    try {
        const part = new Intl.DateTimeFormat('en-US', { timeZone: timezone, timeZoneName: 'shortOffset' })
            .formatToParts(at)
            .find(p => p.type === 'timeZoneName')

        return part?.value || ''
    } catch {
        return ''
    }
}

/** "Los Angeles (GMT-7)" — the city part of the IANA name plus its current offset. */
export const timezoneLabel = (timezone, at = new Date()) => {
    const city = String(timezone || '').split('/').pop().replace(/_/g, ' ')
    const offset = timezoneOffsetLabel(timezone, at)

    return offset ? `${city} (${offset})` : city
}

/** Every IANA zone the browser knows, falling back to a short list on old engines. */
export const timezoneOptions = () => {
    let zones = []
    try {
        zones = Intl.supportedValuesOf('timeZone')
    } catch {
        zones = [APP_TIMEZONE, 'UTC', 'Asia/Singapore', 'Asia/Tokyo', 'Asia/Dubai', 'Europe/London', 'America/Los_Angeles', 'America/New_York', 'Australia/Sydney']
    }
    if (!zones.includes(APP_TIMEZONE)) zones.unshift(APP_TIMEZONE)

    const now = new Date()
    return zones.map(zone => ({
        value: zone,
        label: `${zone.replace(/_/g, ' ')} (${timezoneOffsetLabel(zone, now)})`,
    }))
}
