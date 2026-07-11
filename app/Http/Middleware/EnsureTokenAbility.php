<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforce that the authenticated API token carries the required ability.
 * Must be chained AFTER the `token` middleware so `tower_token` is set.
 *
 * Usage: `Route::post(...)->middleware(['token', 'ability:ingest']);`
 */
class EnsureTokenAbility
{
    public function handle(Request $request, Closure $next, string ...$required): Response
    {
        $token = $request->attributes->get('tower_token');

        if (!$token instanceof ApiToken) {
            return response()->json([
                'error' => 'unauthenticated',
                'message' => 'Bearer token required.',
            ], 401);
        }

        $abilities = (array) ($token->abilities ?? []);

        foreach ($required as $need) {
            if (!in_array($need, $abilities, true)) {
                return response()->json([
                    'error' => 'forbidden',
                    'message' => "Token missing required ability [{$need}].",
                    'required' => $required,
                ], 403);
            }
        }

        return $next($request);
    }
}
