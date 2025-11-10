<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\RoleUser;
use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::factory()->create([
            'name' => 'Super Administrador',
            'email' => 'superadmin@gmail.com',
            'phone' => '977743053',
            'password' => bcrypt('12345678'),
            'has_full_access' => true,
        ]);

        User::factory()->create([
            'name' => 'Administrador',
            'email' => 'admin@gmail.com',
            'phone' => '933054810',
            'password' => bcrypt('12345678'),

        ]);

    }
}
