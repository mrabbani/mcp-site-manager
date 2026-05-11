/**
 * MCP Site Manager — Dashboard React app entry.
 */

import { createRoot, StrictMode } from '@wordpress/element';
import Dashboard from './Dashboard';
import './style.scss';

document.addEventListener('DOMContentLoaded', () => {
    const root = document.getElementById('mcpsm-dashboard-root');
    if (!root) return;
    createRoot(root).render(
        <StrictMode>
            <Dashboard />
        </StrictMode>
    );
});
