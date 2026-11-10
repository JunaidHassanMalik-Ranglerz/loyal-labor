<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminSubscriptionPaymentNotification;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\User;
use Illuminate\Http\Request;
use Yajra\DataTables\Facades\DataTables;

class SubscriptionPaymentController extends Controller
{
    public function index(Request $request)
    {
        checkAdminHasPermissionAndThrowException('subscriptions.view');

        if ($request->ajax() || $request->has('draw') || $request->has('start')) {
            $adminId = (int) auth('admin')->id();

            $query = SubscriptionPayment::query()
                ->with(['technician:id,name,email', 'subscriptionPlan'])
                ->latest('id');

            if ($request->filled('status')) {
                $query->where('status', $request->string('status')->toString());
            }
            if ($request->filled('gateway')) {
                $query->where('gateway', 'like', '%' . $request->string('gateway')->toString() . '%');
            }
            if ($request->filled('subscription_id')) {
                $query->where('subscription_id', (int) $request->input('subscription_id'));
            }
            if ($request->filled('technician_id')) {
                $query->where('technician_id', (int) $request->input('technician_id'));
            }
            if ($request->filled('order_reference')) {
                $query->where('order_reference', 'like', '%' . $request->string('order_reference')->toString() . '%');
            }
            if ($request->filled('date_from')) {
                $query->whereDate('created_at', '>=', $request->date('date_from'));
            }
            if ($request->filled('date_to')) {
                $query->whereDate('created_at', '<=', $request->date('date_to'));
            }
            if ($request->filled('read_state') && in_array($request->input('read_state'), ['read', 'unread'], true)) {
                $wantRead = $request->input('read_state') === 'read';
                $query->whereHas('adminNotifications', function ($q) use ($adminId, $wantRead) {
                    $q->where('admin_id', $adminId)->where('is_read', $wantRead);
                });
            }

            return DataTables::of($query)
                ->addColumn('technician_name', function (SubscriptionPayment $payment) {
                    return e(optional($payment->technician)->name ?: optional($payment->technician)->email ?: '—');
                })
                ->addColumn('plan_name', function (SubscriptionPayment $payment) {
                    $plan = $payment->subscriptionPlan;

                    return e($plan ? ($plan->name ?: $plan->plan_type_label) : '—');
                })
                ->addColumn('original_amount', function (SubscriptionPayment $payment) {
                    $plan = $payment->subscriptionPlan;
                    $original = $plan ? (float) $plan->resolvedOriginalPrice() : (float) $payment->amount;

                    return 'PKR ' . number_format($original, 2);
                })
                ->addColumn('discount_display', function (SubscriptionPayment $payment) {
                    $plan = $payment->subscriptionPlan;
                    if (!$plan) {
                        return '—';
                    }
                    $pct = (float) $plan->resolvedDiscountPercentage();
                    $amount = max(0, (float) $plan->resolvedOriginalPrice() - (float) $payment->amount);

                    return rtrim(rtrim(number_format($pct, 2), '0'), '.') . '% (− PKR ' . number_format($amount, 2) . ')';
                })
                ->addColumn('payable_amount', function (SubscriptionPayment $payment) {
                    return e(($payment->currency ?: 'PKR') . ' ' . number_format((float) $payment->amount, 2));
                })
                ->addColumn('status_badge', function (SubscriptionPayment $payment) {
                    $map = [
                        'paid' => 'success',
                        'pending' => 'warning',
                        'processing' => 'info',
                        'failed' => 'danger',
                        'cancelled' => 'secondary',
                        'expired' => 'dark',
                    ];
                    $class = $map[$payment->status] ?? 'secondary';

                    return '<span class="badge badge-' . $class . '">' . e(ucfirst($payment->status)) . '</span>';
                })
                ->addColumn('payment_date', function (SubscriptionPayment $payment) {
                    $at = $payment->verified_at ?: $payment->initiated_at ?: $payment->created_at;

                    return $at ? $at->format('d M Y H:i') : '—';
                })
                ->addColumn('action', function (SubscriptionPayment $payment) {
                    return '<a class="btn btn-info btn-sm" href="' . route('admin.subscription-payments.show', $payment->id) . '"><i class="fa fa-eye"></i></a>';
                })
                ->rawColumns(['status_badge', 'action'])
                ->make(true);
        }

        return view('admin.subscription-payments.index', [
            'plans' => Subscription::query()->orderBy('name')->get(['id', 'name', 'plan_type']),
            'technicians' => User::query()
                ->where('user_type', 'technician')
                ->orderBy('name')
                ->limit(500)
                ->get(['id', 'name', 'email']),
            'statuses' => [
                SubscriptionPayment::STATUS_PENDING,
                SubscriptionPayment::STATUS_PROCESSING,
                SubscriptionPayment::STATUS_PAID,
                SubscriptionPayment::STATUS_FAILED,
                SubscriptionPayment::STATUS_CANCELLED,
                SubscriptionPayment::STATUS_EXPIRED,
            ],
        ]);
    }

    public function show(Request $request, $id)
    {
        checkAdminHasPermissionAndThrowException('subscriptions.view');

        $payment = SubscriptionPayment::query()
            ->with(['technician', 'subscriptionPlan'])
            ->findOrFail($id);

        $adminId = (int) auth('admin')->id();
        $notification = null;

        // Mark associated notification read only when opened via notification deep-link.
        if ($request->filled('notification_id')) {
            $notification = AdminSubscriptionPaymentNotification::query()
                ->where('admin_id', $adminId)
                ->where('id', (int) $request->input('notification_id'))
                ->where('subscription_payment_id', $payment->id)
                ->first();

            if ($notification) {
                $notification->markRead();
            }
        }

        $plan = $payment->subscriptionPlan;
        $original = $plan ? (float) $plan->resolvedOriginalPrice() : (float) $payment->amount;
        $discountPct = $plan ? (float) $plan->resolvedDiscountPercentage() : 0.0;
        $discountAmount = max(0, $original - (float) $payment->amount);

        return view('admin.subscription-payments.show', [
            'payment' => $payment,
            'plan' => $plan,
            'original' => $original,
            'discountPct' => $discountPct,
            'discountAmount' => $discountAmount,
            'notification' => $notification,
        ]);
    }
}
