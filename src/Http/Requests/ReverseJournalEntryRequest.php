<?php

namespace Nit\AgraniAccounts\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReverseJournalEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reversal_date' => ['nullable', 'date'],
            'narration' => ['nullable', 'string', 'max:500'],
        ];
    }
}
