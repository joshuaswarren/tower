<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\SessionController;
use App\Livewire\DemoConsole;
use App\Livewire\FleetBoard;
use App\Livewire\RunDetail;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web routes — Tower (Lane B + Lane D)
|--------------------------------------------------------------------------
|
| Public board (`/`) is gated by `tower.public_board.enabled`. The
| authed board (`/board`) requires a session. The login form is at
| `/login`; the admin action is at `/runs/{run}`.
|
| The demo console (`/demo`) is gated by `tower.demo.enabled` and is
| cut-safe: when the flag is off the route is absent entirely, the
| Livewire component is not loaded, and there are zero tendrils into
| the rest of the app (per ARCHITECTURE.md §1.7).
|
| Registration is intentionally NOT routed — the admin user is created
| by the env-gated `AdminUserSeeder`. The form lives at
| `resources/views/auth/login.blade.php`.
|
*/

// `/` — public read-only board. The component inspects the route name
// (`public.board`) to enter public mode and reads
// `config('tower.public_board.enabled')` to redirect to login when the
// feature flag is off (so a tweet with the URL still lands somewhere
// sensible when the feature is toggled off). No auth middleware; the
// public variant is safe to be put in a tweet.
Route::get('/', FleetBoard::class)->name('public.board');

// `/board` — authed, full board. `auth` middleware redirects to the
// login form on miss.
Route::middleware('auth')->group(function (): void {
    Route::get('/board', FleetBoard::class)->name('board');

    Route::get('/runs/{run}', RunDetail::class)
        ->whereUlid('run')
        ->name('runs.show');

    Route::post('/logout', [SessionController::class, 'destroy'])
        ->name('logout');
});

// `/demo` — public demo console. The route is always registered so its
// name resolves; DemoConsole::mount() redirects to login when
// `tower.demo.enabled` is off (same UX as the disabled public board).
// Cut-safe: delete DemoConsole + this line + the AppServiceProvider bind.
Route::get('/demo', DemoConsole::class)->name('demo.console');

// Login form + submission sit outside the auth group so the redirect on
// `/board` can find them. The login form is the only place to enter
// credentials — no register, no reset, no verify-email.
Route::middleware('guest')->group(function (): void {
    Route::get('/login', [SessionController::class, 'create'])
        ->name('login');
    Route::post('/login', [SessionController::class, 'store'])
        ->name('login.store');
});
