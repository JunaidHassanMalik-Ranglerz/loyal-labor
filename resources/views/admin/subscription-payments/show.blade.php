@extends('admin.master_layout')

@section('title')
    <title>{{ __('Payment') }} #{{ $payment->id }}</title>
@endsection

@section('admin-content')
<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>{{ __('Subscription Payment') }} #{{ $payment->id }}</h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item"><a href="{{ route('admin.subscription-payments.index') }}">{{ __('Payments') }}</a></div>
                <div class="breadcrumb-item active">#{{ $payment->id }}</div>
            </div>
        </div>

        <div class="section-body">
            <div class="row">
                <div class="col-lg-4">
                    <div class="card">
                        <div class="card-header"><h4>{{ __('Technician') }}</h4></div>
                        <div class="card-body">
                            <p><strong>{{ __('ID') }}:</strong> {{ $payment->technician_id }}</p>
                            <p><strong>{{ __('Name') }}:</strong> {{ optional($payment->technician)->name ?: '—' }}</p>
                            <p><strong>{{ __('Email') }}:</strong> {{ optional($payment->technician)->email ?: '—' }}</p>
                            <p><strong>{{ __('Phone') }}:</strong> {{ optional($payment->technician)->phone ?: '—' }}</p>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4">
                    <div class="card">
                        <div class="card-header"><h4>{{ __('Subscription') }}</h4></div>
                        <div class="card-body">
                            <p><strong>{{ __('Plan') }}:</strong> {{ $plan ? ($plan->name ?: $plan->plan_type_label) : '—' }}</p>
                            <p><strong>{{ __('Duration') }}:</strong> {{ $plan?->duration_label ?: '—' }}</p>
                            <p><strong>{{ __('Original') }}:</strong> PKR {{ number_format($original, 2) }}</p>
                            <p><strong>{{ __('Discount') }}:</strong> {{ rtrim(rtrim(number_format($discountPct, 2), '0'), '.') }}% (− PKR {{ number_format($discountAmount, 2) }})</p>
                            <p><strong>{{ __('Payable') }}:</strong> {{ $payment->currency }} {{ number_format((float) $payment->amount, 2) }}</p>
                            <p><strong>{{ __('Activated') }}:</strong> {{ optional($payment->activated_at)->format('d M Y H:i') ?: '—' }}</p>
                            <p><strong>{{ __('Expires') }}:</strong> {{ $payment->subscription_end ?: '—' }}</p>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4">
                    <div class="card">
                        <div class="card-header"><h4>{{ __('Transaction') }}</h4></div>
                        <div class="card-body">
                            <p><strong>{{ __('Payment ID') }}:</strong> {{ $payment->id }}</p>
                            <p><strong>{{ __('Gateway') }}:</strong> {{ $payment->gateway ?: '—' }}</p>
                            <p><strong>{{ __('Environment') }}:</strong> {{ $payment->environment ?: '—' }}</p>
                            <p><strong>{{ __('Order reference') }}:</strong> {{ $payment->order_reference }}</p>
                            <p><strong>{{ __('Gateway txn ID') }}:</strong> {{ $payment->payfast_transaction_id ?: '—' }}</p>
                            <p><strong>{{ __('Status') }}:</strong>
                                <span class="badge badge-{{ $payment->status === 'paid' ? 'success' : 'secondary' }}">{{ ucfirst($payment->status) }}</span>
                            </p>
                            <p><strong>{{ __('Verified at') }}:</strong> {{ optional($payment->verified_at)->format('d M Y H:i') ?: '—' }}</p>
                            <p><strong>{{ __('Initiated at') }}:</strong> {{ optional($payment->initiated_at)->format('d M Y H:i') ?: '—' }}</p>
                            <p><strong>{{ __('Created at') }}:</strong> {{ optional($payment->created_at)->format('d M Y H:i') ?: '—' }}</p>
                            @if($payment->failure_reason)
                                <p><strong>{{ __('Note') }}:</strong> {{ $payment->failure_reason }}</p>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h4>{{ __('Lifecycle signals') }}</h4></div>
                <div class="card-body">
                    <ul class="list-group">
                        <li class="list-group-item">{{ __('Initiated') }} — {{ optional($payment->initiated_at)->toDateTimeString() ?: '—' }}</li>
                        <li class="list-group-item">{{ __('Verified (gateway)') }} — {{ optional($payment->verified_at)->toDateTimeString() ?: '—' }}</li>
                        <li class="list-group-item">{{ __('Subscription activated') }} — {{ optional($payment->activated_at)->toDateTimeString() ?: '—' }}</li>
                        @if(!empty($payment->callback_payload['browser_return']['at']))
                            <li class="list-group-item">{{ __('Browser return recorded') }} — {{ $payment->callback_payload['browser_return']['at'] }}</li>
                        @endif
                        @if(!empty($payment->callback_payload['ipn']['at']))
                            <li class="list-group-item">{{ __('Merchant callback recorded') }} — {{ $payment->callback_payload['ipn']['at'] }}</li>
                        @endif
                    </ul>
                    <p class="text-muted mt-3 mb-0">{{ __('Only events present in the payment record are shown. No fabricated history.') }}</p>
                </div>
            </div>
        </div>
    </section>
</div>
@endsection
