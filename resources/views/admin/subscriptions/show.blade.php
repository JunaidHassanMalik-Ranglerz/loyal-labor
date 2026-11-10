@extends('admin.master_layout')

@section('title')
    <title>{{ __('Subscription Plan Details') }}</title>
@endsection

@section('admin-content')
<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>{{ __('Subscription Plan Details') }}</h1>
            <div class="section-header-breadcrumb">
                <a href="{{ route('admin.subscriptions.index') }}" class="btn btn-primary">
                    <i class="fa fa-arrow-left"></i> {{ __('Back') }}
                </a>
            </div>
        </div>

        <div class="section-body">
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-body">
                            <table class="table table-bordered">
                                <tr>
                                    <th>{{ __('Name') }}</th>
                                    <td>{{ $subscription->name }}</td>
                                </tr>
                                <tr>
                                    <th>{{ __('Duration') }}</th>
                                    <td>{{ $subscription->duration_label }}</td>
                                </tr>
                                <tr>
                                    <th>{{ __('Original Price (PKR)') }}</th>
                                    <td>{{ number_format($subscription->resolvedOriginalPrice(), 2) }}</td>
                                </tr>
                                <tr>
                                    <th>{{ __('Discount (%)') }}</th>
                                    <td>{{ number_format($subscription->resolvedDiscountPercentage(), 2) }}%</td>
                                </tr>
                                <tr>
                                    <th>{{ __('Payable Price (PKR)') }}</th>
                                    <td>{{ number_format($subscription->resolvedPayablePrice(), 2) }}</td>
                                </tr>
                                <tr>
                                    <th>{{ __('Status') }}</th>
                                    <td>
                                        @if($subscription->is_active)
                                            <span class="badge badge-success">{{ __('Active') }}</span>
                                        @else
                                            <span class="badge badge-danger">{{ __('Inactive') }}</span>
                                        @endif
                                    </td>
                                </tr>
                                <tr>
                                    <th>{{ __('Features') }}</th>
                                    <td>
                                        @php
                                            $features = $subscription->features;
                                            if (is_string($features)) {
                                                $decoded = json_decode($features, true);
                                                $features = json_last_error() === JSON_ERROR_NONE ? $decoded : preg_split('/\\r?\\n/', $features);
                                            }
                                            $features = is_array($features) ? array_values(array_filter($features)) : [];
                                        @endphp

                                        @if(count($features))
                                            <ul class="mb-0">
                                                @foreach($features as $f)
                                                    <li>{{ $f }}</li>
                                                @endforeach
                                            </ul>
                                        @else
                                            <span class="text-muted">{{ __('No features added') }}</span>
                                        @endif
                                    </td>
                                </tr>
                            </table>

                            <div class="mt-3">
                                <a class="btn btn-primary" href="{{ route('admin.subscriptions.edit', $subscription->id) }}">
                                    <i class="fa fa-edit"></i> {{ __('Edit') }}
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>
@endsection

