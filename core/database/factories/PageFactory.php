<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class PageFactory extends Factory
{
    public function definition(): array
    {
        return ['title' => fake()->sentence(3), 'slug' => fake()->unique()->slug(3), 'published' => false, 'content' => [], 'settings' => ['show_heading' => true]];
    }
}
