<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Member document — type + PDF/JPG/PNG, max 5 MB (MASTER_SPEC §19, §38). */
class UploadMemberDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware: logged in + approved
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(array_keys(config('profile_options.document_types')))],
            'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'extensions:pdf,jpg,jpeg,png', 'max:5120'],
        ];
    }
}
