<?php

namespace Database\Factories;

use App\Enums\PublishStatus;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return [
            'category_id' => Category::factory(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 1_000_000),
            'description' => fake()->sentence(),
            'publish_status' => PublishStatus::DRAFT,
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => ['publish_status' => PublishStatus::PUBLISHED]);
    }

    public function archived(): static
    {
        return $this->state(fn () => ['publish_status' => PublishStatus::ARCHIVED]);
    }
}
