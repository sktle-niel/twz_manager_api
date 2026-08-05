<?php

namespace Database\Seeders;

use App\Models\ExpenseCategory;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/*
 * The owner everywhere, and fixtures for the test suite.
 *
 * The owner is seeded from .env (OWNER_USERNAME / OWNER_PASSWORD), and the
 * password only lands when the account is first created: after that the app
 * owns it, and reseeding must never quietly undo a password the owner set
 * for themselves.
 *
 * Branches are NOT seeded outside the test suite anymore: the real ones come
 * from Loyverse via `twz:sync-stores`. The four invented branches and their
 * managers exist only so the tests have a known world; they reset to their
 * known passwords on every seed, which is what test data should do.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedOwner();
        $this->seedCategories();

        /*
         * Everything below is fixture data for the TEST SUITE, and only the
         * test suite. Real branches come from Loyverse — `twz:sync-stores` —
         * in development and production alike; reseeding must never plant
         * the four invented branches next to the real ones.
         */
        if (! app()->environment('testing')) {
            return;
        }

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
        $managers = [
            ['name' => 'Marvin Deocampo', 'username' => 'marvin.deocampo', 'store_id' => 'arevalo', 'password' => $password],
            ['name' => 'Joel Sarabia', 'username' => 'joel.sarabia', 'store_id' => 'molo', 'password' => $password],
            ['name' => 'Rhea Villanueva', 'username' => 'rhea.villanueva', 'store_id' => 'jaro', 'password' => $password],
            // For poking at the manager side without touching a "real" account
            ['name' => 'Test Account', 'username' => 'testaccount', 'store_id' => 'lapaz', 'password' => Hash::make('test1234')],
        ];
        foreach ($managers as $manager) {
            User::query()->updateOrCreate(['username' => $manager['username']], [
                ...$manager,
                'role' => User::ROLE_MANAGER,
                'active' => true,
            ]);
        }
    }

    /*
     * Starting categories, everywhere — real config, not a fixture. Seeded
     * only while the table is empty: once the owner has edited the list,
     * reseeding must never resurrect what they removed.
     */
    private function seedCategories(): void
    {
        if (ExpenseCategory::query()->exists()) {
            return;
        }

        $categories = [
            ['id' => 'meals', 'name' => 'Meals', 'receipt_exempt' => true],
            ['id' => 'merienda', 'name' => 'Merienda', 'receipt_exempt' => true],
            ['id' => 'water-bill', 'name' => 'Water bill', 'receipt_exempt' => false],
            ['id' => 'electric-bill', 'name' => 'Electric bill', 'receipt_exempt' => false],
            ['id' => 'wifi-bill', 'name' => 'Wifi bill', 'receipt_exempt' => false],
            ['id' => 'other', 'name' => 'Other', 'receipt_exempt' => false],
        ];
        foreach ($categories as $position => $category) {
            ExpenseCategory::query()->create([...$category, 'position' => $position]);
        }
    }

    private function seedOwner(): void
    {
        $owner = User::query()->where('role', User::ROLE_OWNER)->first();

        if ($owner === null) {
            User::query()->create([
                'name' => 'Two Wheels Zone',
                'username' => config('twz.owner_username'),
                'password' => config('twz.owner_password'),
                'role' => User::ROLE_OWNER,
                'store_id' => null,
                'active' => true,
            ]);

            return;
        }

        /* The username keeps following .env — it is configuration. The
           password is deliberately not touched: the moment the owner changes
           it in the app, the database owns it, and OWNER_PASSWORD is only
           ever the first sign-in. */
        $owner->forceFill(['username' => config('twz.owner_username')])->save();
    }
}
