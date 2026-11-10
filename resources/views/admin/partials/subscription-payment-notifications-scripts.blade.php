@can('subscriptions.view')
<script src="https://js.pusher.com/8.2.0/pusher.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/laravel-echo@1.16.1/dist/echo.iife.js"></script>
<script>
(function ($) {
    'use strict';

    const feedUrl = @json(route('admin.subscription-payment-notifications.feed'));
    const unreadUrl = @json(route('admin.subscription-payment-notifications.unread-count'));
    const markAllUrl = @json(route('admin.subscription-payment-notifications.mark-all-read'));
    const markReadUrlTpl = @json(url('admin/subscription-payment-notifications/__ID__/read'));
    const csrf = @json(csrf_token());
    const adminId = @json((int) auth('admin')->id());
    const pusherKey = @json(config('broadcasting.connections.pusher.key'));
    const pusherCluster = @json(config('broadcasting.connections.pusher.options.cluster'));
    const broadcastDriver = @json(config('broadcasting.default'));
    const authEndpoint = @json(route('admin.broadcasting.auth'));

    let lastUnread = null;
    let pollTimer = null;
    const POLL_MS = 15000;

    function setBadge(count) {
        count = Math.max(0, parseInt(count, 10) || 0);
        lastUnread = count;
        const $nav = $('#adminPaymentNotifBadge');
        const $side = $('#sidebarPaymentUnreadBadge');
        [$nav, $side].forEach(function ($el) {
            if (!$el.length) return;
            if (count > 0) {
                $el.text(count > 99 ? '99+' : String(count)).removeClass('d-none');
            } else {
                $el.text('0').addClass('d-none');
            }
        });
        const $toggle = $('#adminPaymentNotifToggle');
        if ($toggle.length) {
            $toggle.toggleClass('beep', count > 0);
        }
    }

    function renderList(items) {
        const $list = $('#adminPaymentNotifList');
        if (!$list.length) return;
        if (!items || !items.length) {
            $list.html('<div class="dropdown-item text-muted">{{ __("No payment notifications yet.") }}</div>');
            return;
        }
        const html = items.map(function (n) {
            const unreadClass = n.is_read ? '' : 'dropdown-item-unread';
            const amount = (n.currency || 'PKR') + ' ' + Number(n.amount || 0).toFixed(2);
            const href = n.detail_url
                ? (n.detail_url + (n.detail_url.indexOf('?') >= 0 ? '&' : '?') + 'notification_id=' + encodeURIComponent(n.id))
                : '#';
            return (
                '<a class="dropdown-item ' + unreadClass + ' admin-pay-notif-item" href="' + href + '" data-id="' + n.id + '">' +
                    '<div class="dropdown-item-icon bg-success text-white"><i class="fas fa-credit-card"></i></div>' +
                    '<div class="dropdown-item-desc">' +
                        '<span><strong>' + $('<div>').text(n.technician_name || 'Technician').html() + '</strong> · ' +
                        $('<div>').text(n.plan_name || 'Plan').html() + '</span>' +
                        '<p>' + $('<div>').text(amount).html() + ' · ' + $('<div>').text(n.status || '').html() + '</p>' +
                        '<div class="time">' + $('<div>').text(n.created_at_human || '').html() + '</div>' +
                    '</div>' +
                '</a>'
            );
        }).join('');
        $list.html(html);
    }

    function refreshFeed() {
        return $.ajax({
            url: feedUrl,
            method: 'GET',
            dataType: 'json',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).done(function (res) {
            if (!res || !res.success) return;
            setBadge(res.unread_count);
            renderList(res.notifications || []);
        }).fail(function () {
            // Keep last known badge; silent fail for polling.
        });
    }

    function refreshUnreadOnly() {
        return $.ajax({
            url: unreadUrl,
            method: 'GET',
            dataType: 'json',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).done(function (res) {
            if (res && res.success) setBadge(res.unread_count);
        });
    }

    function markRead(id) {
        const url = markReadUrlTpl.replace('__ID__', encodeURIComponent(id));
        return $.ajax({
            url: url,
            method: 'POST',
            data: { _token: csrf },
            dataType: 'json'
        }).done(function (res) {
            if (res && typeof res.unread_count !== 'undefined') {
                setBadge(res.unread_count);
            }
        });
    }

    function startPolling() {
        stopPolling();
        pollTimer = setInterval(function () {
            if (document.hidden) return;
            refreshUnreadOnly().then(function () {
                // If unread changed, refresh list content too.
                if ($('#adminPaymentNotifList').is(':visible') || lastUnread > 0) {
                    refreshFeed();
                }
            });
        }, POLL_MS);
    }

    function stopPolling() {
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    function initPusher() {
        if (broadcastDriver !== 'pusher' || !pusherKey || typeof Echo === 'undefined' || !adminId) {
            return false;
        }
        try {
            window.LoyLaborAdminEcho = new Echo({
                broadcaster: 'pusher',
                key: pusherKey,
                cluster: pusherCluster || 'mt1',
                forceTLS: true,
                authEndpoint: authEndpoint,
                auth: {
                    headers: {
                        'X-CSRF-TOKEN': csrf
                    }
                }
            });
            window.LoyLaborAdminEcho.private('admin.' + adminId)
                .listen('.subscription.payment.received', function (payload) {
                    if (payload && typeof payload.unread_count !== 'undefined') {
                        setBadge(payload.unread_count);
                    }
                    refreshFeed();
                    if (window.iziToast && payload && payload.notification) {
                        iziToast.success({
                            title: '{{ __("New subscription payment") }}',
                            message: (payload.notification.technician_name || '') + ' · ' +
                                (payload.notification.currency || 'PKR') + ' ' +
                                Number(payload.notification.amount || 0).toFixed(2),
                            position: 'topRight',
                            timeout: 5000
                        });
                    }
                });
            return true;
        } catch (e) {
            return false;
        }
    }

    $(function () {
        if (!$('#adminPaymentNotifWrap').length) return;

        refreshFeed();
        const realtime = initPusher();
        // Always keep polling as resilient fallback (also after refresh/reconnect).
        startPolling();
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) {
                refreshFeed();
            }
        });

        $('#adminPaymentNotifMarkAll').on('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            $.ajax({
                url: markAllUrl,
                method: 'POST',
                data: { _token: csrf },
                dataType: 'json'
            }).done(function (res) {
                setBadge(0);
                refreshFeed();
                if (window.iziToast) {
                    iziToast.success({ message: (res && res.message) || 'Done', position: 'topRight' });
                }
            });
        });

        $(document).on('click', '.admin-pay-notif-item', function () {
            const id = $(this).data('id');
            if (id) {
                // Fire-and-forget mark read; navigation continues.
                markRead(id);
            }
        });

        // Expose for debugging / reconnect.
        window.LoyLaborAdminPaymentNotifs = {
            refresh: refreshFeed,
            realtime: realtime
        };
    });
})(jQuery);
</script>
<style>
#adminPaymentNotifBadge.navbar-badge {
    position: absolute;
    top: 4px;
    right: 2px;
    font-size: 10px;
    min-width: 16px;
    padding: 2px 4px;
}
#adminPaymentNotifToggle { position: relative; }
#sidebarPaymentUnreadBadge {
    font-size: 10px;
    vertical-align: middle;
}
</style>
@endcan
