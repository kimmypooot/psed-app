<?php

namespace App\Http\Requests;

use App\Enums\ReferenceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReferenceDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Only PSED staff and administrators manage the reference page.
        return $this->user()->can('manage-references');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(ReferenceType::class)],
            'reference_no' => ['nullable', 'string', 'max:255'],
            'issued_on' => ['nullable', 'date'],
            'summary' => ['nullable', 'string', 'max:5000'],
            // Only web links; never javascript: or data: URLs that would run when clicked.
            'url' => ['nullable', 'string', 'max:2048', 'regex:#^https?://#i', 'url'],
            'file' => ['nullable', 'file', 'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx', 'max:20480'],
            'remove_file' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'url.regex' => 'Enter a web address that starts with http:// or https://.',
            'file.max' => 'The file may not be larger than 20 MB.',
            'file.mimes' => 'Upload a PDF, Word, Excel or PowerPoint file.',
        ];
    }
}
