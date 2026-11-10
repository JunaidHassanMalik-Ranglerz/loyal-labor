<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('subscription_payments')) {
            return;
        }

        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('technician_id')->index();
            $table->unsignedBigInteger('subscription_id')->index();
            $table->string('order_reference', 64)->unique();
            $table->string('payfast_transaction_id', 128)->nullable()->index();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 8)->default('PKR');
            $table->string('gateway', 32)->default('payfast_pakistan');
            $table->string('environment', 16)->default('sandbox');
            $table->string('status', 32)->default('pending')->index();
            // pending | processing | paid | failed | cancelled | expired
            $table->string('failure_reason', 500)->nullable();
            $table->json('provider_request')->nullable();
            $table->json('provider_response')->nullable();
            $table->json('callback_payload')->nullable();
            $table->timestamp('initiated_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->string('subscription_end', 32)->nullable();
            $table->timestamps();

            $table->unique(['technician_id', 'order_reference'], 'sub_pay_tech_order_uq');
            $table->index(['technician_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_payments');
    }
};
