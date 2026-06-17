<?php

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateVariantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $id = $this->route('variant')->id;

        return [
            'sku' => ['required', 'string', 'max:255', Rule::unique('product_variants', 'sku')->ignore($id)],
            'size' => ['nullable', 'string', 'max:50'],
            'color' => ['nullable', 'string', 'max:50'],
            'price' => ['required', 'numeric', 'min:0'],
        ];
    }
}
