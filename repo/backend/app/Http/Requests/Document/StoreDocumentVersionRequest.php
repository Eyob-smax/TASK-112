<?php

namespace App\Http\Requests\Document;

use Illuminate\Foundation\Http\FormRequest;

class StoreDocumentVersionRequest extends FormRequest
{
    /**
     * Authorization is handled in the controller via DocumentPolicy::update().
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validation rules for version uploads.
     *
     * The 25 MB size limit is consistent with FileConstraints::MAX_SIZE_BYTES.
     * MIME validation here checks the declared type; the service layer additionally
     * performs a finfo magic-bytes check for MIME spoofing prevention.
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:25600', // 25 MB in kilobytes
                'mimetypes:application/pdf,'
                    . 'application/vnd.openxmlformats-officedocument.wordprocessingml.document,'
                    . 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,'
                    . 'image/png,'
                    . 'image/jpeg',
            ],
            'page_count'          => ['nullable', 'integer', 'min:1', 'max:9999'],
            'sheet_count'         => ['nullable', 'integer', 'min:1', 'max:9999'],
            'is_previewable'      => ['nullable', 'boolean'],
            'thumbnail_available' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required'  => 'A file is required.',
            'file.max'       => 'The file may not be larger than 25 MB.',
            'file.mimetypes' => 'The file type is not permitted. Allowed types: PDF, DOCX, XLSX, PNG, JPEG.',
        ];
    }
}
