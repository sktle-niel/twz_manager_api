<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->name();

        return [
            'name' => $name,
            // The username is the credential now, so it has to be unique
            'username' => Str::slug($name, '.').'.'.fake()->unique()->numberBetween(1, 99999),
            'password' => static::$password ??= Hash::make('password'),
            'role' => User::ROLE_MANAGER,
            'store_id' => null,
            'active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    public function owner(): static
    {
        return $this->state(fn (array $attributes) => ['role' => User::ROLE_OWNER]);
    }

    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => ['active' => false]);
    }
}
