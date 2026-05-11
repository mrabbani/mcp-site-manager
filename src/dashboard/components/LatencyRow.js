import { __ } from '@wordpress/i18n';
import StatCard from './StatCard';

const fmt = (n) => Number(n).toLocaleString();

export default function LatencyRow({ latency }) {
    return (
        <div style={{ display: 'flex', gap: '1em', flexWrap: 'wrap', marginBottom: '1.5em' }}>
            <StatCard label={__('Average', 'mcp-site-manager')} value={`${fmt(latency.avg_ms)} ms`} color="#646970" />
            <StatCard label={__('p95', 'mcp-site-manager')}     value={`${fmt(latency.p95_ms)} ms`} color="#646970" />
        </div>
    );
}
