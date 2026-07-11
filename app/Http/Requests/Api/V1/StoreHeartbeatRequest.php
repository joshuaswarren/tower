<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Heartbeat is intentionally minimal: the agent identifies itself via the
 * authenticated token (token owner = Agent, per ADR-0002). The body may
 * optionally carry a new status; status writes are constrained to the
 * documented AgentStatus values.
 */
class StoreHeartbeatRequest extends FormRequest
{
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
            'status' => ['sometimes', 'string', 'in:idle,working,blocked,done,offline'],
        ];
    }
}
