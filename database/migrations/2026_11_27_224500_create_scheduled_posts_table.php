<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connected_account_id')
                ->nullable()
                ->constrained('connected_accounts')
                ->nullOnDelete();
            $table->string('provider', 40)->default('linkedin');
            $table->text('content');
            $table->string('status', 30)->default('draft'); // draft, scheduled, publishing, published, failed, cancelled
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->string('provider_post_id', 191)->nullable();
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'provider', 'status'], 'scheduled_posts_user_provider_status_idx');
            $table->index(['status', 'scheduled_for'], 'scheduled_posts_status_scheduled_for_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_posts');
    }
};
