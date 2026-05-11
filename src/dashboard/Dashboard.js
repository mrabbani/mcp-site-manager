import { __ } from '@wordpress/i18n';
import { Notice, Spinner } from '@wordpress/components';
import useStats from './hooks/useStats';
import RefreshHeader from './components/RefreshHeader';
import NumbersRow from './components/NumbersRow';
import LatencyRow from './components/LatencyRow';
import TopAbilitiesTable from './components/TopAbilitiesTable';
import RecentErrorsTable from './components/RecentErrorsTable';
import WindowFooter from './components/WindowFooter';
import EmptyState from './components/EmptyState';

export default function Dashboard() {
    const { data, loading, error, lastUpdated, refresh } = useStats();

    if (!data && loading) {
        return <div style={{ padding: '2em' }}><Spinner /></div>;
    }
    if (error && !data) {
        return (
            <Notice status="error" isDismissible={false}>
                {__('Could not load stats:', 'mcp-site-manager')} {error.message}
            </Notice>
        );
    }
    if (!data) return null;

    if (data.counts.total === 0) {
        return <EmptyState />;
    }

    return (
        <>
            <RefreshHeader lastUpdated={lastUpdated} loading={loading} onManualRefresh={refresh} />
            {error && (
                <Notice status="warning" isDismissible={false}>
                    {__('Last refresh failed:', 'mcp-site-manager')} {error.message}
                </Notice>
            )}
            <h2>{__('Numbers', 'mcp-site-manager')}</h2>
            <NumbersRow counts={data.counts} />
            <h2>{__('Latency', 'mcp-site-manager')}</h2>
            <LatencyRow latency={data.latency} />
            <h2>{__('Top abilities', 'mcp-site-manager')}</h2>
            <TopAbilitiesTable rows={data.top_abilities} />
            <h2 style={{ marginTop: '1.5em' }}>{__('Recent errors', 'mcp-site-manager')}</h2>
            <RecentErrorsTable rows={data.recent_errors} />
            <WindowFooter window={data.window} lastUpdated={lastUpdated} />
        </>
    );
}
