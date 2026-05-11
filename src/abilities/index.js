/**
 * MCP Site Manager — Abilities React app entry.
 */
import { createRoot, StrictMode } from '@wordpress/element';
import Abilities from './Abilities';
import './style.scss';

document.addEventListener('DOMContentLoaded', () => {
    const root = document.getElementById('mcpsm-abilities-root');
    if (!root) return;
    createRoot(root).render(
        <StrictMode>
            <Abilities />
        </StrictMode>
    );
});
