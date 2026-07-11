<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Allowlist declaration. The new manifest replaces the agent's active
 * manifest (AllowlistController assigns a monotonically incremented version).
 *
 * `tools`, `scopes`, and `deny` are glob-matched strings used by DetectDrift
 * (fnmatch semantics). Empty arrays are allowed: a `tools: []` manifest is
 * intentionally strict and would flag every tool invocation.
 */
class StoreAllowlistRequest extends FormRequest
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
            'tools' => ['present', 'array'],
            'tools.*' => ['string', 'max:255'],
            'scopes' => ['sometimes', 'array'],
            'scopes.*' => ['string', 'max:255'],
            'deny' => ['sometimes', 'array'],
            'deny.*' => ['string', 'max:255'],
        ];
    }
}
