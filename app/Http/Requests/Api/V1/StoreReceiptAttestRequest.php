<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Receipt attestation. The honesty model (ARCHITECTURE.md §1.2) requires a
 * non-empty `url` or `summary`; the controller calls AttestReceipt which
 * enforces that contract. The form request only validates the *shape* of
 * the payload — the "at least one of" rule lives in the action.
 */
class StoreReceiptAttestRequest extends FormRequest
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
            'url' => ['sometimes', 'nullable', 'string', 'max:2048', 'url'],
            'summary' => ['sometimes', 'nullable', 'string', 'max:65535'],
            'kind' => ['sometimes', 'nullable', 'string', 'in:link,artifact,text'],
        ];
    }
}
