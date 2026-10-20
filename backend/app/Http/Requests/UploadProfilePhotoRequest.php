<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Profile photo — JPG/PNG/WebP, max 2 MB (MASTER_SPEC §38). */
class UploadProfilePhotoRequest extends FormRequest
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
            'photo' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'extensions:jpg,jpeg,png,webp', 'max:2048'],
        ];
    }
}
