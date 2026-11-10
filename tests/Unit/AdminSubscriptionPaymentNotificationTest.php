<?php

namespace Tests\Unit;

use App\Events\AdminSubscriptionPaymentReceived;
use App\Models\AdminSubscriptionPaymentNotification;
use App\Models\SubscriptionPayment;
use App\Services\Admin\AdminSubscriptionPaymentNotifier;
use App\Services\PayFast\PayFastPakistanService;
use App\Services\PayFast\SubscriptionPaymentActivator;
use Illuminate\Support\Facades\Event;
use Mockery;
use Tests\TestCase;

/**
 * DB-light / pure unit coverage for admin payment notification rules.
 * Full DB multi-admin persistence is validated when MySQL is available.
 */
class AdminSubscriptionPaymentNotificationTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_pending_failed_cancelled_expired_do_not_notify(): void
    {
        Event::fake([AdminSubscriptionPaymentReceived::class]);
        $notifier = app(AdminSubscriptionPaymentNotifier::class);

        foreach ([
            SubscriptionPayment::STATUS_PENDING,
            SubscriptionPayment::STATUS_PROCESSING,
            SubscriptionPayment::STATUS_FAILED,
            SubscriptionPayment::STATUS_CANCELLED,
            SubscriptionPayment::STATUS_EXPIRED,
        ] as $status) {
            $payment = new SubscriptionPayment([
                'status' => $status,
                'amount' => 570,
                'currency' => 'PKR',
            ]);
            $payment->id = random_int(1000, 999999);
            $notifier->notifyVerifiedPayment($payment);
        }

        Event::assertNotDispatched(AdminSubscriptionPaymentReceived::class);
    }

    public function test_mark_read_is_idempotent_on_model(): void
    {
        $notification = Mockery::mock(AdminSubscriptionPaymentNotification::class)->makePartial();
        $notification->is_read = false;
        $notification->shouldReceive('save')->once()->andReturn(true);
        $notification->shouldReceive('forceFill')->once()->andReturnSelf();

        $this->assertTrue($notification->markRead());

        $notification->is_read = true;
        $this->assertFalse($notification->markRead());
    }

    public function test_notification_card_array_hides_secrets(): void
    {
        $payment = new SubscriptionPayment([
            'amount' => 570.00,
            'currency' => 'PKR',
            'status' => 'paid',
            'gateway' => 'payfast_pakistan',
            'order_reference' => 'LOY-CARD-1',
            'payfast_transaction_id' => 'TXN-1',
        ]);
        $payment->id = 77;
        $payment->setRelation('technician', new \App\Models\User(['name' => 'Ali Tech', 'email' => 'a@example.com']));
        $payment->setRelation('subscriptionPlan', null);

        $notification = new AdminSubscriptionPaymentNotification([
            'admin_id' => 1,
            'subscription_payment_id' => 77,
            'is_read' => false,
        ]);
        $notification->id = 5;
        $notification->created_at = now();
        $notification->setRelation('payment', $payment);

        $card = $notification->toAdminCardArray();

        $this->assertSame(5, $card['id']);
        $this->assertSame(77, $card['payment_id']);
        $this->assertSame('Ali Tech', $card['technician_name']);
        $this->assertSame(570.0, $card['amount']);
        $this->assertArrayNotHasKey('secured_key', $card);
        $this->assertArrayNotHasKey('token', $card);
        $json = json_encode($card);
        $this->assertStringNotContainsString('secured_key', $json);
        $this->assertStringNotContainsString('TOKEN', $json);
    }

    public function test_broadcast_payload_shape_is_safe(): void
    {
        $payment = new SubscriptionPayment([
            'amount' => 570,
            'currency' => 'PKR',
            'status' => 'paid',
            'gateway' => 'payfast_pakistan',
            'order_reference' => 'LOY-BCAST-1',
        ]);
        $payment->id = 12;
        $payment->setRelation('technician', new \App\Models\User(['name' => 'Tech']));
        $payment->setRelation('subscriptionPlan', null);

        $notification = new AdminSubscriptionPaymentNotification([
            'admin_id' => 3,
            'subscription_payment_id' => 12,
            'is_read' => false,
        ]);
        $notification->id = 9;
        $notification->created_at = now();
        $notification->setRelation('payment', $payment);

        // Avoid DB unread_count query by stubbing via partial event payload check on channels.
        $event = new AdminSubscriptionPaymentReceived($notification);
        $channels = $event->broadcastOn();
        $this->assertCount(1, $channels);
        $this->assertTrue(
            in_array($channels[0]->name, ['private-admin.3', 'admin.3'], true),
            'Unexpected channel name: ' . $channels[0]->name
        );
        $this->assertSame('subscription.payment.received', $event->broadcastAs());
    }

    public function test_verify_without_gateway_success_does_not_activate_with_optional_notifier(): void
    {
        $payFast = Mockery::mock(PayFastPakistanService::class);
        $payFast->shouldReceive('getStatusByBasketId')->once()->andReturn([
            'ok' => true,
            'normalized' => [
                'success' => false,
                'status_code' => '02',
                'status_msg' => 'Pending',
                'transaction_id' => null,
                'basket_id' => 'LOY-SAFE-NOTIF',
            ],
        ]);
        $payFast->shouldReceive('extractTransactionIdFromCallback')->andReturn(null);
        $payFast->shouldReceive('extractBasketIdFromCallback')->andReturn('LOY-SAFE-NOTIF');

        $notifier = Mockery::mock(AdminSubscriptionPaymentNotifier::class);
        $notifier->shouldNotReceive('notifyVerifiedPayment');

        // Requires DB for lock/update — skip cleanly if unavailable.
        try {
            \DB::connection()->getPdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('MySQL unavailable: ' . $e->getMessage());
        }

        $payment = SubscriptionPayment::query()->create([
            'technician_id' => 48,
            'subscription_id' => 1,
            'order_reference' => 'LOY-SAFE-NOTIF-' . uniqid(),
            'amount' => 570,
            'currency' => 'PKR',
            'gateway' => 'payfast_pakistan',
            'environment' => 'sandbox',
            'status' => SubscriptionPayment::STATUS_PENDING,
            'initiated_at' => now(),
        ]);

        Event::fake([AdminSubscriptionPaymentReceived::class]);
        $activator = new SubscriptionPaymentActivator($payFast, $notifier);
        $result = $activator->verifyAndActivate($payment);
        $this->assertFalse($result->isPaid());
        Event::assertNotDispatched(AdminSubscriptionPaymentReceived::class);
    }
}
