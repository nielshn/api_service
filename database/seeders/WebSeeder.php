<?php

namespace Database\Seeders;

use App\Models\Web;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class WebSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (!Web::exists()) {
            Web::create([
                'web_nama' => 'Sistem Inventaris',
                'web_deskripsi' => 'Deskripsi default sistem.',
                'web_logo' => 'img/web/logo_icon.png',
                'user_id' => 1,
            ]);
        }
    }
}
