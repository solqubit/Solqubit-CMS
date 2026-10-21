<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('avatar_id')->nullable()->constrained('media')->nullOnDelete();
            $table->text('bio')->nullable();
            $table->text('app_authentication_secret')->nullable();
            $table->text('app_authentication_recovery_codes')->nullable();
        });
        Schema::table('posts', function (Blueprint $table): void {
            $table->boolean('show_updated_at')->default(false);
            $table->timestamp('content_updated_at')->nullable();
        });
        Schema::create('editor_drafts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('context', 10);
            $table->string('record_key', 30);
            $table->text('payload');
            $table->unsignedInteger('version')->default(1);
            $table->string('base_revision', 64)->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'context', 'record_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('editor_drafts');
        Schema::table('posts', fn (Blueprint $table) => $table->dropColumn(['show_updated_at', 'content_updated_at']));
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('avatar_id');
            $table->dropColumn(['bio', 'app_authentication_secret', 'app_authentication_recovery_codes']);
        });
    }
};
