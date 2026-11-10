@can('subscriptions.view')
<li class="dropdown dropdown-list-toggle" id="adminPaymentNotifWrap">
    <a href="#" data-bs-toggle="dropdown" class="nav-link notification-toggle nav-link-lg" id="adminPaymentNotifToggle" aria-expanded="false">
        <i class="far fa-bell"></i>
        <span class="badge badge-danger navbar-badge d-none" id="adminPaymentNotifBadge">0</span>
    </a>
    <div class="dropdown-menu dropdown-list dropdown-menu-end" style="width: 360px; max-width: 95vw;">
        <div class="dropdown-header d-flex justify-content-between align-items-center">
            <span>{{ __('Payment Notifications') }}</span>
            <button type="button" class="btn btn-success btn-sm p-1" id="adminPaymentNotifMarkAll" title="{{ __('Mark all as read') }}">
                <i class="fas fa-check-circle"></i>
            </button>
        </div>
        <div class="dropdown-list-content dropdown-list-icons" id="adminPaymentNotifList" style="max-height: 360px; overflow:auto;">
            <div class="dropdown-item text-muted">{{ __('Loading…') }}</div>
        </div>
        <div class="dropdown-footer text-center">
            <a href="{{ route('admin.subscription-payments.index') }}">{{ __('View all payments') }} <i class="fas fa-chevron-right"></i></a>
        </div>
    </div>
</li>
@endcan
