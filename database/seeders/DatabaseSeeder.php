<?php

namespace Database\Seeders;

use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/*
 * The four branches and the accounts the frontend's sample mode already
 * knows, so switching VITE_DATA_SOURCE from sample to the real backend keeps
 * every familiar identifier working. Development password for every seeded
 * account: "password" — rotate real credentials before anything is deployed.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $stores = [
            ['id' => 'arevalo', 'name' => 'Arevalo'],
            ['id' => 'molo', 'name' => 'Molo'],
            ['id' => 'jaro', 'name' => 'Jaro'],
            ['id' => 'lapaz', 'name' => 'La Paz'],
        ];
        foreach ($stores as $store) {
            Store::query()->updateOrCreate(['id' => $store['id']], $store);
        }

        $password = Hash::make('password');

        User::query()->updateOrCreate(['username' => 'twz.owner'], [
            'name' => 'Two Wheels Zone',
            'email' => 'owner@gmail.com',
            'password' => $password,
            'role' => User::ROLE_OWNER,
            'store_id' => null,
            'active' => true,
        ]);

        $managers = [
            ['name' => 'Marvin Deocampo', 'username' => 'marvin.deocampo', 'email' => 'marvin.deocampo@gmail.com', 'store_id' => 'arevalo'],
            ['name' => 'Joel Sarabia', 'username' => 'joel.sarabia', 'email' => 'joel.sarabia@gmail.com', 'store_id' => 'molo'],
            ['name' => 'Rhea Villanueva', 'username' => 'rhea.villanueva', 'email' => 'rhea.villanueva@gmail.com', 'store_id' => 'jaro'],
        ];
        foreach ($managers as $manager) {
            User::query()->updateOrCreate(['username' => $manager['username']], [
                ...$manager,
                'password' => $password,
                'role' => User::ROLE_MANAGER,
                'active' => true,
            ]);
        }
    }
}
