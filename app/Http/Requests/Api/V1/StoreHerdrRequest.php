<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * herdr envelope (docs/contracts/tower.herdr.v1.json).
 *
 * We do NOT validate against the JSON Schema file at runtime; that is the
 * ContractSchemaTest's job. This request enforces the *coarse* shape we
 * need to translate the batch into canonical events: the per-batch size
 * cap and the field types the controller touches. Anything more rigorous
 * would duplicate the schema document.
 */
class StoreHerdrRequest extends FormRequest
{
    public const SCHEMA = 'tower.herdr.v1';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'schema' => ['required', 'string', 'in:'.self::SCHEMA],
            'bridge_version' => ['sometimes', 'nullable', 'string', 'max:64'],
            'herdr_version' => ['sometimes', 'nullable', 'string', 'max:64'],
            'host' => ['required', 'string', 'max:255'],
            'tier' => ['required', 'integer', Rule::in([0, 1])],
            'sent_at' => ['sometimes', 'nullable', 'date'],
            'batch' => ['sometimes', 'array', 'max:200'],
            'tails' => ['sometimes', 'array'],

            'batch.*.kind' => ['required_with:batch', 'string', Rule::in([
                'snapshot', 'pane.agent_status_changed', 'pane.agent_detected',
                'pane.closed', 'workspace.created', 'workspace.closed',
            ])],
            'batch.*.workspace' => ['sometimes', 'nullable', 'string', 'max:255'],
            'batch.*.pane' => ['sometimes', 'nullable', 'string', 'max:64'],
            'batch.*.agent_kind' => ['sometimes', 'nullable', 'string', 'max:64'],
            'batch.*.from' => ['sometimes', 'nullable', 'string', 'in:idle,working,blocked,done'],
            'batch.*.to' => ['sometimes', 'nullable', 'string', 'in:idle,working,blocked,done'],
            'batch.*.at' => ['sometimes', 'nullable', 'date'],
            'batch.*.dedupe_key' => ['sometimes', 'nullable', 'string', 'max:255'],
            'batch.*.workspaces' => ['sometimes', 'array'],
            'batch.*.workspaces.*.name' => ['required_with:batch.*.workspaces', 'string', 'max:255'],
            'batch.*.workspaces.*.panes' => ['required_with:batch.*.workspaces', 'array'],
            'batch.*.workspaces.*.panes.*.pane' => ['required', 'string', 'max:64'],
            'batch.*.workspaces.*.panes.*.agent_kind' => ['required', 'string', 'max:64'],
            'batch.*.workspaces.*.panes.*.state' => ['required', 'string', 'in:idle,working,blocked,done'],

            'tails.*.pane' => ['required_with:tails', 'string', 'max:64'],
            'tails.*.dedupe_key' => ['required_with:tails', 'string', 'max:255'],
            'tails.*.content_redacted' => ['required_with:tails', 'string'],
            'tails.*.redactions_applied' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ];
    }
}
