<?php

namespace Database\Factories;

use App\Models\HeroSlide;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HeroSlide>
 */
class HeroSlideFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'image_path' => 'slides/'.fake()->unique()->slug(2).'.svg',
            'title_bn' => 'শিরোনাম',
            'title_en' => fake()->sentence(3),
            'subtitle_bn' => 'উপশিরোনাম',
            'subtitle_en' => fake()->sentence(6),
            'button_text_bn' => null,
            'button_text_en' => null,
            'button_link' => null,
            'sort_order' => 0,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
