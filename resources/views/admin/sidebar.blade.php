<style>
.main-sidebar {
    height: 100vh;
    overflow-y: auto;
    background: #ffffff;  /* CHANGE: white background */
}

#sidebar-wrapper {
    height: 100%;
    overflow-y: auto;
}

.sidebar-brand {
    padding: 20px 15px;
    text-align: center;
    border-bottom: 1px solid #dee2e6;
}

.sidebar-brand a {
    display: inline-block;
}

.sidebar-brand img {
    max-width: 180px;
    border-radius: 8px;
}

.sidebar-brand-sm {
    display: none;
}

.sidebar-menu {
    list-style: none;
    padding: 0 0 20px 0;
    margin: 0;
}

.sidebar-menu .menu-header {
    margin: 15px 0 5px;
    padding: 5px 20px;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: #6c757d;  /* CHANGE: original gray */
    font-weight: 600;
}

.sidebar-menu li a {
    display: flex;
    align-items: center;
    padding: 10px 20px;
    color: #333333;  /* CHANGE: original dark text */
    text-decoration: none;
    font-size: 14px;
    transition: all 0.2s;
}

.sidebar-menu li a:hover {
    background: #e9ecef;  /* CHANGE: light hover */
    color: #000;
}

.sidebar-menu li a.active {
    background: #EB9620;  /* CHANGE: theme accent color */
    color: white;
}

.sidebar-menu li a i {
    width: 24px;
    margin-right: 10px;
    text-align: center;
    color: inherit;
}

.sidebar-menu li a span {
    flex: 1;
    white-space: nowrap;
}

/* DROPDOWN STYLES */
.dropdown-parent {
    position: relative;
}

.dropdown-toggle {
    cursor: pointer;
}

.dropdown-arrow {
    margin-left: auto;
    transition: transform 0.25s ease;
    font-size: 12px;
}

.dropdown-toggle[aria-expanded="true"] .dropdown-arrow {
    transform: rotate(90deg);
}

.submenu {
    list-style: none;
    padding-left: 0;
    margin: 0;
    display: none;
    background: #f8f9fa;  /* CHANGE: light gray background */
}

.submenu li a {
    padding: 8px 20px 8px 54px;
    font-size: 13px;
    color: #555555;  /* CHANGE: original color */
}

.submenu li a i {
    width: 20px;
    font-size: 13px;
}

.submenu li a.active {
    background: #EB9620;
    color: white;
}

/* Badge styles */
.badge-pending {
    background-color: #dc3545;
    color: white;
    font-size: 11px;
    padding: 2px 8px;
    border-radius: 30px;
    margin-left: auto;
}

/* Silver color tree lines REMOVED */
.sidebar-menu li::before {
    display: none;
}

/* Custom scrollbar */
.main-sidebar::-webkit-scrollbar {
    width: 5px;
}

.main-sidebar::-webkit-scrollbar-track {
    background: #f1f1f1;
}

.main-sidebar::-webkit-scrollbar-thumb {
    background: #c1c1c1;
    border-radius: 5px;
}
</style>

<!-- jQuery CDN -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js" integrity="sha256-/xUj+3OJU5yExlq6GSYGSHk7tPXikynS7ogEvDej/m4=" crossorigin="anonymous"></script>

