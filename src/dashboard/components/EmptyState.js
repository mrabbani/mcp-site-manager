import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';

export default function EmptyState() {
    const connectionUrl = (window.mcpsmDashboard && window.mcpsmDashboard.tabUrls && window.mcpsmDashboard.tabUrls.connection) || '#';
    return (
        <div style={{
            marginTop: '2em',
            padding: '2em',
            border: '1px solid #ddd',
            background: '#fff',
            textAlign: 'center'
        }}>
            <h2>{__("You haven't run anything yet.", 'mcp-site-manager')}</h2>
            <p>{__('Once your MCP client invokes a tool, stats will show up here.', 'mcp-site-manager')}</p>
            <p>
                <Button variant="primary" href={connectionUrl}>
                    {__('See connection details →', 'mcp-site-manager')}
                </Button>
            </p>
        </div>
    );
}
