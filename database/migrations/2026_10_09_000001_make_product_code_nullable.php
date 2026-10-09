<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', static function (Blueprint $table): void {
            $table->string('code', 80)->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('products')->whereNull('code')->exists()) {
            throw new RuntimeException('Assign codes to products without an internal code before rolling back this migration.');
        }

        Schema::table('products', static function (Blueprint $table): void {
            $table->string('code', 80)->nullable(false)->change();
        });
    }
};
