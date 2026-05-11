/**
 * MCP Site Manager — Dashboard React app.
 * Mounts into <div id="mcpsm-dashboard-root"> rendered by SettingsPage::render_dashboard().
 *
 * Full component tree is built in Task 10; this stub verifies the build pipeline.
 */

import { createRoot } from '@wordpress/element';

document.addEventListener('DOMContentLoaded', () => {
    const root = document.getElementById('mcpsm-dashboard-root');
    if (!root) return;
    createRoot(root).render('Dashboard build pipeline OK');
});
