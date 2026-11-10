<?php

namespace Tests\Unit;

use Tests\TestCase;

class PayFastCheckoutAckScriptTest extends TestCase
{
    public function test_public_checkout_ack_script_exists_and_returns_ok_contract(): void
    {
        $path = base_path('public/payfast-checkout-ack.php');
        $this->assertFileExists($path);

        $source = file_get_contents($path);
        $this->assertIsString($source);
        $this->assertStringContainsString("echo 'OK'", $source);
        $this->assertStringContainsString('fastcgi_finish_request', $source);
        $this->assertStringContainsString('checkout_acks.jsonl', $source);
        // Hot path must stay DB-free for UAT latency.
        $this->assertStringNotContainsString('PDO', $source);
        $this->assertStringNotContainsString('mysql:', $source);
        $this->assertStringNotContainsString('activated_at', $source);
        $this->assertStringNotContainsString('payment_status', $source);
    }

    public function test_config_default_string_points_to_fast_ack(): void
    {
        $source = file_get_contents(base_path('config/payfast.php'));
        $this->assertIsString($source);
        $this->assertStringContainsString(
            "env('PAYFAST_IPN_PATH', '/payfast-checkout-ack.php')",
            $source
        );
    }
}
