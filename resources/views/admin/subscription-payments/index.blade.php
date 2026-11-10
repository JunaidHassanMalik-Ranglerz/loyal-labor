@extends('admin.master_layout')

@section('title')
    <title>{{ __('Subscription Payments') }}</title>
@endsection

@section('admin-content')
<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>{{ __('Subscription Payments') }}</h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">{{ __('Dashboard') }}</a></div>
                <div class="breadcrumb-item active">{{ __('Subscription Payments') }}</div>
            </div>
        </div>

        <div class="section-body">
            <div class="card">
                <div class="card-header">
                    <h4>{{ __('Verified & gateway payment history') }}</h4>
                </div>
                <div class="card-body">
                    <form id="paymentFilters" class="row g-2 mb-3">
                        <div class="col-md-2">
                            <label class="form-label">{{ __('Status') }}</label>
                            <select name="status" class="form-control">
                                <option value="">{{ __('All') }}</option>
                                @foreach($statuses as $status)
                                    <option value="{{ $status }}">{{ ucfirst($status) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">{{ __('Plan') }}</label>
                            <select name="subscription_id" class="form-control">
                                <option value="">{{ __('All') }}</option>
                                @foreach($plans as $plan)
                                    <option value="{{ $plan->id }}">{{ $plan->name ?: $plan->plan_type_label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">{{ __('Technician') }}</label>
                            <select name="technician_id" class="form-control">
                                <option value="">{{ __('All') }}</option>
                                @foreach($technicians as $tech)
                                    <option value="{{ $tech->id }}">{{ $tech->name ?: $tech->email }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">{{ __('Gateway') }}</label>
                            <input type="text" name="gateway" class="form-control" placeholder="payfast">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">{{ __('Order Ref') }}</label>
                            <input type="text" name="order_reference" class="form-control" placeholder="LOY-">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">{{ __('My notification') }}</label>
                            <select name="read_state" class="form-control">
                                <option value="">{{ __('All') }}</option>
                                <option value="unread">{{ __('Unread') }}</option>
                                <option value="read">{{ __('Read') }}</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">{{ __('From') }}</label>
                            <input type="date" name="date_from" class="form-control">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">{{ __('To') }}</label>
                            <input type="date" name="date_to" class="form-control">
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="button" id="applyPaymentFilters" class="btn btn-primary me-2">{{ __('Filter') }}</button>
                            <button type="reset" id="resetPaymentFilters" class="btn btn-light">{{ __('Reset') }}</button>
                        </div>
                    </form>

                    <div class="table-responsive">
                        <table class="table table-striped" id="subscriptionPaymentsTable" style="width:100%">
                            <thead>
                                <tr>
                                    <th>{{ __('Payment ID') }}</th>
                                    <th>{{ __('Technician') }}</th>
                                    <th>{{ __('Plan') }}</th>
                                    <th>{{ __('Original') }}</th>
                                    <th>{{ __('Discount') }}</th>
                                    <th>{{ __('Payable') }}</th>
                                    <th>{{ __('Status') }}</th>
                                    <th>{{ __('Gateway') }}</th>
                                    <th>{{ __('Order Ref') }}</th>
                                    <th>{{ __('Txn ID') }}</th>
                                    <th>{{ __('Date') }}</th>
                                    <th>{{ __('Actions') }}</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>
@endsection

@push('js')
<script>
(function () {
    const table = $('#subscriptionPaymentsTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url: "{{ route('admin.subscription-payments.index') }}",
            data: function (d) {
                const form = $('#paymentFilters').serializeArray();
                form.forEach(function (item) {
                    d[item.name] = item.value;
                });
            }
        },
        order: [[0, 'desc']],
        columns: [
            { data: 'id', name: 'id' },
            { data: 'technician_name', name: 'technician_name', orderable: false },
            { data: 'plan_name', name: 'plan_name', orderable: false },
            { data: 'original_amount', name: 'original_amount', orderable: false },
            { data: 'discount_display', name: 'discount_display', orderable: false },
            { data: 'payable_amount', name: 'payable_amount', orderable: false },
            { data: 'status_badge', name: 'status', orderable: false },
            { data: 'gateway', name: 'gateway' },
            { data: 'order_reference', name: 'order_reference' },
            { data: 'payfast_transaction_id', name: 'payfast_transaction_id', defaultContent: '—' },
            { data: 'payment_date', name: 'payment_date', orderable: false },
            { data: 'action', name: 'action', orderable: false, searchable: false }
        ]
    });

    $('#applyPaymentFilters').on('click', function () {
        table.ajax.reload();
    });
    $('#resetPaymentFilters').on('click', function () {
        setTimeout(function () { table.ajax.reload(); }, 0);
    });
})();
</script>
@endpush
