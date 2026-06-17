<?php

namespace App\Http\Requests\Shop;

use App\Enums\PublishStatus;
use App\Models\ProductVariant;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class AddCartItemRequest extends FormRequest
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
        return [
            'product_variant_id' => ['required', 'integer', $this->variantAvailable()],
            'quantity' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * Variant phải tồn tại VÀ thuộc product đã PUBLISHED (không soft-deleted).
     */
    private function variantAvailable(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $exists = ProductVariant::query()
                ->where('id', $value)
                ->whereHas('product', fn ($q) => $q->where('publish_status', PublishStatus::PUBLISHED->value))
                ->exists();

            if (! $exists) {
                $fail('The selected product is not available.');
            }
        };
    }
}
