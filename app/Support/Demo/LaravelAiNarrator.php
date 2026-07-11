<?php

declare(strict_types=1);

namespace App\Support\Demo;

use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\TextDelta;

/**
 * The REAL demo narrator (docs/ARCHITECTURE.md §1.7).
 *
 * Wires `Laravel\Ai\AnonymousAgent` (the first-party Laravel AI SDK agent
 * contract) and streams `TextDelta` events from the configured provider.
 * This class is bound when an AI provider key is configured — see
 * `App\Providers\AppServiceProvider::register` and the
 * `tower.demo.narrator` config key.
 *
 * If a future SDK bumps `TextDelta` out from under us, the
 * `StreamingContractTest` (tests/Feature/Demo/) will go red. The interface
 * `DemoNarrator` is stable; the SDK is replaceable.
 */
class LaravelAiNarrator implements DemoNarrator
{
    public function __construct(
        /**
         * The agent factory closure. Resolved from container or overridden
         * in tests. Defaults to a fresh `AnonymousAgent` with empty
         * messages/tools and the given instructions.
         *
         * @var (callable(string $instructions): AnonymousAgent)
         */
        private $agentFactory = null,
    ) {
        $this->agentFactory ??= static fn (string $instructions): AnonymousAgent
            => new AnonymousAgent($instructions, [], []);
    }

    public function stream(string $prompt, callable $onChunk): string
    {
        $instructions = (string) config('tower.demo.narrator_instructions', '');
        $agent = ($this->agentFactory)($instructions);

        /** @var StreamableAgentResponse $response */
        $response = $agent->stream($prompt);

        $buffer = '';
        foreach ($response as $event) {
            if ($event instanceof TextDelta) {
                $buffer .= $event->delta;
                $onChunk($event->delta);
            }
        }

        // Belt-and-braces: even if no TextDelta was emitted, the
        // StreamableAgentResponse exposes a combined `$text` after the
        // iterator is consumed. We trust the assembled text from the SDK.
        return $response->text ?? $buffer;
    }
}
