<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#071A3D">
    <title>{{ $title ?? 'Checkout' }} — LoyLabor</title>
    @include('payments.partials.loyolabor-pay-styles')
</head>
<body>
<div class="ll-shell">
    <header class="ll-header">
        <div class="ll-brand">
            @if(!empty($logo_url))
                <img class="ll-logo" src="{{ $logo_url }}" alt="LoyLabor">
            @endif
            <div class="ll-brand-text">
                <strong>LoyLabor</strong>
                <span>Secure checkout</span>
            </div>
        </div>
    </header>

    <section class="ll-card ll-status">
        <div class="ll-status-icon {{ $icon_class ?? 'fail' }}">{{ $icon ?? '!' }}</div>
        <h1>{{ $title ?? 'Unable to continue' }}</h1>
        <p>{{ $message }}</p>
    </section>

    <a class="ll-cta" href="{{ $deep_link ?? 'loylabor://payfast?status=failure' }}">Return to LoyLabor</a>
    <p class="ll-foot">Please re-initiate payment from the app if needed.</p>
</div>
</body>
</html>
