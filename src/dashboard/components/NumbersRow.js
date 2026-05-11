import { __ } from '@wordpress/i18n';
import StatCard from './StatCard';

const fmt = (n) => Number(n).toLocaleString();

export default function NumbersRow({ counts }) {
    const ratePct = (counts.success_rate * 100).toFixed(1);
    const errBg   = counts.error > 0 ? '#d63638' : '#646970';
    const rateBg  = counts.error === 0 ? '#00a32a' : (counts.success_rate >= 0.95 ? '#00a32a' : '#646970');
    return (
        <div style={{ display: 'flex', gap: '1em', flexWrap: 'wrap', marginBottom: '1.5em' }}>
            <StatCard label={__('Total', 'mcp-site-manager')}        value={fmt(counts.total)}   color="#646970" />
            <StatCard label={__('Success', 'mcp-site-manager')}      value={fmt(counts.success)} color="#00a32a" />
            <StatCard label={__('Errors', 'mcp-site-manager')}       value={fmt(counts.error)}   color={errBg} />
            <StatCard label={__('Success rate', 'mcp-site-manager')} value={`${ratePct}%`}       color={rateBg} />
        </div>
    );
}
