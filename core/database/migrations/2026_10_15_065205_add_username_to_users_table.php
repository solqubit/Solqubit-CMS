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
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 40)->nullable()->unique();
        });
        DB::table('users')->select('id')->orderBy('id')->chunkById(100, function ($users): void {
            foreach ($users as $user) {
                DB::table('users')->where('id', $user->id)->update(['username' => 'usuario-'.$user->id]);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('username');
        });
    }
};
