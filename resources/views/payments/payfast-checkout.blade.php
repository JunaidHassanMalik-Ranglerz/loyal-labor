<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="color-scheme" content="light">
    <meta name="theme-color" content="#071A3D">
    <title>Secure Checkout — LoyLabor</title>
    @include('payments.partials.loyolabor-pay-styles')
</head>
<body>
<div class="ll-shell">
    <header class="ll-header">
        <a class="ll-back" href="{{ $back_url ?? 'loylabor://payfast?status=failure' }}" aria-label="Go back">←</a>
        <div class="ll-brand">
            @if(!empty($logo_url))
                <img class="ll-logo" src="{{ $logo_url }}" alt="LoyLabor">
            @endif
            <div class="ll-brand-text">
                <strong>LoyLabor</strong>
                <span>Encrypted payment session</span>
            </div>
        </div>
    </header>

    <section class="ll-hero">
        <div class="ll-badge">🔒 Secure Checkout</div>
        <h1>Confirm &amp; pay for your plan</h1>
        <p>You’ll continue to PayFast’s official payment page to complete this purchase safely.</p>
    </section>

    <section class="ll-card">
        <h2>Subscription summary</h2>
        <p class="ll-plan-name">{{ $plan_name ?? 'Subscription Plan' }}</p>
        <p class="ll-plan-meta">{{ $duration_label ?? 'Billing period as selected' }}</p>

        <div class="ll-row">
            <span class="label">Original price</span>
            <span class="value">PKR {{ number_format((float) ($original_price ?? $amount ?? 0), 2) }}</span>
        </div>
        @if(((float) ($discount_amount ?? 0)) > 0)
            <div class="ll-row">
                <span class="label">Discount @if(!empty($discount_percentage))({{ rtrim(rtrim(number_format((float) $discount_percentage, 2), '0'), '.') }}%)@endif</span>
                <span class="value">− PKR {{ number_format((float) $discount_amount, 2) }}</span>
            </div>
        @endif
        <div class="ll-row total">
            <span class="label">Total payable</span>
            <span class="value">PKR {{ number_format((float) ($amount ?? 0), 2) }}</span>
        </div>
    </section>

    <section class="ll-card">
        <h2>Pay with PayFast</h2>
        <div class="ll-note">
            <span>🛡️</span>
            <div>Bank, card, wallet, and Raast options are provided on the official PayFast checkout. LoyLabor never stores your card or bank credentials.</div>
        </div>
        <div class="ll-methods" aria-hidden="true">
            <div class="ll-method"><div class="ll-method-icon">B</div><div><strong>Bank Account</strong><span>Direct bank transfer via PayFast</span></div></div>
            <div class="ll-method"><div class="ll-method-icon">C</div><div><strong>Debit / Credit Card</strong><span>Processed by PayFast</span></div></div>
            <div class="ll-method"><div class="ll-method-icon">W</div><div><strong>Mobile Wallet</strong><span>Supported wallets on PayFast</span></div></div>
            <div class="ll-method"><div class="ll-method-icon">R</div><div><strong>Raast</strong><span>Instant account payments</span></div></div>
        </div>
    </section>

    <button type="button" class="ll-cta is-loading" id="pay-button" disabled>
        <span class="ll-spinner" aria-hidden="true"></span>
        <span id="pay-button-label">Pay Securely — PKR {{ number_format((float) ($amount ?? 0), 2) }}</span>
    </button>

    <p class="ll-foot">Order <strong>{{ $order_reference ?? '—' }}</strong> · Protected by PayFast</p>
</div>

<form id="payfast-form" method="POST" action="{{ $action }}" style="display:none">
    @foreach($fields as $name => $value)
        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
    @endforeach
</form>

<script>
(function () {
    var form = document.getElementById('payfast-form');
    var btn = document.getElementById('pay-button');
    var label = document.getElementById('pay-button-label');
    var submitted = false;

    function submitCheckout() {
        if (submitted || !form) return;
        submitted = true;
        btn.classList.add('is-loading');
        btn.disabled = true;
        label.textContent = 'Opening secure PayFast…';
        form.submit();
    }

    // Brief branded interstitial, then hand off to PayFast-hosted checkout.
    setTimeout(submitCheckout, 900);

    btn.addEventListener('click', function () {
        submitCheckout();
    });
})();
</script>
</body>
</html>
