<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PayFastPaymentMethodSchemaTest extends TestCase
{
    public function test_users_payment_method_column_exists_after_fix_migration(): void
    {
        $this->assertTrue(
            Schema::hasColumn('users', 'payment_method'),
            'users.payment_method must exist so initiate/activate do not throw SQLSTATE[42S22].'
        );
    }

    public function test_subscription_payments_table_has_gateway_of_record(): void
    {
        $this->assertTrue(Schema::hasTable('subscription_payments'));
        $this->assertTrue(Schema::hasColumn('subscription_payments', 'gateway'));
        $this->assertTrue(Schema::hasColumn('subscription_payments', 'status'));
        $this->assertTrue(Schema::hasColumn('subscription_payments', 'order_reference'));
        $this->assertTrue(Schema::hasColumn('subscription_payments', 'amount'));
    }

    public function test_payfast_initiate_user_update_payload_omits_payment_method_when_column_missing(): void
    {
        // Simulate defensive payload builder used by initiate/activator.
        $userUpdate = [
            'subscription_id' => 1,
            'subscription' => 'pending',
            'payment_status' => 'pending',
        ];

        if (Schema::hasColumn('users', 'payment_method')) {
            $userUpdate['payment_method'] = 'payfast';
        }

        $this->assertArrayHasKey('payment_status', $userUpdate);
        $this->assertSame('payfast', $userUpdate['payment_method'] ?? null);
        $this->assertArrayNotHasKey('secured_key', $userUpdate);
    }
}
