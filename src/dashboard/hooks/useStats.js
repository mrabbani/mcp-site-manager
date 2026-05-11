import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

/**
 * Fetch /stats/all from the MCP Site Manager REST namespace.
 * Loads once on mount; caller invokes `refresh()` to re-fetch on demand
 * (e.g. via the "Refresh now" button). No polling.
 *
 * @returns {{ data: object|null, loading: boolean, error: Error|null, lastUpdated: Date|null, refresh: function }}
 */
export default function useStats() {
    const [data, setData] = useState(null);
    const [error, setError] = useState(null);
    const [loading, setLoading] = useState(true);
    const [lastUpdated, setLastUpdated] = useState(null);
    const inflight = useRef(false);

    const refresh = useCallback(async () => {
        if (inflight.current) return;
        inflight.current = true;
        setLoading(true);
        try {
            const result = await apiFetch({ path: '/mcp-site-manager/v1/stats/all' });
            setData(result);
            setError(null);
            setLastUpdated(new Date());
        } catch (e) {
            setError(e instanceof Error ? e : new Error(String(e?.message ?? e)));
        } finally {
            setLoading(false);
            inflight.current = false;
        }
    }, []);

    useEffect(() => {
        refresh();
    }, [refresh]);

    return { data, loading, error, lastUpdated, refresh };
}
