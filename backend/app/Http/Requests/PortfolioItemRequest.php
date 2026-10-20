<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Portfolio item (MASTER_SPEC §19). Adding: image, category and title required.
 * Updating: send only what changes (image optional).
 */
class PortfolioItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware: logged in + approved; ownership checked in the controller
    }

    protected function prepareForValidation(): void
    {
        $this->merge(array_map(
            fn ($value) => is_string($value) ? trim($value) : $value,
            $this->only(['category', 'title', 'description']),
        ));

        if ($this->has('description') && $this->input('description') === '') {
            $this->merge(['description' => null]);
        }
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $adding = $this->isMethod('post');
        $required = $adding ? 'required' : 'sometimes';

        return [
            'image' => [$required, 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'extensions:jpg,jpeg,png,webp', 'max:2048'],
            'category' => [$required, 'string', Rule::in(array_keys(config('profile_options.portfolio_categories')))],
            'title' => [$required, 'string', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:300'],
        ];
    }
}
