<?php

declare(strict_types=1);

namespace App\Support\Demo;

/**
 * The OFFLINE-ONLY fake narrator (tests + local dev with no provider key).
 *
 * Not a substitute for the real `LaravelAiNarrator`. Per
 * docs/ARCHITECTURE.md §1.7 + the "Offline-only fake" guardrail, this
 * class exists so the demo loop is deterministic in tests and runnable
 * without an API key in development. It is bound by the service
 * provider when no provider key is configured; in production with a
 * key the binding is `LaravelAiNarrator`.
 *
 * The chunking strategy mirrors how `Laravel\Ai\Gateway\FakeTextGateway`
 * splits text on spaces (its `generateStreamStep` iterates
 * `Str::of($text)->explode(' ')`). The reassembly in
 * `StreamingContractTest` therefore exercises the same code path
 * the real SDK uses: collect deltas, combine in order, compare to
 * the original.
 */
class FakeNarrator implements DemoNarrator
{
    /**
     * The deterministic report produced for a 24h fleet-health prompt.
     * Word boundaries become chunk boundaries, yielding >=2 chunks
     * for the streaming contract test to assert on.
     */
    private const REPORT = 'Fleet health over the last 24 hours: stable. '
        .'Busiest agent is omp-lane-acme with 42 events. '
        .'Blocked time 7 minutes across 3 agents. '
        .'Drift summary: 0 violations, 2 warnings acknowledged.';

    public function stream(string $prompt, callable $onChunk): string
    {
        $text = self::REPORT;
        $words = explode(' ', $text);
        $buffer = '';

        foreach ($words as $index => $word) {
            // Match the FakeTextGateway word-by-word streaming shape.
            $fragment = $index === 0 ? $word : ' '.$word;
            $buffer .= $fragment;
            $onChunk($fragment);
        }

        return $buffer;
    }
}
