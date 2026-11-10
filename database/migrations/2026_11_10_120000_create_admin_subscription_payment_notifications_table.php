<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('admin_subscription_payment_notifications')) {
            return;
        }

        Schema::create('admin_subscription_payment_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('admin_id');
            $table->unsignedBigInteger('subscription_payment_id');
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->foreign('admin_id', 'aspn_admin_fk')
                ->references('id')->on('admins')->cascadeOnDelete();
            $table->foreign('subscription_payment_id', 'aspn_payment_fk')
                ->references('id')->on('subscription_payments')->cascadeOnDelete();

            $table->unique(
                ['admin_id', 'subscription_payment_id'],
                'admin_sub_pay_notif_unique'
            );
            $table->index(['admin_id', 'is_read', 'created_at'], 'admin_sub_pay_notif_unread_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_subscription_payment_notifications');
    }
};
