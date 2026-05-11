/**
 * MCP Site Manager — Activity Log React app entry.
 */
import { createRoot, StrictMode } from '@wordpress/element';
import Log from './Log';
import './style.scss';

document.addEventListener('DOMContentLoaded', () => {
    const root = document.getElementById('mcpsm-log-root');
    if (!root) return;
    createRoot(root).render(
        <StrictMode>
            <Log />
        </StrictMode>
    );
});
