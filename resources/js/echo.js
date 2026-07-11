import Echo from 'laravel-echo';

import Pusher from 'pusher-js';

window.Pusher = Pusher;

/*
 * Bootstrap the Reverb Echo client. Vite injects REVERB_* envs at build
 * time (see .env / .env.example). The Echo instance is attached to
 * `window.Echo` so the rest of the app can subscribe to channels
 * (resources/js/board.js).
 *
 * If Reverb envs are missing (no VITE_REVERB_*), we still attach a
 * stub Echo so the board.js subscribe calls no-op cleanly in offline
 * mode — `wire:poll.30s` keeps the board honest.
 */
const reverbKey = import.meta.env.VITE_REVERB_APP_KEY;
const reverbHost = import.meta.env.VITE_REVERB_HOST;
const reverbPort = import.meta.env.VITE_REVERB_PORT;
const reverbScheme = import.meta.env.VITE_REVERB_SCHEME ?? 'https';

if (reverbKey && reverbHost) {
    window.Echo = new Echo({
        broadcaster: 'reverb',
        key: reverbKey,
        wsHost: reverbHost,
        wsPort: reverbPort ?? 80,
        wssPort: reverbPort ?? 443,
        forceTLS: reverbScheme === 'https',
        enabledTransports: ['ws', 'wss'],
    });
} else {
    // No Reverb configured (typical for `php artisan serve` local dev
    // without the WebSocket sidecar up). Expose a stub so board.js
    // doesn't crash. Polling is the fallback per ADR-0004.
    window.Echo = {
        private: () => ({
            listen: () => ({ listen: () => ({}) }),
        }),
        channel: () => ({
            listen: () => ({ listen: () => ({}) }),
        }),
        leave: () => {},
        disconnect: () => {},
    };
}
