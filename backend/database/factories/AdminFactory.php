<?php

namespace Database\Factories;

use App\Models\Admin;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** @extends Factory<Admin> */
class AdminFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => mb_substr(fake()->firstName(), 0, 50),
            'email' => fake()->unique()->safeEmail(),
            'password' => Hash::make(Str::random(32)),
            'status' => 'active',
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => ['status' => 'disabled']);
    }
}
