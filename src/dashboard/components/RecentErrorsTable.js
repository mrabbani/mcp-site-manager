import { __ } from '@wordpress/i18n';

function formatTs(mysqlDt) {
    if (!mysqlDt) return '';
    const d = new Date(mysqlDt.replace(' ', 'T') + 'Z');
    if (Number.isNaN(d.getTime())) return mysqlDt;
    return d.toLocaleString();
}

export default function RecentErrorsTable({ rows }) {
    if (!rows || rows.length === 0) {
        return <p><em>{__('No errors recorded in the current window.', 'mcp-site-manager')}</em></p>;
    }
    return (
        <table className="widefat striped" style={{ maxWidth: 900 }}>
            <thead><tr>
                <th>{__('Time', 'mcp-site-manager')}</th>
                <th>{__('Ability', 'mcp-site-manager')}</th>
                <th>{__('Code', 'mcp-site-manager')}</th>
                <th>{__('User', 'mcp-site-manager')}</th>
            </tr></thead>
            <tbody>
                {rows.map((r, i) => (
                    <tr key={`${r.ts}-${r.ability}-${i}`}>
                        <td>{formatTs(r.ts)}</td>
                        <td><code>{r.ability}</code></td>
                        <td>{r.error_code ?? ''}</td>
                        <td>{r.user_login ?? __('(unknown)', 'mcp-site-manager')}</td>
                    </tr>
                ))}
            </tbody>
        </table>
    );
}
