<?php

declare(strict_types=1);

namespace App\Support\Demo;

/**
 * The demo narrator contract (docs/ARCHITECTURE.md §1.7).
 *
 * Demo agents call `stream($prompt, $onChunk)` to generate a response in
 * real-time. The implementation may stream from a remote provider, a
 * deterministic local fake, or anything else — the contract is the same:
 *   - $onChunk is invoked for every streamed text fragment,
 *   - the FULL assembled text is returned synchronously after the stream
 *     completes (so the caller can store it on the run / receipt).
 *
 * The contract is intentionally NOT coupled to any particular SDK
 * (currently `Laravel\Ai\AnonymousAgent` + `Laravel\Ai\Streaming\Events\TextDelta`).
 * A breaking change in the SDK surfaces as a failing test against the
 * `FakeNarrator` reassembly logic, not silent drift.
 */
interface DemoNarrator
{
    /**
     * Stream a response to `$prompt`, invoking `$onChunk($textFragment)`
     * for every fragment the narrator produces, and return the FULL
     * assembled text once the stream is complete.
     *
     * @param  callable(string): void  $onChunk
     */
    public function stream(string $prompt, callable $onChunk): string;
}
