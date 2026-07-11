<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\EventType;
use App\Enums\RunState;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Generic ingest envelope (docs/contracts/tower.ingest.v1.json).
 *
 * The form request enforces the envelope shape and the per-batch size cap
 * (`tower.ingest.max_batch_size` = 100). The per-event payload size cap
 * (`tower.ingest.max_payload_kb` = 256) is enforced in the controller
 * before validation runs because we need to look at the raw request body
 * size to return 413 (Payload Too Large), not a 422.
 *
 * Schema identity is fixed; we reject anything else with 422.
 */
class StoreEventsRequest extends FormRequest
{
    public const SCHEMA = 'tower.ingest.v1';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxBatch = (int) config('tower.ingest.max_batch_size', 100);

        return [
            'schema' => ['required', 'string', 'in:'.self::SCHEMA],
            'sent_at' => ['sometimes', 'nullable', 'date'],
            'events' => ['required', 'array', 'min:1', 'max:'.$maxBatch],
            'events.*' => ['required', 'array'],
            'events.*.type' => ['required', 'string', Rule::in(array_map(
                fn (EventType $t) => $t->value,
                EventType::cases()
            ))],
            'events.*.occurred_at' => ['sometimes', 'nullable', 'date'],
            'events.*.dedupe_key' => ['sometimes', 'nullable', 'string', 'max:255'],
            'events.*.payload' => ['sometimes', 'nullable', 'array'],

            // run.state_changed shape
            'events.*.run' => ['sometimes', 'array'],
            'events.*.run.external_id' => ['required_with:events.*.run', 'string', 'max:255'],
            'events.*.run.title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'events.*.from' => ['sometimes', 'nullable', 'string', Rule::in(array_map(
                fn (RunState $s) => $s->value,
                RunState::cases()
            ))],
            'events.*.to' => ['sometimes', 'nullable', 'string', Rule::in(array_map(
                fn (RunState $s) => $s->value,
                RunState::cases()
            ))],

            // tool.invoked shape
            'events.*.tool' => ['sometimes', 'nullable', 'string', 'max:255'],

            // exit_state for terminal transitions
            'events.*.exit_state' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Conditional requirement: `run.state_changed` MUST carry a `run` and `to`.
     * Implemented via a `after` callback because Laravel's `required_if`
     * doesn't reach into array items cleanly with custom keys.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $events = (array) $this->input('events', []);
            foreach ($events as $i => $event) {
                if (!is_array($event)) {
                    continue;
                }
                if (($event['type'] ?? null) === EventType::RunStateChanged->value) {
                    if (!isset($event['run']['external_id']) || !isset($event['to'])) {
                        $v->errors()->add(
                            "events.{$i}",
                            "run.state_changed requires run.external_id and to"
                        );
                    }
                }
            }
        });
    }
}
