@extends('admin.master_layout')

@section('title')
    <title>{{ __('Edit Subscription Plan') }}</title>
@endsection

@section('admin-content')
<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>{{ __('Edit Subscription Plan') }}</h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item"><a href="{{ route('admin.subscriptions.index') }}">{{ __('Subscriptions') }}</a></div>
                <div class="breadcrumb-item">{{ __('Edit') }}</div>
            </div>
        </div>

        <div class="section-body">
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-body">
                            <form action="{{ route('admin.subscriptions.update', $subscription->id) }}" method="POST">
                                @csrf
                                @method('PUT')

                                <div class="row">
                                    <div class="form-group col-12">
                                        <label>{{ __('Plan Name') }} <span class="text-danger">*</span></label>
                                        <select name="plan_type" id="plan_type" class="form-control" required>
                                            @foreach($planTypes as $value => $label)
                                                <option value="{{ $value }}" @selected(old('plan_type', $subscription->plan_type ?? 'basic_plan') === $value)>{{ __($label) }}</option>
                                            @endforeach
                                        </select>
                                        <small class="text-muted">{{ __('Only Basic, Silver and Gold plans are allowed. Features are fixed.') }}</small>
                                    </div>

                                    <div class="form-group col-12 col-md-6">
                                        <label>{{ __('Duration') }} <span class="text-danger">*</span></label>
                                        <input type="number" id="duration_months" class="form-control" name="duration_months" value="{{ old('duration_months', $subscription->duration_months ?? 1) }}" required min="1" placeholder="e.g., 7, 15, 30">
                                        <small class="text-muted">{{ __('Number of days or months this plan stays active') }}</small>
                                    </div>

                                    <div class="form-group col-12 col-md-6">
                                        <label>{{ __('Duration Unit') }} <span class="text-danger">*</span></label>
                                        <select name="duration_unit" id="duration_unit" class="form-control" required>
                                            @foreach(\App\Models\Subscription::DURATION_UNITS as $value => $label)
                                                <option value="{{ $value }}" @selected(old('duration_unit', $subscription->duration_unit ?? 'months') === $value)>{{ __($label) }}</option>
                                            @endforeach
                                        </select>
                                    </div>

                                    <div class="form-group col-12 col-md-6">
                                        <label>{{ __('Original Price (PKR)') }} <span class="text-danger">*</span></label>
                                        <input type="number" step="0.01" id="original_price" class="form-control" name="original_price" value="{{ old('original_price', number_format($subscription->resolvedOriginalPrice(), 2, '.', '')) }}" placeholder="e.g., 1000" required min="0.01">
                                    </div>

                                    <div class="form-group col-12 col-md-6">
                                        <label>{{ __('Discount (%)') }} <span class="text-danger">*</span></label>
                                        <input type="number" step="0.01" id="discount_percentage" class="form-control" name="discount_percentage" value="{{ old('discount_percentage', number_format($subscription->resolvedDiscountPercentage(), 2, '.', '')) }}" placeholder="e.g., 20" required min="0" max="100">
                                    </div>

                                    <div class="form-group col-12">
                                        <label>{{ __('Payable Price (PKR)') }}</label>
                                        <input type="number" step="0.01" id="payable_price" class="form-control" value="{{ old('payable_price', number_format($subscription->resolvedPayablePrice(), 2, '.', '')) }}" placeholder="0.00" readonly>
                                        <small class="text-muted">{{ __('Automatically calculated from Original Price and Discount (%).') }}</small>
                                    </div>

                                    <div class="form-group col-12">
                                        <label>{{ __('Features') }} <span class="text-danger">*</span></label>
                                        <div id="featuresList" class="features-tags-container"></div>
                                        <small class="text-muted">{{ __('Features are locked to the selected plan and cannot be changed.') }}</small>
                                    </div>

                                    <div class="form-group col-12">
                                        <label>{{ __('Status') }}</label>
                                        <select name="is_active" class="form-control">
                                            <option value="1" {{ old('is_active', (int) $subscription->is_active) == 1 ? 'selected' : '' }}>{{ __('Active') }}</option>
                                            <option value="0" {{ old('is_active', (int) $subscription->is_active) == 0 ? 'selected' : '' }}>{{ __('Inactive') }}</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-12">
                                        <button class="btn btn-primary" type="submit">
                                            <i class="fas fa-save"></i> {{ __('Update Plan') }}
                                        </button>
                                        <a href="{{ route('admin.subscriptions.index') }}" class="btn btn-secondary">
                                            <i class="fas fa-times"></i> {{ __('Cancel') }}
                                        </a>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>
@endsection

@push('css')
<style>
    .features-tags-container {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        padding: 15px;
        min-height: 100px;
        border: 1px solid #e3e6f0;
        border-radius: 8px;
        background: #f8f9fc;
        margin-top: 10px;
    }

    .feature-tag {
        display: inline-flex;
        align-items: center;
        padding: 8px 15px;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border-radius: 50px;
        font-size: 14px;
        font-weight: 500;
    }

    .feature-tag i {
        margin-right: 8px;
        font-size: 12px;
    }

    .empty-features {
        color: #858796;
        text-align: center;
        width: 100%;
        padding: 20px;
        font-style: italic;
    }
</style>
@endpush

@push('js')
@include('admin.partials.system-records-toast')
<script>
    $(document).ready(function() {
        const staticFeatures = @json($staticFeatures);

        function escapeHtml(text) {
            const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
            return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
        }

        function renderFeatures(planType) {
            const container = $('#featuresList');
            const features = (staticFeatures[planType] || []).map(f => f.label);

            container.empty();
            if (!planType || features.length === 0) {
                container.html('<div class="empty-features"><i class="fas fa-info-circle"></i> Select a plan to see locked features</div>');
                return;
            }

            features.forEach(function(feature) {
                container.append(`
                    <div class="feature-tag">
                        <i class="fas fa-check-circle"></i>
                        ${escapeHtml(feature)}
                    </div>
                `);
            });
        }

        function calculatePayablePrice() {
            const original = parseFloat($('#original_price').val());
            const discount = parseFloat($('#discount_percentage').val());
            const safeOriginal = isNaN(original) || original < 0 ? 0 : original;
            const safeDiscount = isNaN(discount) ? 0 : Math.min(100, Math.max(0, discount));
            const payable = Math.max(0, safeOriginal - (safeOriginal * safeDiscount / 100));
            $('#payable_price').val(payable.toFixed(2));
        }

        $('#plan_type').on('change', function() {
            renderFeatures($(this).val());
        });

        $('#original_price, #discount_percentage').on('input change', calculatePayablePrice);

        renderFeatures($('#plan_type').val());
        calculatePayablePrice();
    });
</script>
@endpush
