// Tower client entrypoint. Vite bundles this for the browser; the
// layout (`resources/views/components/layouts/app.blade.php`) loads
// it via `@vite(['resources/css/app.css', 'resources/js/app.js'])`.
//
// The two modules below are split so the Echo client can be reused
// by other surfaces (e.g. the DemoConsole on `/`) without dragging
// the board bridge along.

import './echo.js';
import './board.js';
