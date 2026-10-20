<?php

namespace App\Http\Requests;

use App\Models\ProfileListItem;
use App\Models\User;
use App\Support\ProfileLists;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Own profile edit — MASTER_SPEC §19–§20. Every field is optional (send only what changes).
 * Mobile, name, role and status are not editable here: they are not in the rules,
 * so validated() never contains them and they are ignored.
 */
class UpdateProfileRequest extends FormRequest
{
    public const MAX_WORK_HISTORY = 15;

    public function authorize(): bool
    {
        return true; // route middleware: logged in + approved
    }

    protected function prepareForValidation(): void
    {
        $this->merge(array_map(
            fn ($value) => is_string($value) ? trim($value) : $value,
            $this->only(['bio', 'district', 'workplace', 'designation', 'email']),
        ));

        foreach (['bio', 'designation', 'email'] as $field) {
            if ($this->has($field) && $this->input($field) === '') {
                $this->merge([$field => null]);
            }
        }

        foreach (['other_machine', 'other_skill'] as $field) {
            if ($this->has($field)) {
                $value = $this->input($field);
                $this->merge([$field => is_string($value) ? (ProfileLists::normalizeSpaces($value) ?: null) : null]);
            }
        }

        if (is_array($history = $this->input('work_history'))) {
            $this->merge(['work_history' => array_map(function ($entry) {
                if (! is_array($entry)) {
                    return $entry;
                }
                foreach (['company', 'position'] as $key) {
                    if (isset($entry[$key]) && is_string($entry[$key])) {
                        $entry[$key] = trim($entry[$key]);
                    }
                }
                if (isset($entry['end_year']) && is_string($entry['end_year']) && strtolower(trim($entry['end_year'])) === 'present') {
                    $entry['end_year'] = 'present';
                }

                return $entry;
            }, $history)]);
        }
    }

    /** @return list<string> active list keys + the keys already saved on this member's profile */
    private function allowedKeys(string $type): array
    {
        $saved = array_filter((array) ($this->user()->{ProfileListItem::COLUMNS[$type]} ?? []), 'is_string');

        return array_values(array_unique([...app(ProfileLists::class)->activeKeys($type), ...$saved]));
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $visibility = ['sometimes', 'required', Rule::in(User::VISIBILITIES)];
        $thisYear = (int) now('Asia/Dhaka')->year;

        return [
            'email' => ['sometimes', 'nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->user()->id)],
            'bio' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'district' => ['sometimes', 'required', 'string', Rule::in(array_keys(config('districts')))],
            'workplace' => ['sometimes', 'required', 'string', 'max:150'],
            'employment_status' => ['sometimes', 'required', Rule::in(array_keys(config('profile_options.employment_statuses')))],
            'designation' => ['sometimes', 'nullable', 'string', 'max:100'],
            'experience_years' => ['sometimes', 'required', 'integer', 'min:0', 'max:60'],
            // Keys from the admin-managed lists (Phase 11d-1): active items, plus anything this member already has
            // saved (a hidden item or an old unmapped text stays when the rest of the list is changed).
            // At least one pick, or an "Other" text instead.
            'machines' => ['sometimes', 'required_without:other_machine', 'array', 'max:20'],
            'machines.*' => ['required', 'string', 'distinct', Rule::in($this->allowedKeys(ProfileListItem::TYPE_MACHINE))],
            'skills' => ['sometimes', 'required_without:other_skill', 'array', 'max:20'],
            'skills.*' => ['required', 'string', 'distinct', Rule::in($this->allowedKeys(ProfileListItem::TYPE_SKILL))],
            'other_machine' => ['sometimes', 'nullable', 'string', 'max:100'],
            'other_skill' => ['sometimes', 'nullable', 'string', 'max:100'],
            'phone_visibility' => $visibility,
            'email_visibility' => $visibility,
            'workplace_visibility' => $visibility,

            // The whole list is replaced; [] removes all entries.
            'work_history' => ['sometimes', 'present', 'array', 'max:'.self::MAX_WORK_HISTORY],
            'work_history.*' => ['array:company,position,start_year,end_year'],
            'work_history.*.company' => ['required', 'string', 'max:150'],
            'work_history.*.position' => ['required', 'string', 'max:100'],
            'work_history.*.start_year' => ['required', 'integer', 'min:1950', 'max:'.$thisYear],
            'work_history.*.end_year' => ['required', function (string $attribute, mixed $value, Closure $fail) use ($thisYear) {
                if ($value === 'present') {
                    return;
                }
                if (filter_var($value, FILTER_VALIDATE_INT) === false || $value > $thisYear) {
                    $fail(__('messages.end_year_invalid', ['max' => $thisYear]));

                    return;
                }
                $start = $this->input(str_replace('.end_year', '.start_year', $attribute));
                if (filter_var($start, FILTER_VALIDATE_INT) !== false && (int) $value < (int) $start) {
                    $fail(__('messages.end_year_before_start'));
                }
            }],
        ];
    }
}
