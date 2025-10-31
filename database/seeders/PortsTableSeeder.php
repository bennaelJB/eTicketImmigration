<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Support\Facades\DB;

class PortsTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('ports')->insert([
            [
                'name' => 'Port international de Port-au-Prince',
                'status' => 'active',
                'type' => 'sea',
                'location' => 'Port-au-Prince, Ouest, Haïti',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Aéroport international Toussaint Louverture',
                'status' => 'active',
                'type' => 'air',
                'location' => 'Port-au-Prince, Ouest, Haïti',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Port de Cap-Haïtien',
                'status' => 'active',
                'type' => 'sea',
                'location' => 'Cap-Haïtien, Nord, Haïti',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Aéroport international Cap-Haïtien',
                'status' => 'active',
                'type' => 'air',
                'location' => 'Cap-Haïtien, Nord, Haïti',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
