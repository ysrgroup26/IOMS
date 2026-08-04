import { useState, useEffect } from 'react';

/**
 * Ticks every second using the browser's own clock (not server time),
 * since greetings/current-time displays should reflect the user's local
 * system time, not the server's timezone. Re-render cost is trivial --
 * only the components that call this hook update each tick.
 */
export function useClock() {
    const [now, setNow] = useState(() => new Date());

    useEffect(() => {
        const interval = setInterval(() => setNow(new Date()), 1000);
        return () => clearInterval(interval);
    }, []);

    return now;
}

export function greetingFor(date) {
    const hour = date.getHours();
    if (hour < 12) return 'Good Morning';
    if (hour < 18) return 'Good Afternoon';
    return 'Good Evening';
}