<div class="main-sidebar">
    <aside id="sidebar-wrapper">

        <!-- Logo -->
        <div class="sidebar-brand">
            <a href="{{ route('admin.dashboard') }}">
                <img class="w-75" src="{{ asset('public/backend/img/admin-auth-bg.png') }}">
            </a>
        </div>

        <div class="sidebar-brand sidebar-brand-sm">
            <a href="{{ route('admin.dashboard') }}">
                <img src="{{ asset('public/backend/img/admin-auth-bg.png') }}">
            </a>
        </div>

        @php
            $admin = auth('admin')->user();
            $pendingJobsCount = \App\Models\StaffJob::where('status', 'pending')->count();
        @endphp

        <ul class="sidebar-menu">

            <!-- DASHBOARD -->
            <li class="menu-header">{{ __('Dashboard') }}</li>
            <li class="{{ isRoute('admin.dashboard', 'active') }}">
                <a href="{{ route('admin.dashboard') }}">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>{{ __('Dashboard') }}</span>
                </a>
            </li>

            <!-- SYSTEM MANAGEMENT -->
            @if(
                $admin->can('users.view') ||
                $admin->can('bookings.view') ||
                $admin->can('bookings.settings.view') ||
                $admin->can('subscriptions.view') ||
                $admin->can('service.categories.view') ||
                $admin->can('complaints.view')
            )
            <li class="menu-header">{{ __('System Management') }}</li>
            <li class="dropdown-parent">
                <a href="javascript:void(0);" class="dropdown-toggle" aria-expanded="false">
                    <i class="fas fa-cogs"></i>
                    <span>{{ __('System Records') }}</span>
                    <i class="fas fa-chevron-right dropdown-arrow"></i>
                </a>
                <ul class="submenu">
                    @can('users.view')
                    <li class="{{ request()->routeIs('admin.users.*') ? 'active' : '' }}">
                        <a href="{{ route('admin.users.index') }}">
                            <i class="fas fa-user-friends"></i>
                            <span>{{ __('Users') }}</span>
                        </a>
                    </li>
                    @endcan
                    @can('bookings.view')
                    <li class="{{ request()->routeIs('admin.bookings.index', 'admin.bookings.destroy') ? 'active' : '' }}">
                        <a href="{{ route('admin.bookings.index') }}">
                            <i class="fas fa-calendar-check"></i>
                            <span>{{ __('Bookings') }}</span>
                        </a>
                    </li>
                    @endcan
                    @can('bookings.settings.view')
                    <li class="{{ request()->routeIs('admin.bookings.settings*') ? 'active' : '' }}">
                        <a href="{{ route('admin.bookings.settings') }}">
                            <i class="fas fa-hourglass-half"></i>
                            <span>{{ __('Booking Settings') }}</span>
                        </a>
                    </li>
                    @endcan
                    <li class="{{ request()->routeIs('admin.app-notifications.*') ? 'active' : '' }}">
                        <a href="{{ route('admin.app-notifications.index') }}">
                            <i class="fas fa-bell"></i>
                            <span>{{ __('App Notifications') }}</span>
                        </a>
                    </li>
                    @can('subscriptions.view')
                    <li class="{{ request()->routeIs('admin.subscriptions.*') ? 'active' : '' }}">
                        <a href="{{ route('admin.subscriptions.index') }}">
                            <i class="fas fa-list-alt"></i>
                            <span>{{ __('Subscriptions') }}</span>
                        </a>
                    </li>
                    <li class="{{ request()->routeIs('admin.subscription-payments.*') ? 'active' : '' }}">
                        <a href="{{ route('admin.subscription-payments.index') }}">
                            <i class="fas fa-credit-card"></i>
                            <span>{{ __('Subscription Payments') }}</span>
                            <span class="badge badge-danger badge-pending ms-1 d-none" id="sidebarPaymentUnreadBadge">0</span>
                        </a>
                    </li>
                    @endcan
                    @can('service.categories.view')
                    <li class="{{ request()->routeIs('admin.service-categories.*') ? 'active' : '' }}">
                        <a href="{{ route('admin.service-categories.index') }}">
                            <i class="fas fa-tags"></i>
                            <span>{{ __('Service Categories') }}</span>
                        </a>
                    </li>
                    @endcan
                    {{-- Email Configuration (Settings): /admin/email-configuration --}}
                    {{-- <li><a href="{{ url('admin/email-configuration') }}"><i class="fas fa-envelope"></i><span>{{ __('Email Configuration') }}</span></a></li> --}}
                    @can('complaints.view')
                    <li class="{{ request()->routeIs('admin.help-supports.*') ? 'active' : '' }}">
                        <a href="{{ route('admin.help-supports.index') }}">
                            <i class="fas fa-headset"></i>
                            <span>{{ __('Complaints') }}</span>
                        </a>
                    </li>
                    @endcan
                </ul>
            </li>
            @endif

            <!-- ADMIN SETTINGS DROPDOWN -->
            @if(
                $admin->can('role.view') ||
                $admin->can('role.assign') ||
                $admin->can('activity.logs.view') ||
                $admin->can('admin.view')
            )
                <li class="menu-header">{{ __('Admin Settings') }}</li>
                <li class="dropdown-parent">
                    <a href="javascript:void(0);" class="dropdown-toggle" aria-expanded="false">
                        <i class="fas fa-cog"></i>
                        <span>{{ __('Settings & Permissions') }}</span>
                        <i class="fas fa-chevron-right dropdown-arrow"></i>
                    </a>
                    <ul class="submenu">
                        @can('role.view')
                        <li class="{{ isRoute('admin.role.index', 'active') }}">
                            <a href="{{ route('admin.role.index') }}">
                                <i class="fas fa-shield-alt"></i>
                                <span>{{ __('Manage Roles') }}</span>
                            </a>
                        </li>
                        @endcan
                        @can('role.assign')
                        <li class="{{ isRoute('assign.permissions.form', 'active') }}">
                            <a href="{{ route('assign.permissions.form') }}">
                                <i class="fas fa-key"></i>
                                <span>{{ __('Assign Permissions') }}</span>
                            </a>
                        </li>
                        @endcan
                        @can('admin.view')
                        <li class="{{ isRoute('admin.admin.index', 'active') }}">
                            <a href="{{ route('admin.admin.index') }}">
                                <i class="fas fa-user-plus"></i>
                                <span>{{ __('Add Sub Admin') }}</span>
                            </a>
                        </li>
                        @endcan
                        @can('role.assign')
                        <li class="{{ isRoute('admin.assign-roles.index', 'active') }}">
                            <a href="{{ route('admin.assign-roles.index') }}">
                                <i class="fas fa-user-tag"></i>
                                <span>{{ __('Assign Roles') }}</span>
                            </a>
                        </li>
                        @endcan
                        
                    </ul>
                </li>
            @endif

            <!-- AGENT MANAGEMENT DROPDOWN -->
            @if(
                $admin->can('staff.view') ||
                $admin->can('staff.create') ||
                $admin->can('staff.permission.view') ||
                $admin->can('district.view')
            )
                <li class="menu-header">{{ __('Agent Management') }}</li>
                <li class="dropdown-parent">
                    <a href="javascript:void(0);" class="dropdown-toggle" aria-expanded="false">
                        <i class="fas fa-users"></i>
                        <span>{{ __('Agent Controls') }}</span>
                        <i class="fas fa-chevron-right dropdown-arrow"></i>
                    </a>
                    <ul class="submenu">
                        @can('staff.view')
                        <li class="{{ isRoute('admin.staff.index', 'active') }}">
                            <a href="{{ route('admin.staff.index') }}">
                                <i class="fas fa-users"></i>
                                <span>{{ __('Agent List') }}</span>
                            </a>
                        </li>
                        @endcan
                        @can('district.view')
                        <li class="{{ isRoute('admin.districts.index', 'active') }}">
                            <a href="{{ route('admin.districts.index') }}">
                                <i class="fas fa-map-marker-alt"></i>
                                <span>{{ __('Districts') }}</span>
                            </a>
                        </li>
                        @endcan
                        @can('staff.permission.view')
                        <li class="{{ isRoute('admin.staff-permissions.index', 'active') }}">
                            <a href="{{ route('admin.staff-permissions.index') }}">
                                <i class="fas fa-shield-alt"></i>
                                <span>{{ __('Agent Permissions') }}</span>
                            </a>
                        </li>
                        @endcan
                    </ul>
                </li>
            @endif

            <!-- SHOP MANAGEMENT DROPDOWN -->
            @if(
                $admin->can('shop.view') ||
                $admin->can('shop.create') ||
                $admin->can('shop.category.view')
            )
                <li class="menu-header">{{ __('Shop Management') }}</li>
                <li class="dropdown-parent">
                    <a href="javascript:void(0);" class="dropdown-toggle" aria-expanded="false">
                        <i class="fas fa-store"></i>
                        <span>{{ __('Shop Operations') }}</span>
                        <i class="fas fa-chevron-right dropdown-arrow"></i>
                    </a>
                    <ul class="submenu">
                          @can('shop.category.view')
                        <li class="{{ isRoute('admin.shop-categories.index', 'active') }}">
                            <a href="{{ route('admin.shop-categories.index') }}">
                                <i class="fas fa-tags"></i>
                                <span>{{ __('Shop Categories') }}</span>
                            </a>
                        </li>
                        @endcan
                        @can('shop.view')
                        <li class="{{ isRoute('admin.shop-management.shop-list', 'active') }}">
                            <a href="{{ route('admin.shop-management.shop-list') }}">
                                <i class="fas fa-store"></i>
                                <span>{{ __('Shop List') }}</span>
                            </a>
                        </li>
                        @endcan
                        @can('shop.edit')
                        <li class="{{ isRoute('admin.shop-management.index', 'active') }}">
                            <a href="{{ route('admin.shop-management.index') }}">
                                <i class="fas fa-tasks"></i>
                                <span>{{ __('Assigned Jobs') }}</span>
                                @if($pendingJobsCount > 0)
                                    <span class="btn-danger" style="
                                                display:flex;
                                                align-items:center;
                                                justify-content:center;
                                                width:26px;
                                                height:26px;
                                                background-color:#dc3545;
                                                color:#fff; 
                                                font-size:12px;
                                                font-weight:600;
                                                border-radius:50%;
                                                margin-left:auto;
                                                flex:0 0 36px;
                                                align-self:center;">{{ $pendingJobsCount }}</span>
                                @endif
                            </a>
                        </li>
                        @endcan
                    </ul>
                </li>
            @endif

        </ul>
    </aside>
