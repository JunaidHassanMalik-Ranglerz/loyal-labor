<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#071A3D">
    <title>
        @if(!empty($success)) Payment Successful
        @elseif(!empty($pending)) Payment Pending
        @elseif(!empty($cancelled)) Payment Cancelled
        @else Payment Failed
        @endif
        — LoyLabor
    </title>
    @include('payments.partials.loyolabor-pay-styles')
</head>
<body>
@php
    $state = !empty($success) ? 'ok' : (!empty($pending) ? 'pending' : (!empty($cancelled) ? 'fail' : 'fail'));
    $icon = !empty($success) ? '✓' : (!empty($pending) ? '…' : (!empty($cancelled) ? '!' : '✕'));
    $title = !empty($success)
        ? 'Payment Successful'
        : (!empty($pending)
            ? 'Verifying your payment'
            : (!empty($cancelled) ? 'Payment Cancelled' : 'Payment Failed'));
@endphp
<div class="ll-shell">
    <header class="ll-header">
        <div class="ll-brand">
            @if(!empty($logo_url))
                <img class="ll-logo" src="{{ $logo_url }}" alt="LoyLabor">
            @endif
            <div class="ll-brand-text">
                <strong>LoyLabor</strong>
                <span>Payment status</span>
            </div>
        </div>
    </header>

    <section class="ll-card ll-status">
        <div class="ll-status-icon {{ !empty($pending) ? 'process' : $state }}">{{ $icon }}</div>
        <h1>{{ $title }}</h1>
        <p>{{ $message }}</p>

        @if(!empty($payment))
            <div class="ll-meta">
                <div class="ll-row" style="border:0;padding-top:0">
                    <span class="label">Plan</span>
                    <span class="value">{{ $plan_name ?? 'Subscription' }}</span>
                </div>
                <div class="ll-row">
                    <span class="label">Amount</span>
                    <span class="value">PKR {{ number_format((float) ($payment->amount ?? 0), 2) }}</span>
                </div>
                <div class="ll-row">
                    <span class="label">Order</span>
                    <span class="value">{{ $payment->order_reference }}</span>
                </div>
                <div class="ll-row">
                    <span class="label">Status</span>
                    <span class="value">{{ strtoupper((string) $payment->status) }}</span>
                </div>
                @if(!empty($payment->payfast_transaction_id))
                    <div class="ll-row">
                        <span class="label">Reference</span>
                        <span class="value">{{ $payment->payfast_transaction_id }}</span>
                    </div>
                @endif
            </div>
        @endif
    </section>

    <a class="ll-cta" href="{{ $deep_link }}">
        @if(!empty($success))
            Return to LoyLabor
        @elseif(!empty($pending))
            Open App &amp; Confirm Status
        @elseif(!empty($cancelled))
            Back to Subscription Plans
        @else
            Retry in LoyLabor
        @endif
    </a>

    @if(empty($success) && empty($pending))
        <a class="ll-cta-secondary" href="{{ $plans_deep_link ?? ($deep_link ?? 'loylabor://payfast?status=failure') }}">Back to Plans</a>
    @endif

    <p class="ll-foot">Activation happens only after LoyLabor verifies payment with PayFast.</p>
</div>

@if(!empty($auto_redirect))
<script>
    setTimeout(function () {
        window.location.href = @json($deep_link);
    }, {{ (int) ($redirect_ms ?? 1400) }});
</script>
@endif
</body>
</html>
