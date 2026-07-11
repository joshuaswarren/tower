<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast channels (frozen — docs/contracts/channels.md)
|--------------------------------------------------------------------------
|
| Channel names and authorization rules below are bound to TWR-003. Every
| other lane (A, D) dispatches broadcasts on these strings; clients
| (resources/js/board.js) subscribe to them. Do not rename a channel
| without updating channels.md + the JS mapping.
|
*/

// `fleet.board` — private, any authenticated user (single-tenant = the
// admin). Authorizes the connecting user on a `User` model.
Broadcast::channel('fleet.board', function (User $user): bool {
    return $user->exists;
});

// `public.board` — public, no auth gate required.
Broadcast::channel('public.board', function (): bool {
    return true;
});

// `demo.run.{runId}` — public, AI SDK stream chunks for a dispatched demo
// run. The `{runId}` placeholder is a wildcard; presence on the channel
// is open. (Lane D fills the demo job that broadcasts on this channel.)
Broadcast::channel('demo.run.{runId}', function (string $runId): bool {
    return $runId !== '';
});
