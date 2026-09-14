/** Small formatting helpers shared across the Clifto Talk components. */

export function formatTime(iso) {
    if (!iso) return '';
    return new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

/** "14:32" today, "Yesterday", "Mon" this week, otherwise a short date. */
export function formatListTimestamp(iso) {
    if (!iso) return '';

    const date = new Date(iso);
    const now = new Date();
    const startOfToday = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    const diffDays = Math.floor((startOfToday - new Date(date.getFullYear(), date.getMonth(), date.getDate())) / 86400000);

    if (diffDays <= 0) return formatTime(iso);
    if (diffDays === 1) return 'Yesterday';
    if (diffDays < 7) return date.toLocaleDateString([], { weekday: 'short' });

    return date.toLocaleDateString([], { day: '2-digit', month: 'short' });
}

/** Separator label between day groups in a thread. */
export function formatDayLabel(iso) {
    if (!iso) return '';

    const date = new Date(iso);
    const now = new Date();
    const startOfToday = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    const diffDays = Math.floor((startOfToday - new Date(date.getFullYear(), date.getMonth(), date.getDate())) / 86400000);

    if (diffDays <= 0) return 'Today';
    if (diffDays === 1) return 'Yesterday';

    return date.toLocaleDateString([], { day: 'numeric', month: 'long', year: date.getFullYear() === now.getFullYear() ? undefined : 'numeric' });
}

export function sameDay(a, b) {
    if (!a || !b) return false;
    const x = new Date(a);
    const y = new Date(b);
    return x.getFullYear() === y.getFullYear() && x.getMonth() === y.getMonth() && x.getDate() === y.getDate();
}

export function formatBytes(bytes) {
    if (!bytes) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB'];
    const index = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
    const value = bytes / 1024 ** index;
    return `${value >= 10 || index === 0 ? Math.round(value) : value.toFixed(1)} ${units[index]}`;
}

/** Renders a system message's metadata into a sentence. */
export function systemMessageText(message) {
    const meta = message?.metadata || {};

    switch (meta.action) {
        case 'group.created':
            return `${meta.actor} created "${meta.name}"`;
        case 'group.renamed':
            return `${meta.actor} renamed the group to "${meta.to}"`;
        case 'participants.added':
            return `${meta.actor} added ${(meta.names || []).join(', ')}`;
        case 'participant.removed':
            return `${meta.actor} removed ${meta.target}`;
        case 'participant.left':
            return `${meta.target} left the group`;
        case 'participant.promoted':
            return `${meta.actor} made ${meta.target} an admin`;
        case 'participant.demoted':
            return `${meta.actor} removed admin from ${meta.target}`;
        default:
            return 'Conversation updated';
    }
}

/**
 * Escape, then linkify bare URLs. Message bodies are user input rendered with
 * v-html, so escaping first is what keeps this from being an XSS hole.
 */
export function renderBody(text) {
    if (!text) return '';

    const escaped = text
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');

    return escaped.replace(
        /(https?:\/\/[^\s<]+)/g,
        '<a href="$1" target="_blank" rel="noopener noreferrer" class="underline underline-offset-2 hover:opacity-80">$1</a>',
    );
}
