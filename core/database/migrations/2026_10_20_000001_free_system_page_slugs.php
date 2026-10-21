<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Inicio, Series and Sobre mí answer on their own routes, so their stored slug is never used. Renaming it frees
 * "inicio", "series" and "sobre-mi" for additional pages.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['home', 'series', 'about'] as $kind) {
            DB::table('pages')->where('kind', $kind)->update(['slug' => 'sistema-'.$kind]);
        }
    }

    public function down(): void
    {
        foreach (['home' => 'inicio', 'series' => 'series', 'about' => 'sobre-mi'] as $kind => $slug) {
            DB::table('pages')->where('kind', $kind)->update(['slug' => $slug]);
        }
    }
};
