<?php

namespace App\Http\Requests\Attachment;

use Illuminate\Foundation\Http\FormRequest;

class StoreAttachmentRequest extends FormRequest
{
    /**
     * Only users with 'upload attachments' permission may upload.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('upload attachments') ?? false;
    }

    /**
     * Validation rules.
     *
     * Accepts an array of files via the `files` key to support multi-file batch upload.
     * File constraints (from FileConstraints value object):
     *   - Max size per file: 25 MB = 25600 KB (Laravel's max rule uses KB)
     *   - Allowed MIME types: the 5 permitted types from AllowedMimeType enum
     *
     * The 20-file-per-record aggregate limit is enforced at the controller level
     * (requires a database count query). Per-file MIME/size validation is here.
     */
    public function rules(): array
    {
        return [
            'files'         => ['required', 'array', 'min:1'],
            'files.*'       => [
                'required',
                'file',
                'max:25600', // 25 MB in kilobytes
                'mimetypes:application/pdf,'
                    . 'application/vnd.openxmlformats-officedocument.wordprocessingml.document,'
                    . 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,'
                    . 'image/png,'
                    . 'image/jpeg',
            ],
            'validity_days' => ['nullable', 'integer', 'min:1', 'max:' . config('meridian.attachments.max_validity_days', 3650)],
        ];
    }

    /**
     * Custom error messages.
     */
    public function messages(): array
    {
        return [
            'files.required'    => 'At least one file is required.',
            'files.array'       => 'Files must be provided as an array.',
            'files.*.required'  => 'Each file entry is required.',
            'files.*.max'       => 'Each file may not be larger than 25 MB.',
            'files.*.mimetypes' => 'File type is not permitted. Allowed types: PDF, DOCX, XLSX, PNG, JPEG.',
        ];
    }
}
