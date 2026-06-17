<?php

namespace App\Services\Media;

use App\Models\Product;
use App\Models\ProductMedia;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Laravel\Facades\Image;

/**
 * Abstraction lưu/transform media sản phẩm. Hiện dùng disk local ('public') + resize/optimize
 * bằng intervention/image. Đổi sang S3 = đổi disk, không động tới caller (spec §0.5).
 */
class MediaService
{
    private const MAX_WIDTH = 1200;

    private const DISK = 'public';

    private const QUALITY = 80;

    public function upload(Product $product, UploadedFile $file): ProductMedia
    {
        $encoded = Image::read($file->getPathname())
            ->scaleDown(width: self::MAX_WIDTH)
            ->encodeByExtension('jpg', quality: self::QUALITY);

        $path = "products/{$product->id}/".Str::uuid()->toString().'.jpg';
        Storage::disk(self::DISK)->put($path, (string) $encoded);

        return $product->media()->create([
            'path' => $path,
            'disk' => self::DISK,
            'is_primary' => ! $product->media()->where('is_primary', true)->exists(),
            'sort_order' => $product->media()->count(),
        ]);
    }

    public function setPrimary(ProductMedia $media): ProductMedia
    {
        ProductMedia::query()
            ->where('product_id', $media->product_id)
            ->update(['is_primary' => false]);

        $media->update(['is_primary' => true]);

        return $media->refresh();
    }
}
