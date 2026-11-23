<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connected_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 40);
            $table->string('provider_account_id', 191);
            $table->string('account_type', 40)->default('member');
            $table->string('display_name')->nullable();
            $table->string('email')->nullable();
            $table->text('picture_url')->nullable();
            $table->longText('access_token');
            $table->longText('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->json('scopes')->nullable();
            $table->string('status', 30)->default('connected');
            $table->json('metadata')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'provider'], 'connected_accounts_owner_provider_unique');
            $table->index(['user_id', 'provider', 'status'], 'connected_accounts_owner_provider_status_index');
        });

        Schema::create('linkedin_oauth_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('state_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('linkedin_oauth_states');
        Schema::dropIfExists('connected_accounts');
    }
};
