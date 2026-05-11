import { __ } from '@wordpress/i18n';
import { Button, Spinner } from '@wordpress/components';

export default function RefreshHeader({ lastUpdated, loading, onManualRefresh }) {
    const updated = lastUpdated ? lastUpdated.toLocaleTimeString() : '—';
    return (
        <div style={{ display: 'flex', alignItems: 'center', gap: '0.75em', marginBottom: '1em' }}>
            <strong>{__('Stats', 'mcp-site-manager')}</strong>
            <span style={{ color: '#646970', fontSize: '0.9em' }}>
                {__('Last updated:', 'mcp-site-manager')} {updated}
            </span>
            {loading && <Spinner />}
            <Button variant="secondary" onClick={onManualRefresh} disabled={loading}>
                {__('Refresh now', 'mcp-site-manager')}
            </Button>
        </div>
    );
}
