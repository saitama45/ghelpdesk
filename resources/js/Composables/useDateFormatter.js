import { usePage } from '@inertiajs/vue3';

export function useDateFormatter() {
    /**
     * Parse a date string from the database (Manila time) into a JavaScript Date object.
     * Handles formats with and without offsets.
     */
    const parseDate = (dateString) => {
        if (!dateString) return new Date(0);
        if (dateString instanceof Date) return dateString;
        
        let s = String(dateString).trim();
        
        // 1. If it's a complete ISO string with 'Z' (UTC), parse it and return.
        // The display logic (formatDate) will then shift it to Manila time (+8).
        if (s.endsWith('Z')) {
            const d = new Date(s);
            if (!isNaN(d.getTime())) return d;
        }

        // 2. If it already has an offset like +08:00, it's already localized.
        // We just return the Date object.
        if (/[+-]\d{2}:\d{2}$/.test(s)) {
            const d = new Date(s);
            if (!isNaN(d.getTime())) return d;
        }
        
        // 3. Aggressively match only the date and time numbers (YYYY-MM-DD HH:MM:SS)
        // These are bare strings from the DB which we KNOW are Manila time (UTC+8).
        const match = s.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})/);
        
        if (match) {
            const [_, year, month, day, hour, minute, second] = match;
            // Create an ISO string forcing it to be interpreted as Manila time (+08:00)
            const forcedIso = `${year}-${month}-${day}T${hour}:${minute}:${second}+08:00`;
            const d = new Date(forcedIso);
            if (!isNaN(d.getTime())) return d;
        }
        
        // Final fallback for any other string format
        const d = new Date(s);
        return isNaN(d.getTime()) ? new Date(dateString) : d;
    };

    /**
     * Format a date for display, defaulting to Asia/Manila timezone to match the backend.
     */
    const formatDate = (dateInput, options = {}) => {
        if (!dateInput) return '';
        const date = parseDate(dateInput);
        
        const defaultOptions = { 
            year: 'numeric', 
            month: 'numeric', 
            day: 'numeric', 
            hour: 'numeric', 
            minute: '2-digit', 
            second: '2-digit',
            hour12: true,
            timeZone: 'Asia/Manila'
        };

        return date.toLocaleString('en-US', { 
            ...defaultOptions,
            ...options
        });
    };

    return {
        parseDate,
        formatDate
    };
}
