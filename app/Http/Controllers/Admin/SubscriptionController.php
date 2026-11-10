<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Subscription;
use Yajra\DataTables\Facades\DataTables;

class SubscriptionController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        checkAdminHasPermissionAndThrowException('subscriptions.view');

        $query = Subscription::query()->latest();

        if ($request->ajax() || $request->has('draw') || $request->has('start')) {
            return DataTables::of($query)
                ->editColumn('duration_months', function ($plan) {
                    return $plan->duration_label;
                })
                ->addColumn('original_price_display', function ($plan) {
                    return 'PKR ' . number_format($plan->resolvedOriginalPrice(), 2);
                })
                ->addColumn('discount_display', function ($plan) {
                    $discount = $plan->resolvedDiscountPercentage();

                    return rtrim(rtrim(number_format($discount, 2, '.', ''), '0'), '.') . '%';
                })
                ->addColumn('payable_price_display', function ($plan) {
                    return 'PKR ' . number_format($plan->resolvedPayablePrice(), 2);
                })
                ->addColumn('status_toggle', function ($plan) {
                    if (!checkAdminHasPermission('subscriptions.edit')) {
                        return $plan->is_active
                            ? '<span class="badge badge-success">' . __('Active') . '</span>'
                            : '<span class="badge badge-danger">' . __('Inactive') . '</span>';
                    }

                    $checked = $plan->is_active ? 'checked' : '';

                    return '<input onchange="changeSubscriptionStatus(' . $plan->id . ')" id="status_toggle_' . $plan->id . '" type="checkbox" ' . $checked . ' data-toggle="toggle" data-onlabel="' . __('Active') . '" data-offlabel="' . __('Inactive') . '" data-onstyle="success" data-offstyle="danger">';
                })
                ->addColumn('action', function ($plan) {
                    $html = '';

                    if (checkAdminHasPermission('subscriptions.view')) {
                        $html .= '<a class="btn btn-info btn-sm" href="' . route('admin.subscriptions.show', $plan->id) . '"><i class="fa fa-eye"></i></a> ';
                    }

                    if (checkAdminHasPermission('subscriptions.edit')) {
                        $html .= '<a class="btn btn-primary btn-sm" href="' . route('admin.subscriptions.edit', $plan->id) . '"><i class="fa fa-edit"></i></a> ';
                    }

                    if (checkAdminHasPermission('subscriptions.delete')) {
                        $html .= '<a class="btn btn-danger btn-sm deleteForm" href="javascript:;" data-url="' . route('admin.subscriptions.destroy', $plan->id) . '"><i class="fa fa-trash"></i></a>';
                    }

                    return $html;
                })
                ->rawColumns(['status_toggle', 'action'])
                ->make(true);
        }

        return view('admin.subscriptions.index');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        checkAdminHasPermissionAndThrowException('subscriptions.create');

        return view('admin.subscriptions.create', [
            'planTypes' => Subscription::PLAN_TYPES,
            'staticFeatures' => Subscription::STATIC_FEATURES,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        checkAdminHasPermissionAndThrowException('subscriptions.create');

        $request->validate([
            'plan_type' => 'required|in:' . implode(',', Subscription::planTypeKeys()),
            'duration_months' => 'required|integer|min:1',
            'duration_unit' => 'required|in:days,months',
            'original_price' => 'required|numeric|gt:0',
            'discount_percentage' => 'required|numeric|min:0|max:100',
            'is_active' => 'required|in:0,1',
        ]);

        $planType = $request->plan_type;
        $features = Subscription::featureLabelsForPlanType($planType);
        $pricing = Subscription::buildPricingPayload(
            (float) $request->original_price,
            (float) $request->discount_percentage
        );

        // #region agent log
        @file_put_contents(base_path('debug-545283.log'), json_encode(['sessionId' => '545283', 'runId' => 'sub-duration', 'hypothesisId' => 'H-DUR1', 'location' => 'SubscriptionController::store', 'message' => 'create with duration unit', 'data' => ['plan_type' => $planType, 'duration' => (int) $request->duration_months, 'duration_unit' => $request->duration_unit], 'timestamp' => round(microtime(true) * 1000)]) . "\n", FILE_APPEND);
        // #endregion

        Subscription::create(array_merge([
            'name' => Subscription::PLAN_TYPES[$planType],
            'plan_type' => $planType,
            'duration_months' => (int) $request->duration_months,
            'duration_unit' => $request->duration_unit,
            'features' => $features,
            'tax_percent' => 0,
            'is_active' => (int) $request->is_active,
        ], $pricing));

        return redirect()->route('admin.subscriptions.index')->with('success', 'Subscription plan created successfully.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Subscription $subscription)
    {
        checkAdminHasPermissionAndThrowException('subscriptions.view');

        return view('admin.subscriptions.show', compact('subscription'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Subscription $subscription)
    {
        checkAdminHasPermissionAndThrowException('subscriptions.edit');

        return view('admin.subscriptions.edit', [
            'subscription' => $subscription,
            'planTypes' => Subscription::PLAN_TYPES,
            'staticFeatures' => Subscription::STATIC_FEATURES,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Subscription $subscription)
    {
        checkAdminHasPermissionAndThrowException('subscriptions.edit');

        $request->validate([
            'plan_type' => 'required|in:' . implode(',', Subscription::planTypeKeys()),
            'duration_months' => 'required|integer|min:1',
            'duration_unit' => 'required|in:days,months',
            'original_price' => 'required|numeric|gt:0',
            'discount_percentage' => 'required|numeric|min:0|max:100',
            'is_active' => 'required|in:0,1',
        ]);

        $planType = $request->plan_type;
        $features = Subscription::featureLabelsForPlanType($planType);
        $pricing = Subscription::buildPricingPayload(
            (float) $request->original_price,
            (float) $request->discount_percentage
        );

        // #region agent log
        @file_put_contents(base_path('debug-545283.log'), json_encode(['sessionId' => '545283', 'runId' => 'sub-duration', 'hypothesisId' => 'H-DUR2', 'location' => 'SubscriptionController::update', 'message' => 'update with duration unit', 'data' => ['id' => $subscription->id, 'plan_type' => $planType, 'duration' => (int) $request->duration_months, 'duration_unit' => $request->duration_unit], 'timestamp' => round(microtime(true) * 1000)]) . "\n", FILE_APPEND);
        // #endregion

        $subscription->update(array_merge([
            'name' => Subscription::PLAN_TYPES[$planType],
            'plan_type' => $planType,
            'duration_months' => (int) $request->duration_months,
            'duration_unit' => $request->duration_unit,
            'features' => $features,
            'tax_percent' => 0,
            'is_active' => (int) $request->is_active,
        ], $pricing));

        return redirect()->route('admin.subscriptions.index')->with('success', 'Subscription plan updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Subscription $subscription)
    {
        checkAdminHasPermissionAndThrowException('subscriptions.delete');

        $subscription->delete();
        return redirect()->route('admin.subscriptions.index')->with('success', 'Subscription plan deleted successfully.');
    }

    public function changeStatus($id)
    {
        checkAdminHasPermissionAndThrowException('subscriptions.edit');

        $plan = Subscription::findOrFail($id);
        $plan->is_active = !$plan->is_active;
        $plan->save();

        // #region agent log
        @file_put_contents(base_path('debug-545283.log'), json_encode(['sessionId' => '545283', 'runId' => 'sub-impl', 'hypothesisId' => 'H-SUB3', 'location' => 'SubscriptionController::changeStatus', 'message' => 'subscription status toggled', 'data' => ['id' => $plan->id, 'is_active' => (bool) $plan->is_active], 'timestamp' => round(microtime(true) * 1000)]) . "\n", FILE_APPEND);
        // #endregion

        return response()->json([
            'success' => true,
            'message' => $plan->is_active ? 'Plan activated.' : 'Plan deactivated.',
            'is_active' => (bool) $plan->is_active,
        ]);
    }
}
