import { __, sprintf } from '@wordpress/i18n';

function formatTs(mysqlDt) {
    if (!mysqlDt) return '';
    const d = new Date(mysqlDt.replace(' ', 'T') + 'Z');
    if (Number.isNaN(d.getTime())) return mysqlDt;
    return d.toLocaleString();
}

export default function WindowFooter({ window: w, lastUpdated }) {
    const updated = lastUpdated ? lastUpdated.toLocaleTimeString() : '—';
    return (
        <p style={{ marginTop: '1.5em' }}>
            <em>
                {sprintf(
                    __('Stats based on the last %1$s invocations between %2$s and %3$s. Last updated: %4$s.', 'mcp-site-manager'),
                    Number(w.count).toLocaleString(),
                    formatTs(w.from),
                    formatTs(w.to),
                    updated
                )}
            </em>
        </p>
    );
}