</div>

<script>
$(document).ready(function() {
    
    // Close all dropdowns function
    function closeAllDropdowns() {
        $('.submenu').slideUp(200);
        $('.dropdown-toggle').attr('aria-expanded', 'false');
    }
    
    // Toggle dropdown on click with smooth slide animation
    $('.dropdown-toggle').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        var $this = $(this);
        var $parent = $this.closest('.dropdown-parent');
        var $submenu = $parent.find('.submenu');
        var isExpanded = $this.attr('aria-expanded') === 'true';
        
        if (isExpanded) {
            // Close this dropdown
            $submenu.slideUp(200);
            $this.attr('aria-expanded', 'false');
        } else {
            // Close all other dropdowns (accordion behavior)
            $('.submenu').not($submenu).slideUp(200);
            $('.dropdown-toggle').not($this).attr('aria-expanded', 'false');
            
            // Open this dropdown with smooth slide
            $submenu.slideDown(250);
            $this.attr('aria-expanded', 'true');
        }
    });
    
    // Keep dropdown open if it contains active menu item
    function openParentDropdownForActiveLink() {
        $('.submenu li.active, .submenu li a.active').each(function() {
            var $parent = $(this).closest('.dropdown-parent');
            var $toggle = $parent.find('.dropdown-toggle');
            var $submenu = $parent.find('.submenu');
            
            if ($submenu.length && $submenu.is(':hidden')) {
                $submenu.slideDown(200);
                $toggle.attr('aria-expanded', 'true');
            }
        });
    }
    
    // Call on load
    openParentDropdownForActiveLink();
    
    // Optional: Prevent event bubbling on menu clicks
    $('.sidebar-menu a').on('click', function(e) {
        if ($(this).hasClass('dropdown-toggle')) {
            return;
        }
        // For normal links, just let them navigate
    });
    
});
</script>