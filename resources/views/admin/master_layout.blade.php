@php
    $header_admin = Auth::guard('admin')->user();
@endphp

<!DOCTYPE html>
<html lang="en">

<head>
    <link type="image/x-icon" href="{{ asset($setting->favicon) }}" rel="shortcut icon">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, shrink-to-fit=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    @yield('title')
    @include('admin.partials.styles')
    @stack('css')
</head>

<body>
    <div id="app">
        <div class="main-wrapper">
            <div class="navbar-bg"></div>
            <nav class="navbar navbar-expand-lg main-navbar px-3 py-2">
                <div class="me-2 form-inline">
                    <ul class="navbar-nav d-flex align-items-center">
                        <li><a class="nav-link nav-link-lg" data-toggle="sidebar" href="#"><i
                                    class="fas fa-bars"></i></a></li>
									<p style="margin: 0; padding: 0; color: #34395e; font-size: 20px;">Admin Panel</p>
                        <!-- @if (Module::isEnabled('Language') && Route::has('set-language') && allLanguages()?->where('status', 1)->count() > 1)
                            <li class="setLanguageHeader dropdown border rounded-2"><a
                                    class="nav-link dropdown-toggle nav-link-lg nav-link-user" data-bs-toggle="dropdown"
                                    href="javascript:;">
                                    <div class="d-sm-none d-lg-inline-block">
                                        {{ allLanguages()?->firstWhere('code', getSessionLanguage())?->name ?? __('Select language') }}
                                    </div>
                                </a>
                                <div class="dropdown-menu py-0 dropdown-menu-left">
                                    @forelse (allLanguages()?->where('status', 1) as $language)
                                        <a class="dropdown-item has-icon {{ getSessionLanguage() == $language->code ? 'bg-light' : '' }}"
                                            href="{{ getSessionLanguage() == $language->code ? 'javascript:;' : route('set-language', ['code' => $language->code]) }}">
                                            {{ $language->name }}
                                        </a>
                                    @empty
                                        <a class="dropdown-item has-icon {{ getSessionLanguage() == 'en' ? 'bg-light' : '' }}"
                                            href="javascript:;">
                                            {{ __('English') }}
                                        </a>
                                    @endforelse
                                </div>
                            </li>
                        @endif
                        @if (Module::isEnabled('Currency') &&
                                Route::has('set-currency') &&
                                allCurrencies()?->where('status', 'active')->count() > 1)
                            <li class="set-currency-header dropdown border rounded-2 mx-2"><a
                                    class="nav-link dropdown-toggle nav-link-lg nav-link-user" data-bs-toggle="dropdown"
                                    href="javascript:;">
                                    <div class="d-sm-none d-lg-inline-block">
                                        {{ allCurrencies()?->firstWhere('currency_code', getSessionCurrency())?->currency_name ?? __('Select Currency') }}
                                    </div>
                                </a>
                                <div class="dropdown-menu py-0 dropdown-menu-left">
                                    @forelse (allCurrencies()?->where('status', 'active') as $currency)
                                        <a class="dropdown-item has-icon {{ getSessionCurrency() == $currency->currency_code ? 'bg-light' : '' }}"
                                            href="{{ getSessionCurrency() == $currency->currency_code ? 'javascript:;' : route('set-currency', ['currency' => $currency->currency_code]) }}">
                                            {{ $currency->currency_name }}
                                        </a>
                                    @empty
                                        <a class="dropdown-item has-icon {{ getSessionCurrency() == 'USD' ? 'bg-light' : '' }}"
                                            href="javascript:;">
                                            {{ __('USD') }}
                                        </a>
                                    @endforelse
                                </div>
                            </li>
                        @endif -->
                    </ul>
                </div>
                <div class="mr-auto me-md-auto search-box position-relative">
                    <!-- <x-admin.form-input id="search_menu" autocomplete="off" :placeholder="__('Search option')" /> -->

                    <div class="position-absolute d-none rounded-2" id="admin_menu_list">
                        <!-- @foreach (App\Enums\RouteList::getAll() as $route_item)
                            @if (checkAdminHasPermission($route_item?->permission) || empty($route_item?->permission))
                                <a href="{{ $route_item?->route }}"
                                    @isset($route_item->tab)
                                        data-active-tab="{{ $route_item->tab }}" class="border-bottom search-menu-item"
                                    @else
                                        class="border-bottom"
                                    @endisset>{{ $route_item?->name }}</a>
                            @endif
                        @endforeach -->
                        <a class="not-found-message d-none" href="javascript:;">{{ __('Not Found!') }}</a>
                    </div>
                </div>

                <ul class="navbar-nav">
                    @include('admin.partials.subscription-payment-notifications')

                    <li class="dropdown"><a class="nav-link dropdown-toggle nav-link-lg nav-link-user dropdown_user"
                            data-bs-toggle="dropdown" href="javascript:;">
                            @if ($header_admin->image)
                                <img class="me-1 rounded-circle" src="{{ asset($header_admin->image) }}"
                                    alt="image">
                            @else
                                <img class="me-1 rounded-circle" src="{{ asset($setting->default_avatar) }}"
                                    alt="image">
                            @endif

                            <div class="d-sm-none d-lg-inline-block">{{ $header_admin->name }}</div>
                        </a>
                        <div class="dropdown-menu dropdown-menu-right">
                            <a class="dropdown-item has-icon d-flex align-items-center {{ isRoute(['admin.edit-profile'], 'text-primary') }}"
                                href="{{ route('admin.edit-profile') }}">
                                <i class="far fa-user"></i> {{ __('Profile') }}
                            </a>
                            @adminCan('setting.view')
                                <a class="dropdown-item has-icon d-flex align-items-center {{ isRoute(['admin.settings'], 'text-primary') }}"
                                    href="{{ route('admin.settings') }}">
                                    <i class="fas fa-cog"></i> {{ __('Setting') }}
                                </a>
                            @endadminCan
                            <!-- @adminCan('setting.update')
                                <a class="dropdown-item has-icon d-flex align-items-center {{ isRoute(['admin.ecom_setup.index'], 'text-primary') }}"
                                    href="{{ route('admin.ecom_setup.index') }}">
                                    <i class="fas fa-shopping-cart"></i> {{ __('Ecommerce Setup') }}
                                </a>
                            @endadminCan -->
                            <a class="dropdown-item has-icon d-flex align-items-center" href="javascript:;"
                                onclick="event.preventDefault(); $('#admin-logout-form').trigger('submit');">
                                <i class="fas fa-sign-out-alt"></i> {{ __('Logout') }}
                            </a>
                        </div>
                    </li>

                </ul>
            </nav>

            @if (request()->routeIs(
                    'admin.crediential-setting',
                    'admin.email-configuration',
                    'admin.edit-email-template',
                    'admin.currency.*',
                    'admin.tax.*',
                    'admin.seo-setting',
                    'admin.custom-code',
                    'admin.cache-clear',
                    'admin.database-clear',
                    'admin.system-update.index',
                    'admin.languages.*',
                    'admin.basicpayment',
                    'admin.addons.*',
                    'admin.sitemap.*',
                    'admin.ecom_setup.*'))
                @include('admin.settings.sidebar')
            @else
                @include('admin.sidebar')
            @endif
            @yield('admin-content')

            <footer class="main-footer">
                <div class="footer-left">
                   Home-Services-Technicians-Plumbers
                </div>
                <!-- <div class="footer-right">
                    <span>{{ __('version') }}: {{ $setting->version ?? '' }} ({{ __('Loaded in') }}
                        %%LOAD_TIME%%)</span>
                </div> -->
            </footer>

        </div>
    </div>

    {{-- start admin logout form --}}
    <form class="d-none" id="admin-logout-form" action="{{ route('admin.logout') }}" method="POST">
        @csrf
    </form>
    {{-- end admin logout form --}}

    {{-- delete modal --}}
    <x-admin.delete-modal />

    @stack('modals')

    @include('admin.partials.javascripts')
    @include('admin.js-variables')
    @stack('js')

</body>

</html>
