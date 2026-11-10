<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Services\PayFast\PayFastPakistanService;
use App\Services\PayFast\SubscriptionPaymentActivator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PayFastPaymentController extends Controller
{
    public function __construct(
        private readonly PayFastPakistanService $payFast,
        private readonly SubscriptionPaymentActivator $activator
    ) {
    }

    /**
     * POST /api/payments/payfast/initiate
     */
    public function initiate(Request $request)
    {
        $user = $request->user();

        if (!$user || $user->user_type !== 'technician') {
            return response()->json([
                'success' => false,
                'message' => 'Only authenticated technicians can initiate subscription payments.',
            ], 403);
        }

        if (!$user->allDocumentsVerified()) {
            return response()->json([
                'success' => false,
                'message' => 'All documents must be verified by admin before purchasing a subscription plan.',
            ], 403);
        }

        $request->validate([
            'subscription_id' => 'required|exists:subscriptions,id',
        ]);

        $plan = Subscription::query()
            ->where('id', $request->subscription_id)
            ->where('is_active', true)
            ->first();

        if (!$plan) {
            return response()->json([
                'success' => false,
                'message' => 'Selected subscription plan is not active.',
            ], 404);
        }

        if ($user->hasUnexpiredPlan((int) $plan->id)) {
            $planName = $plan->name ?: $plan->plan_type_label;
            $expiry = $user->subscription_end
                ? \Illuminate\Support\Carbon::parse($user->subscription_end)->format('d M Y')
                : null;
            $message = 'You already have an active ' . $planName . ' plan'
                . ($expiry ? ' until ' . $expiry : '')
                . '. You cannot purchase this plan again until it expires. You can upgrade to a different plan.';

            return response()->json([
                'success' => false,
                'message' => $message,
                'toast_message' => $message,
                'code' => 'plan_already_active',
                'can_upgrade' => true,
            ], 409);
        }

        try {
            $this->payFast->assertSandboxOnly();
            $amount = (float) $plan->resolvedPayablePrice();
            if ($amount < 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid plan payable amount.',
                ], 422);
            }

            $forceNew = $request->boolean('force_new');
            // PayFast access tokens / hosted sessions expire quickly; do not reuse stale checkouts.
            $reuseWindowMinutes = (int) config('payfast.pending_reuse_minutes', 10);

            // 1) Reserve/create basket FIRST so the access token can be bound to BASKET_ID + TXNAMT.
            $payment = DB::transaction(function () use ($user, $plan, $amount, $forceNew, $reuseWindowMinutes) {
                $existing = SubscriptionPayment::query()
                    ->where('technician_id', $user->id)
                    ->where('subscription_id', $plan->id)
                    ->whereIn('status', [
                        SubscriptionPayment::STATUS_PENDING,
                        SubscriptionPayment::STATUS_PROCESSING,
                    ])
                    ->whereNotNull('order_reference')
                    ->where('order_reference', '!=', '')
                    ->where('created_at', '>=', now()->subHours(6))
                    ->lockForUpdate()
                    ->latest('id')
                    ->first();

                if ($existing) {
                    $tooOld = $existing->created_at
                        && $existing->created_at->lt(now()->subMinutes(max(1, $reuseWindowMinutes)));
                    if ($forceNew || $tooOld) {
                        $existing->status = SubscriptionPayment::STATUS_EXPIRED;
                        $existing->failure_reason = $forceNew
                            ? 'Superseded by a fresh checkout initiation.'
                            : 'Checkout session expired; a new basket was required.';
                        $existing->save();
                        $existing = null;
                    }
                }

                // Intent only — never activate or grant expiry until gateway-verified payment.
                $userUpdate = [
                    'subscription_id' => $plan->id,
                    'subscription' => 'pending',
                    'payment_status' => 'pending',
                    'subscription_end' => null,
                ];
                if (Schema::hasColumn('users', 'payment_method')) {
                    $userUpdate['payment_method'] = 'payfast';
                }
                $user->update($userUpdate);

                $successUrl = $this->payFast->publicCallbackUrl((string) config('payfast.success_path'));
                $failureUrl = $this->payFast->publicCallbackUrl((string) config('payfast.failure_path'));
                $checkoutNotifyUrl = $this->payFast->publicCallbackUrl((string) config('payfast.ipn_path'));

                if ($existing) {
                    $existing->amount = $amount;
                    $existing->currency = config('payfast.currency', 'PKR');
                    $existing->initiated_at = now();
                    $existing->provider_request = array_merge(
                        is_array($existing->provider_request) ? $existing->provider_request : [],
                        [
                            'checkout_url' => $this->payFast->checkoutUrl(),
                            'fields_meta' => [
                                'BASKET_ID' => $existing->order_reference,
                                'TXNAMT' => number_format($amount, 2, '.', ''),
                                'MERCHANT_ID' => $this->payFast->merchantId(),
                                'ORDER_DATE' => now()->format('Y-m-d H:i:s'),
                                'SUCCESS_URL' => $successUrl,
                                'FAILURE_URL' => $failureUrl,
                                'CHECKOUT_URL' => $checkoutNotifyUrl,
                            ],
                        ]
                    );
                    $existing->save();

                    return $existing;
                }

                $orderReference = $this->nextOrderReference();

                return SubscriptionPayment::create([
                    'technician_id' => $user->id,
                    'subscription_id' => $plan->id,
                    'order_reference' => $orderReference,
                    'amount' => $amount,
                    'currency' => config('payfast.currency', 'PKR'),
                    'gateway' => 'payfast_pakistan',
                    'environment' => $this->payFast->mode(),
                    'status' => SubscriptionPayment::STATUS_PENDING,
                    'provider_request' => [
                        'checkout_url' => $this->payFast->checkoutUrl(),
                        'fields_meta' => [
                            'BASKET_ID' => $orderReference,
                            'TXNAMT' => number_format($amount, 2, '.', ''),
                            'MERCHANT_ID' => $this->payFast->merchantId(),
                            'ORDER_DATE' => now()->format('Y-m-d H:i:s'),
                            'SUCCESS_URL' => $successUrl,
                            'FAILURE_URL' => $failureUrl,
                            'CHECKOUT_URL' => $checkoutNotifyUrl,
                        ],
                    ],
                    'provider_response' => [],
                    'initiated_at' => now(),
                    'subscription_end' => null,
                ]);
            });

            // 2) Request token bound to this basket + amount (hosted checkout contract).
            $tokenData = $this->payFast->getAccessToken($request->ip(), [
                'basket_id' => (string) $payment->order_reference,
                'amount' => (float) $payment->amount,
                'currency' => (string) $payment->currency,
            ]);

            $payment->provider_response = array_merge(
                is_array($payment->provider_response) ? $payment->provider_response : [],
                [
                    'token_meta' => [
                        'expiry' => $tokenData['expiry'] ?? null,
                        'issued_at' => now()->toIso8601String(),
                        'basket_bound' => true,
                        'correlation_id' => 'pf-' . $payment->id . '-' . substr(sha1((string) $payment->order_reference), 0, 10),
                    ],
                ]
            );
            $payment->save();

            return $this->initiateSuccessResponse($payment, $plan, $tokenData, $user);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Unable to initiate PayFast payment: ' . $e->getMessage(),
                'toast_message' => 'Payment initiation failed. Please try again.',
            ], 502);
        }
    }

    private function initiateSuccessResponse(
        SubscriptionPayment $payment,
        Subscription $plan,
        array $tokenData,
        $user
    ) {
        $fields = $this->payFast->buildCheckoutFields([
            'amount' => (float) $payment->amount,
            'basket_id' => $payment->order_reference,
            'access_token' => $tokenData['token'],
            'customer_mobile' => $this->normalizeMobile((string) $user->phone),
            'customer_email' => (string) $user->email,
            'description' => 'LoyLabor subscription: ' . ($plan->name ?: $plan->plan_type_label),
            'order_date' => optional($payment->initiated_at)->format('Y-m-d H:i:s') ?: now()->format('Y-m-d H:i:s'),
        ]);

        $hostedCheckoutPage = $this->publicApiUrl(
            '/api/payments/payfast/checkout/' . rawurlencode((string) $payment->order_reference)
        );

        return response()->json([
            'success' => true,
            'message' => 'PayFast sandbox checkout ready. Complete payment to activate your subscription.',
            'toast_message' => 'Redirecting to secure PayFast checkout.',
            'data' => [
                'payment_id' => $payment->id,
                'order_reference' => $payment->order_reference,
                'subscription_id' => $plan->id,
                'plan_name' => $plan->name ?: $plan->plan_type_label,
                'amount' => (float) $payment->amount,
                'currency' => $payment->currency,
                'status' => $payment->status,
                'environment' => $payment->environment,
                'checkout' => [
                    'type' => 'form_post',
                    'method' => 'POST',
                    'url' => $this->payFast->checkoutUrl(),
                    'fields' => $fields,
                    'webview_url' => $hostedCheckoutPage . '?token=' . urlencode($tokenData['token']),
                ],
                'status_url' => $this->publicApiUrl('/api/payments/' . $payment->id),
            ],
        ]);
    }

    /**
     * GET /api/payments/payfast/checkout/{orderReference}
     * Auto-submits hosted checkout form (WebView friendly).
     * Requires temporary token query param issued by initiate().
     */
    public function checkoutPage(Request $request, string $orderReference)
    {
        // Decode in case clients double-encode the reference path segment.
        $orderReference = rawurldecode($orderReference);

        $payment = SubscriptionPayment::query()
            ->where('order_reference', $orderReference)
            ->first();

        if (!$payment) {
            return response()->view('payments.payfast-message', [
                'title' => 'Payment not found',
                'message' => 'Checkout payment record not found for this order reference. Please re-initiate payment from the app.',
                'icon' => '!',
                'icon_class' => 'fail',
                'logo_url' => $this->logoUrl(),
                'deep_link' => config('payfast.app_failure_deep_link'),
            ], 404);
        }

        if ($payment->isFinal() && !$payment->isPaid()) {
            return response()->view('payments.payfast-message', [
                'title' => 'Checkout unavailable',
                'message' => 'This payment is no longer payable (status: ' . $payment->status . '). Please re-initiate from the app.',
                'icon' => '!',
                'icon_class' => 'fail',
                'logo_url' => $this->logoUrl(),
                'deep_link' => config('payfast.app_failure_deep_link'),
            ], 410);
        }

        $token = (string) $request->query('token', '');
        if ($token === '') {
            return response()->view('payments.payfast-message', [
                'title' => 'Missing checkout token',
                'message' => 'Please re-initiate payment from the LoyLabor app to open a fresh secure session.',
                'icon' => '!',
                'icon_class' => 'pending',
                'logo_url' => $this->logoUrl(),
                'deep_link' => config('payfast.app_failure_deep_link'),
            ], 400);
        }

        $payment->loadMissing(['technician', 'subscriptionPlan']);
        $plan = $payment->subscriptionPlan;
        $planArray = $plan ? $plan->toApiArray() : [];

        $fields = $this->payFast->buildCheckoutFields([
            'amount' => (float) $payment->amount,
            'basket_id' => $payment->order_reference,
            'access_token' => $token,
            'customer_mobile' => $this->normalizeMobile((string) optional($payment->technician)->phone),
            'customer_email' => (string) optional($payment->technician)->email,
            'description' => 'LoyLabor subscription: ' . ($planArray['plan_name'] ?? 'Subscription'),
            'order_date' => optional($payment->initiated_at)->format('Y-m-d H:i:s') ?: now()->format('Y-m-d H:i:s'),
        ]);

        return response()
            ->view('payments.payfast-checkout', [
                'action' => $this->payFast->checkoutUrl(),
                'fields' => $fields,
                'logo_url' => $this->logoUrl(),
                'back_url' => config('payfast.app_failure_deep_link'),
                'order_reference' => $payment->order_reference,
                'amount' => (float) $payment->amount,
                'plan_name' => $planArray['plan_name'] ?? ($planArray['name'] ?? 'Subscription Plan'),
                'duration_label' => $planArray['duration_label'] ?? null,
                'original_price' => (float) ($planArray['original_price'] ?? $payment->amount),
                'discount_percentage' => (float) ($planArray['discount_percentage'] ?? 0),
                'discount_amount' => (float) ($planArray['discount_amount'] ?? 0),
            ]);
    }

    /**
     * GET/POST /api/payments/payfast/return/success
     */
    public function returnSuccess(Request $request)
    {
        return $this->handleReturn($request, true);
    }

    /**
     * GET/POST /api/payments/payfast/return/failure
     */
    public function returnFailure(Request $request)
    {
        return $this->handleReturn($request, false);
    }

    /**
     * POST/GET /api/payments/payfast/return/callback (preferred CHECKOUT_URL)
     * Legacy aliases: /notify, /ipn (may be WAF-blocked on some hosts)
     *
     * PayFast server waits on CHECKOUT_URL. Respond in milliseconds.
     * Do NOT call gateway status APIs here — app POST /verify is authoritative.
     */
    public function ipn(Request $request)
    {
        // PayFast UAT waits on CHECKOUT_URL before finishing Demo Bank.
        // Keep this handler allocation-light and always return HTTP 200 quickly.
        $payload = array_merge($request->query(), $request->request->all());
        $basketId = $this->payFast->extractBasketIdFromCallback($payload);

        if (!$basketId) {
            return response('OK', 200)->header('Content-Type', 'text/plain; charset=UTF-8');
        }

        try {
            $payment = SubscriptionPayment::query()
                ->where('order_reference', $basketId)
                ->first();

            if ($payment && !$payment->isPaid()) {
                $payment->callback_payload = array_merge(
                    is_array($payment->callback_payload) ? $payment->callback_payload : [],
                    [
                        'ipn' => [
                            'payload' => $payload,
                            'at' => now()->toIso8601String(),
                        ],
                    ]
                );
                if (!$payment->isFinal()) {
                    $payment->status = SubscriptionPayment::STATUS_PROCESSING;
                }
                $payment->save();
            }
        } catch (\Throwable $e) {
            // Still ACK — never let merchant-side exceptions surface as gateway "No response".
            \Log::warning('PayFast CHECKOUT_URL handler error', [
                'basket' => $basketId,
                'error' => $e->getMessage(),
            ]);
        }

        return response('OK', 200)->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    /**
     * GET /api/payments/{payment}
     * Auth required — owner technician only.
     */
    public function show(Request $request, $paymentId)
    {
        $user = $request->user();
        $payment = SubscriptionPayment::query()
            ->with('subscriptionPlan')
            ->find($paymentId);

        if (!$payment || (int) $payment->technician_id !== (int) $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Payment not found.',
            ], 404);
        }

        // Read-only status. Never treat a GET as proof of payment success.
        return response()->json([
            'success' => true,
            'message' => 'Payment status fetched.',
            'data' => $payment->fresh(['subscriptionPlan'])->toApiArray(),
        ]);
    }

    /**
     * POST /api/payments/{payment}/verify
     * Manual poll after WebView returns to app.
     */
    public function verify(Request $request, $paymentId)
    {
        $user = $request->user();
        $payment = SubscriptionPayment::query()->find($paymentId);

        if (!$payment || (int) $payment->technician_id !== (int) $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Payment not found.',
            ], 404);
        }

        try {
            $payment = $this->activator->verifyAndActivate($payment, $request->all());
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Unable to verify payment right now. Please try again shortly.',
                'data' => $payment->fresh(['subscriptionPlan'])->toApiArray(),
            ], 502);
        }

        $message = match ($payment->status) {
            SubscriptionPayment::STATUS_PAID => 'Payment verified. Your subscription is now active.',
            SubscriptionPayment::STATUS_FAILED => 'Payment failed. Please try again with another method.',
            SubscriptionPayment::STATUS_CANCELLED => 'Payment was cancelled.',
            default => 'Payment is still pending confirmation from PayFast.',
        };

        return response()->json([
            'success' => $payment->isPaid(),
            'message' => $message,
            'toast_message' => $message,
            'data' => $payment->fresh(['subscriptionPlan'])->toApiArray(),
        ]);
    }

    private function handleReturn(Request $request, bool $fromSuccessUrl)
    {
        $payload = array_merge($request->query(), $request->request->all());
        $basketId = $this->payFast->extractBasketIdFromCallback($payload);

        $payment = $basketId
            ? SubscriptionPayment::query()->where('order_reference', $basketId)->first()
            : null;

        /**
         * CRITICAL: Respond immediately to PayFast SUCCESS/FAILURE redirects.
         * Do NOT call gateway status APIs here — slow merchant pages cause
         * PayFast UAT "No response from server" after Make Payment.
         * Authoritative activation happens via IPN and/or POST /verify.
         */
        if ($payment && !$payment->isPaid()) {
            $callbackStore = array_merge(
                is_array($payment->callback_payload) ? $payment->callback_payload : [],
                [
                    'browser_return' => [
                        'from_success_url' => $fromSuccessUrl,
                        'payload' => $payload,
                        'at' => now()->toIso8601String(),
                    ],
                ]
            );

            // Browser redirects are signals only — never proof of paid/failed.
            // Do not hard-cancel solely on FAILURE_URL: PayFast UAT can send users
            // there after merchant CHECKOUT_URL timeouts ("No response from server")
            // even when no terminal gateway result exists yet. Authoritative outcome
            // comes from gateway status via POST /verify (or later reconciliation).
            if (!$payment->isFinal()) {
                $payment->status = SubscriptionPayment::STATUS_PROCESSING;
                $payment->failure_reason = $fromSuccessUrl
                    ? null
                    : 'Browser returned via PayFast failure/cancel URL; awaiting gateway verification.';
            }
            $payment->callback_payload = $callbackStore;
            $payment->save();
        }

        $deepLink = $fromSuccessUrl
            ? config('payfast.app_success_deep_link')
            : config('payfast.app_failure_deep_link');

        if ($payment) {
            $payment->loadMissing('subscriptionPlan');
            $deepLink .= (str_contains($deepLink, '?') ? '&' : '?')
                . http_build_query([
                    'payment_id' => $payment->id,
                    'order_reference' => $payment->order_reference,
                    'status' => $payment->status,
                ]);
        }

        $cancelled = !$fromSuccessUrl && $payment && $payment->status === SubscriptionPayment::STATUS_CANCELLED;
        $pending = $fromSuccessUrl && $payment && !$payment->isFinal();
        // Browser return alone never proves paid — success UI only if already verified paid.
        $success = (bool) ($payment && $payment->isPaid());

        $planName = null;
        if ($payment && $payment->subscriptionPlan) {
            $planName = $payment->subscriptionPlan->name ?: $payment->subscriptionPlan->plan_type_label;
        }

        // Server-to-server style probes / non-browser clients get a tiny ACK first-byte.
        $accept = strtolower((string) $request->header('Accept', ''));
        $wantsHtml = str_contains($accept, 'text/html')
            || $request->headers->get('Sec-Fetch-Mode') === 'navigate'
            || $request->headers->get('Sec-Fetch-Dest') === 'document';
        if (!$wantsHtml && ($accept === '' || str_contains($accept, 'application/json') || str_contains($accept, '*/*'))) {
            if (!$request->headers->has('Sec-Fetch-Mode') && !$request->headers->has('Upgrade-Insecure-Requests')) {
                return response('OK', 200)->header('Content-Type', 'text/plain; charset=UTF-8');
            }
        }

        return response()->view('payments.payfast-return', [
            'success' => $success,
            'pending' => $pending && !$success,
            'cancelled' => $cancelled,
            'payment' => $payment,
            'plan_name' => $planName,
            'logo_url' => $this->logoUrl(),
            'deep_link' => $deepLink,
            'plans_deep_link' => config('payfast.app_failure_deep_link'),
            'auto_redirect' => true,
            'redirect_ms' => 1600,
            'message' => $success
                ? 'Your payment is confirmed. Your subscription will appear as active in the LoyLabor app.'
                : ($pending
                    ? 'Payment submitted to PayFast. LoyLabor is confirming with the gateway — please return to the app.'
                    : ($cancelled
                        ? 'You cancelled the checkout. No charge was completed for this attempt.'
                        : 'Payment was not completed. You can retry securely from the LoyLabor app.')),
        ]);
    }

    private function logoUrl(): string
    {
        return $this->publicApiUrl('/website/images/logo.webp');
    }

    private function nextOrderReference(): string
    {
        do {
            $ref = 'LOY-' . strtoupper(Str::random(4)) . '-' . now()->format('ymdHis') . random_int(10, 99);
        } while (SubscriptionPayment::query()->where('order_reference', $ref)->exists());

        return $ref;
    }

    /**
     * Build a public HTTPS API URL for mobile WebView / status polling.
     * Avoids broken localhost APP_URL on shared hosting.
     */
    private function publicApiUrl(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        $candidates = [
            (string) config('payfast.public_base_url'),
            (string) config('app.url'),
        ];

        $base = '';
        foreach ($candidates as $candidate) {
            $candidate = rtrim(trim($candidate), '/');
            if ($candidate === '') {
                continue;
            }
            if (str_contains(strtolower($candidate), 'localhost') || str_contains($candidate, '127.0.0.1')) {
                continue;
            }
            $base = $candidate;
            break;
        }

        if ($base === '') {
            $req = request();
            $base = rtrim($req->getSchemeAndHttpHost() . $req->getBasePath(), '/');
            // Hostinger deploy serves API under /homeservices when base path is empty.
            if (!str_contains($base, '/homeservices') && str_contains($req->path(), 'homeservices')) {
                $base .= '/homeservices';
            }
        }

        return $base . $path;
    }

    private function normalizeMobile(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?: '';
        if (str_starts_with($digits, '92') && strlen($digits) >= 11) {
            return $digits;
        }
        if (str_starts_with($digits, '0') && strlen($digits) >= 10) {
            return '92' . substr($digits, 1);
        }

        return $digits !== '' ? $digits : '920000000000';
    }
}
