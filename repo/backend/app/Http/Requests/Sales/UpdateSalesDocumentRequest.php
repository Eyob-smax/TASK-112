<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSalesDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Controller enforces update authorization
    }

    public function rules(): array
    {
        return [
            'notes'                   => ['nullable', 'string', 'max:5000'],
            'line_items'              => ['nullable', 'array'],
            'line_items.*'            => ['array'],
            'line_items.*.product_code' => ['required', 'string', 'max:100'],
            'line_items.*.description'  => ['nullable', 'string', 'max:500'],
            'line_items.*.quantity'     => ['required', 'numeric', 'min:0.0001'],
            'line_items.*.unit_price'   => ['required', 'numeric', 'min:0'],
        ];
    }

    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $validator): void {
            $items = $this->input('line_items');

            if (!is_array($items)) {
                return;
            }

            foreach ($items as $index => $item) {
                if (!is_array($item)) {
                    continue;
                }

                $productCodeKey = "line_items.{$index}.product_code";
                if (!array_key_exists('product_code', $item) || $item['product_code'] === null || $item['product_code'] === '') {
                    $validator->errors()->add($productCodeKey, 'The ' . $productCodeKey . ' field is required.');
                }

                $quantityKey = "line_items.{$index}.quantity";
                if (!array_key_exists('quantity', $item) || $item['quantity'] === null || $item['quantity'] === '') {
                    $validator->errors()->add($quantityKey, 'The ' . $quantityKey . ' field is required.');
                } elseif (!is_numeric($item['quantity']) || (float) $item['quantity'] < 0.0001) {
                    $validator->errors()->add($quantityKey, 'The ' . $quantityKey . ' must be at least 0.0001.');
                }
            }
        });
    }
}
