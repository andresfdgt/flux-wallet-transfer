<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TransferRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'idempotency_key'       => ['required', 'string', 'max:255'],
            'source_wallet_id'      => ['required', 'integer', 'exists:wallets,id'],
            'destination_wallet_id' => ['required', 'integer', 'exists:wallets,id', 'different:source_wallet_id'],
            'amount'                => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'currency'              => ['required', 'string', 'size:3'],
            'description'           => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'idempotency_key.required' => 'idempotency_key is required',
            'source_wallet_id.exists' => 'Source wallet not found',
            'destination_wallet_id.exists' => 'Destination wallet not found',
            'destination_wallet_id.different' => 'Source and destination wallets must be different.',
            'amount.gt' => 'amount must be greater than 0',
            'amount.decimal' => 'amount must be a valid decimal with up to 2 decimal places',
            'currency.size' => 'currency must be a 3-letter code'
        ];
    }
}
