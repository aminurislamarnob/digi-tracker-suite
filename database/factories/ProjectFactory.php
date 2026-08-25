<?php

namespace Database\Factories;

use App\Models\Account;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ProjectFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'account_id' => Account::factory(),
            'hash' => (string) Str::uuid(),
            'name' => Str::title($name),
            'slug' => Str::slug($name),
            'type' => 'plugin',
            'is_active' => true,
        ];
    }
}
