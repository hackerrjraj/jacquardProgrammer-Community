<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Admin edits a member's name and/or email (MASTER_SPEC §19). Other profile
 * fields are not in the rules, so they are ignored.
 */
class AdminUpdateMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware: admin / super_admin
    }

    protected function prepareForValidation(): void
    {
        $this->merge(array_map(
            fn ($value) => is_string($value) ? trim($value) : $value,
            $this->only(['name', 'email']),
        ));

        if ($this->has('email') && $this->input('email') === '') {
            $this->merge(['email' => null]);
        }
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->route('member'))],
        ];
    }
}
