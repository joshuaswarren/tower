<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticate an API request against the `api_tokens` table
 * (docs/ARCHITECTURE.md §1.2 + ADR-0002).
 *
 * Token format: `twr_<base62 40>`. We store the SHA-256 of the full token
 * plus a 12-char prefix (the first 12 chars of the plaintext, which the
 * token record `token_prefix` matches). The prefix is indexed; the hash is
 * the actual credential and is compared in constant time.
 *
 * On success, the matched `ApiToken` is attached to the request as
 * `tower_token`, and the token id is exposed as `tower_token_id` for the
 * `ingest` rate limiter to key on. `last_used_at` is bumped in a throttled
 * fashion so a burst of authorized calls does not pound the DB.
 */
class AuthenticateApiToken
{
    /**
     * Minimum length of the credential portion after `twr_`. The full token
     * is `twr_` + 40 chars; we accept anything from 12+ so a hand-truncated
     * token still fails closed (no hash match) rather than crashing the
     * lookup.
     */
    private const PREFIX_LENGTH = 12;

    public function handle(Request $request, Closure $next): Response
    {
        $plain = $this->extractToken($request);

        if ($plain === null || strlen($plain) < self::PREFIX_LENGTH) {
            return response()->json([
                'error' => 'unauthenticated',
                'message' => 'Bearer token required.',
            ], 401);
        }

        $prefix = substr($plain, 0, self::PREFIX_LENGTH);
        $hash = hash('sha256', $plain);

        // Prefix-indexed O(1) lookup, then constant-time hash compare.
        // Hash compare is the credential; the prefix is the index, not a secret.
        // Pull every column the downstream controllers may need. Keeping
        // this list explicit avoids a follow-up query and prevents the
        // `owner_*` columns from coming back as null (which would break
        // agent/host resolution downstream).
        $token = ApiToken::query()
            ->where('token_prefix', $prefix)
            ->get(['id', 'name', 'token_prefix', 'token_hash', 'owner_type', 'owner_id', 'abilities', 'last_used_at'])
            ->first(function (ApiToken $candidate) use ($hash): bool {
                return hash_equals($candidate->token_hash, $hash);
            });

        if ($token === null) {
            return response()->json([
                'error' => 'unauthenticated',
                'message' => 'Invalid token.',
            ], 401);
        }

        $request->attributes->set('tower_token', $token);
        $request->attributes->set('tower_token_id', $token->id);

        $this->touchLastUsedAt($token);

        return $next($request);
    }

    /**
     * Pull the bearer token from the standard `Authorization` header or the
     * `X-Tower-Token` header. Returns null when nothing usable is present.
     */
    private function extractToken(Request $request): ?string
    {
        $header = (string) $request->header('Authorization', '');
        if ($header !== '' && stripos($header, 'Bearer ') === 0) {
            return trim(substr($header, 7));
        }

        $alt = $request->header('X-Tower-Token');
        if (is_string($alt) && $alt !== '') {
            return trim($alt);
        }

        return null;
    }

    /**
     * Bump `last_used_at` on the matched token. Throttled to once a minute
     * per token via a short-lived cache key, so a burst of authorized calls
     * does not pound the table.
     */
    private function touchLastUsedAt(ApiToken $token): void
    {
        $key = 'tower-token-lastused:'.$token->id;
        if (\Illuminate\Support\Facades\Cache::has($key)) {
            return;
        }

        $token->forceFill(['last_used_at' => now()])->save();
        \Illuminate\Support\Facades\Cache::put($key, true, now()->addMinute());
    }
}
