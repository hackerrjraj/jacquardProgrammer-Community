<?php

namespace App\Http\Requests;

use App\Models\ProfileListItem;
use App\Support\BdPhone;
use App\Support\ProfileLists;
use App\Support\RegistrationTerms;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Registration fields — MASTER_SPEC §16. */
class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // public form
    }

    protected function prepareForValidation(): void
    {
        $trimmed = array_map(
            fn ($value) => is_string($value) ? trim($value) : $value,
            $this->only(['name', 'email', 'district', 'workplace']),
        );

        $this->merge([
            ...$trimmed,
            'phone' => BdPhone::normalize($this->input('phone')),
            'email' => ($trimmed['email'] ?? null) === '' ? null : ($trimmed['email'] ?? null),
            ...$this->cleanOthers(),
        ]);
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $lists = app(ProfileLists::class);

        return [
            // Required only while the admin has the terms switched on (and written).
            'terms_accepted' => RegistrationTerms::isActive() ? ['required', 'accepted'] : ['nullable'],
            'name' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'regex:'.BdPhone::PATTERN, 'unique:users,phone'],
            'password' => ['required', 'string', 'min:8', 'max:100', 'confirmed'],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'district' => ['required', 'string', Rule::in(array_keys(config('districts')))],
            'workplace' => ['required', 'string', 'max:150'],
            'experience_years' => ['required', 'integer', 'min:0', 'max:60'],
            // Keys from the admin-managed lists (Phase 11d-1) — only active items can be picked.
            // At least one pick, or an "Other" text instead.
            'machines' => ['required_without:other_machine', 'array', 'max:20'],
            'machines.*' => ['required', 'string', 'distinct', Rule::in($lists->activeKeys(ProfileListItem::TYPE_MACHINE))],
            'skills' => ['required_without:other_skill', 'array', 'max:20'],
            'skills.*' => ['required', 'string', 'distinct', Rule::in($lists->activeKeys(ProfileListItem::TYPE_SKILL))],
            'other_machine' => ['nullable', 'string', 'max:100'],
            'other_skill' => ['nullable', 'string', 'max:100'],
        ];
    }

    /** "Other" machine / skill text: trimmed, single-spaced, empty = none. */
    private function cleanOthers(): array
    {
        $out = [];
        foreach (['other_machine', 'other_skill'] as $field) {
            $value = $this->input($field);
            $out[$field] = is_string($value) ? (ProfileLists::normalizeSpaces($value) ?: null) : null;
        }

        return $out;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'terms_accepted.required' => __('messages.terms_must_accept'),
            'terms_accepted.accepted' => __('messages.terms_must_accept'),
        ];
    }
}
