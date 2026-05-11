import { __ } from '@wordpress/i18n';

const fmt = (n) => Number(n).toLocaleString();

export default function TopAbilitiesTable({ rows }) {
    return (
        <table className="widefat striped" style={{ maxWidth: 900 }}>
            <thead><tr>
                <th>{__('Ability', 'mcp-site-manager')}</th>
                <th>{__('Calls', 'mcp-site-manager')}</th>
                <th>{__('Success rate', 'mcp-site-manager')}</th>
                <th>{__('Avg ms', 'mcp-site-manager')}</th>
            </tr></thead>
            <tbody>
                {rows.map((r) => (
                    <tr key={r.ability}>
                        <td><code>{r.ability}</code></td>
                        <td>{fmt(r.calls)}</td>
                        <td>{(r.success_rate * 100).toFixed(1)}%</td>
                        <td>{fmt(r.avg_ms)}</td>
                    </tr>
                ))}
            </tbody>
        </table>
    );
}
