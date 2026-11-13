<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The spend ledger (one row per day, provider and model) and the single-row pause state.
 *
 * Skips tables that already exist, so the auto-loaded and a published copy can both run.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('ai-captioning.database.connection');
    }

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());
        $spendTable = (string) config('ai-captioning.database.spend_table', 'ai_captioning_spend');
        $stateTable = (string) config('ai-captioning.database.state_table', 'ai_captioning_state');

        if (! $schema->hasTable($spendTable)) {
            $schema->create($spendTable, function (Blueprint $table): void {
                $table->id();
                $table->date('date');
                $table->string('provider', 64)->default('');
                $table->string('model', 128)->default('');
                $table->unsignedInteger('calls')->default(0);
                $table->unsignedInteger('fallback_calls')->default(0);
                $table->unsignedBigInteger('input_tokens')->default(0);
                $table->unsignedBigInteger('output_tokens')->default(0);
                $table->decimal('estimated_usd', 14, 6)->default(0);
                $table->timestamps();

                $table->unique(['date', 'provider', 'model']);
            });
        }

        if (! $schema->hasTable($stateTable)) {
            $schema->create($stateTable, function (Blueprint $table): void {
                $table->id();
                $table->timestamp('paused_at')->nullable();
                $table->string('pause_kind', 16)->nullable();
                $table->text('pause_reason')->nullable();
                $table->string('paused_period', 16)->nullable();
                $table->string('warned_period', 16)->nullable();
                $table->string('exceeded_period', 16)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());

        $schema->dropIfExists((string) config('ai-captioning.database.spend_table', 'ai_captioning_spend'));
        $schema->dropIfExists((string) config('ai-captioning.database.state_table', 'ai_captioning_state'));
    }
};
