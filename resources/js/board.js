/**
 * Tower board client bridge.
 *
 * Echo subscribes to either `fleet.board` (authed board) or
 * `public.board` (public `/`), maps each `broadcastAs` name to the
 * island(s) it touches per docs/contracts/channels.md, and calls
 * `$wire.$island(name).$refresh()` throttled to 500ms per island.
 *
 * Pushed payloads are NEVER rendered client-side (ADR-0004) — they
 * exist only to signal which island should re-render server-side.
 * `wire:poll.30s` is the degraded-mode fallback.
 *
 * The mapping is the single source of truth, matching the table in
 * docs/contracts/channels.md exactly:
 *
 *   agent.status_changed -> grid, attention
 *   run.updated          -> grid, feed
 *   drift.raised         -> attention, feed
 *   receipt.updated      -> receipts, feed
 *   demo.chunk           -> (DemoConsole component, direct)
 */

const ISLAND_REFRESH_MAP = {
    'agent.status_changed': ['grid', 'attention'],
    'run.updated':          ['grid', 'feed'],
    'drift.raised':         ['attention', 'feed'],
    'receipt.updated':      ['receipts', 'feed'],
};

const REFRESH_THROTTLE_MS = 500;

/**
 * Per-island throttle: collect refresh requests during the window and
 * flush once. Prevents a burst of broadcasts from N-rendering an
 * island in a single tick.
 */
function makeThrottler(fn, ms) {
    let pending = false;
    let timer = null;
    return function schedule() {
        if (pending) return;
        pending = true;
        timer = setTimeout(() => {
            pending = false;
            timer = null;
            fn();
        }, ms);
    };
}

function wireIslandRefreshes() {
    // The board is rendered as a Livewire full-page component, so
    // `Livewire.first()` IS the FleetBoard instance on `/` and
    // `/board`. We pull it lazily and re-resolve if the DOM changes.
    const root = document.querySelector('[data-board]');
    if (!root) return null;

    const component = window.Livewire?.first?.();
    if (!component) return null;

    return function refreshIsland(name) {
        if (typeof name !== 'string' || name === '') return;
        const wire = component.$wire;
        if (! wire) return;
        // $wire.$island(name) tags the next call with the island
        // metadata; calling $refresh on the result fires a request
        // for that single island only.
        wire.$island(name).$refresh();
    };
}

function subscribeBoard() {
    const root = document.querySelector('[data-board]');
    if (!root) return;

    const channelName = root.dataset.channel;
    if (! channelName) return;

    const echo = window.Echo;
    if (! echo) return;

    const scheduleRefresh = wireIslandRefreshes();
    if (! scheduleRefresh) return;

    // Private channel for the authed board; public for the public one.
    const channel = channelName === 'fleet.board'
        ? echo.private('fleet.board')
        : echo.channel('public.board');

    for (const [eventName, islands] of Object.entries(ISLAND_REFRESH_MAP)) {
        // Per-island throttle: at most one refresh per island per 500ms.
        const throttlers = new Map();
        channel.listen('.' + eventName, () => {
            for (const island of islands) {
                let t = throttlers.get(island);
                if (! t) {
                    t = makeThrottler(() => scheduleRefresh(island), REFRESH_THROTTLE_MS);
                    throttlers.set(island, t);
                }
                t();
            }
        });
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', subscribeBoard, { once: true });
} else {
    subscribeBoard();
}

// Re-attach on Livewire navigations (Livewire 4 `navigate` updates the
// DOM without a full reload).
document.addEventListener('livewire:navigated', subscribeBoard, { once: true });
