<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('transaction_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->softDeletes();
            $table->timestamps();
        });

        DB::table('transaction_types')->insert([
            [
                'name' => 'Barang Masuk',
                'slug' => 'barang_masuk',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Barang Keluar',
                'slug' => 'barang_keluar',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Peminjaman',
                'slug' => 'peminjaman',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Pengembalian',
                'slug' => 'pengembalian',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Maintenance',
                'slug' => 'maintenance',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Selesai Maintenance',
                'slug' => 'selesai_maintenance',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transaction_types');
    }
};
