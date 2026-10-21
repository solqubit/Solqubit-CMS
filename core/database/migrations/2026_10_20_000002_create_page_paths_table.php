<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Previous addresses of additional pages, so an old link redirects to the page's current address. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_paths', function (Blueprint $table): void {
            $table->string('path', 255)->primary();
            $table->foreignId('page_id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_paths');
    }
};
