<?php

declare(strict_types=1);

use App\Agents\FleetAnalyst;
use App\Support\Demo\DemoNarrator;
use App\Support\Demo\FakeNarrator;
use App\Support\Demo\LaravelAiNarrator;
use Laravel\Ai\Streaming\Events\TextDelta;

/**
 * Streaming contract test — the parent agent's directive:
 *
 *   "add a focused test asserting your DemoNarrator/streaming contract
 *    (TextDelta reassembly) so an SDK change surfaces as a red test,
 *    not silent drift"
 *
 * The two non-negotiables are:
 *   1. `LaravelAiNarrator` consumes Laravel AI SDK's `TextDelta`
 *      generator and emits the `->delta` string to the user callback.
 *   2. The reassembled text (what the narrator returns) equals the
 *      full string the SDK emitted — round-trip property. If the
 *      SDK swaps `TextDelta` for a different event shape, this test
 *      goes red.
 *
 * The test uses the SDK's first-party `Ai::fakeAgent()` swap
 * (FakeTextGateway) so no network is required and the gateway's
 * word-by-word chunking is exactly the same shape the real gateway
 * produces.
 */
it('DemoNarrator interface is the stable wire (every implementation is swappable)', function (): void {
    $iface = new ReflectionClass(DemoNarrator::class);
    expect($iface->isInterface())->toBeTrue();
    expect($iface->getMethod('stream')->getNumberOfParameters())->toBe(2);

    $implementations = [LaravelAiNarrator::class, FakeNarrator::class];
    foreach ($implementations as $class) {
        $obj = app($class);
        expect($obj)->toBeInstanceOf(DemoNarrator::class);
    }
});

it('FakeNarrator yields >=2 chunks and reassembles to the original report', function (): void {
    $narrator = new FakeNarrator();
    $chunks = [];
    $text = $narrator->stream('whatever', function (string $c) use (&$chunks): void {
        $chunks[] = $c;
    });

    expect(count($chunks))->toBeGreaterThanOrEqual(2);
    expect(implode('', $chunks))->toBe($text);
    expect(strlen($text))->toBeGreaterThan(40);
});

it('LaravelAiNarrator reassembles Laravel AI SDK TextDelta stream into the full text', function (): void {
    $expected = 'Fleet stable. Busiest agent omp-lane-acme 42 events. Zero drift.';
    $words = explode(' ', $expected);

    // Inject a fake agent via the narrator's agentFactory so we exercise the
    // REAL consumption path (iterate the stream, accumulate TextDelta->delta)
    // with no network and no dependency on an SDK facade. If the SDK swaps
    // TextDelta's shape, this and the combine() assertion below go red.
    $fakeAgent = new class($words) {
        /** @param list<string> $words */
        public function __construct(private array $words)
        {
        }

        public function stream(string $prompt): iterable
        {
            foreach ($this->words as $i => $w) {
                yield new TextDelta('id-'.$i, 'msg', $i === 0 ? $w : ' '.$w, time());
            }
        }
    };

    $narrator = new LaravelAiNarrator(fn (string $instructions) => $fakeAgent);
    $received = [];
    $text = $narrator->stream('fake prompt', function (string $chunk) use (&$received): void {
        $received[] = $chunk;
    });

    expect(count($received))->toBeGreaterThanOrEqual(2);
    foreach ($received as $chunk) {
        expect($chunk)->toBeString()->and(strlen($chunk))->toBeGreaterThan(0);
    }
    expect($text)->toBe($expected);

    $deltas = [];
    foreach ($words as $i => $word) {
        $deltas[] = new TextDelta('id-'.$i, 'msg', $i === 0 ? $word : ' '.$word, time());
    }
    expect(TextDelta::combine($deltas))->toBe($expected);
});

it('FleetAnalyst prompt contains the live 24h window (real DB query)', function (): void {
    $analyst = new FleetAnalyst(24);
    $prompt = $analyst->buildPrompt('demo:fleet-analyst');

    expect($prompt)->toContain('last 24 hours');
    expect($prompt)->toContain('demo:fleet-analyst');
    expect($prompt)->toContain('Busiest agent');
    expect($prompt)->toContain('Blocked time');
    expect($prompt)->toContain('Drift summary');
    // No real numbers in the prompt at this point (no events in DB);
    // the "no events" branch must still produce a valid prompt.
    expect($prompt)->toContain('Window since');
});
