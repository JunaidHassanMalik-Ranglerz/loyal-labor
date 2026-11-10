<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotent: adds users.payment_method only when missing.
 * Required by technician subscription flows (manual + PayFast) that record
 * how the technician is paying / paid. Does not alter existing rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('users')) {
            return;
        }

        if (!Schema::hasColumn('users', 'payment_method')) {
            Schema::table('users', function (Blueprint $table) {
                // Matches live phpMyAdmin baseline: VARCHAR(50) NULL.
                if (Schema::hasColumn('users', 'payment_status')) {
                    $table->string('payment_method', 50)->nullable()->after('payment_status');
                } else {
                    $table->string('payment_method', 50)->nullable();
                }
            });
        }

        // Keep plan_type available for subscription API contract when the older
        // combined migration has not been applied yet.
        if (Schema::hasTable('subscriptions') && !Schema::hasColumn('subscriptions', 'plan_type')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                if (Schema::hasColumn('subscriptions', 'name')) {
                    $table->string('plan_type', 50)->default('basic_plan')->after('name');
                } else {
                    $table->string('plan_type', 50)->default('basic_plan');
                }
            });
        }
    }

    public function down(): void
    {
        // Non-destructive rollback intentionally omitted for shared production safety.
        // Use a dedicated approved rollback if column removal is required.
    }
};
