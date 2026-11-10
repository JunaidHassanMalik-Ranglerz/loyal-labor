<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Traits\GlobalMailTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Yajra\DataTables\Facades\DataTables;

class UserController extends Controller
{
    use GlobalMailTrait;

    public function index(Request $request)
    {
        checkAdminHasPermissionAndThrowException('users.view');

        $query = User::query()->latest();

        if ($request->filled('type')) {
            if ($request->type === 'user') {
                $query->where('user_type', 'customer');
            } elseif ($request->type === 'technician') {
                $query->where('user_type', 'technician');
            }
        }

        if ($request->ajax() || $request->has('draw') || $request->has('start')) {
            return DataTables::of($query)
                ->editColumn('name', function ($user) {
                    return e($user->name);
                })
                ->editColumn('user_type', function ($user) {
                    return $user->user_type === 'customer' ? 'User' : ucfirst($user->user_type);
                })
                ->addColumn('status_badge', function ($user) {
                    $displayStatus = $user->status;
                    $badgeColor = 'warning';

                    if ($user->user_type === 'customer' && !$user->is_verified) {
                        $displayStatus = 'pending';
                        $badgeColor = 'warning';
                    } elseif ($user->status === 'active') {
                        $badgeColor = 'success';
                    } elseif ($user->status === 'inactive') {
                        $badgeColor = 'danger';
                    } elseif ($user->status === 'pending') {
                        $badgeColor = 'warning';
                    }

                    $html = '<span class="badge badge-' . $badgeColor . '">' . ucfirst($displayStatus) . '</span>';

                    if (checkAdminHasPermission('users.edit')) {
                        $checked = $user->status === 'active' ? 'checked' : '';
                        $html .= '<div class="mt-1"><input onchange="changeUserStatus(' . $user->id . ')" id="status_toggle_' . $user->id . '" type="checkbox" ' . $checked . ' data-toggle="toggle" data-onlabel="' . __('Active') . '" data-offlabel="' . __('Inactive') . '" data-onstyle="success" data-offstyle="danger" data-size="small"></div>';
                    }

                    if ($user->user_type === 'technician') {
                        try {
                            if ($user->allDocumentsVerified()) {
                                $html .= ' <span class="badge badge-info">Docs OK</span>';
                            }
                        } catch (\Throwable $e) {
                            // Skip if verification columns missing on server
                        }
                    }

                    return $html;
                })
                ->addColumn('action', function ($user) {
                    $html = '';

                    if (checkAdminHasPermission('users.view')) {
                        $html .= '<a href="' . route('admin.users.show', $user->id) . '" class="btn btn-info btn-sm"><i class="fa fa-eye"></i></a> ';
                    }

                    if (checkAdminHasPermission('users.delete')) {
                        $html .= '<a class="btn btn-danger btn-sm deleteForm" href="javascript:;" data-url="' . route('admin.users.destroy', $user->id) . '"><i class="fa fa-trash"></i></a>';
                    }

                    return $html;
                })
                ->rawColumns(['status_badge', 'action'])
                ->make(true);
        }

        return view('admin.users.index');
    }

    public function create()
    {
        checkAdminHasPermissionAndThrowException('users.create');

        return view('admin.users.create');
    }

    public function store(Request $request)
    {
        checkAdminHasPermissionAndThrowException('users.create');

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|min:6',
            'user_type' => 'required|in:customer,technician'
        ]);

        User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'user_type' => $request->user_type,
            'status' => 'active',
            'is_verified' => 1
        ]);

        return redirect()->route('admin.users.index')->with('success', 'User created successfully.');
    }

    public function edit($id)
    {
        checkAdminHasPermissionAndThrowException('users.edit');

        $user = User::find($id);

        if (!$user) {
            return redirect()->route('admin.users.index')->with('error', 'User not found.');
        }

        return view('admin.users.edit', compact('user'));
    }

    public function update(Request $request, $id)
    {
        checkAdminHasPermissionAndThrowException('users.edit');

        $user = User::find($id);

        if (!$user) {
            return redirect()->route('admin.users.index')->with('error', 'User not found.');
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . $user->id,
            'user_type' => 'required|in:customer,technician'
        ]);

        $data = [
            'name' => $request->name,
            'email' => $request->email,
            'user_type' => $request->user_type,
        ];

        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }

        $user->update($data);

        return redirect()->route('admin.users.index')->with('success', 'User updated successfully.');
    }

    public function destroy($id)
    {
        checkAdminHasPermissionAndThrowException('users.delete');

        $user = User::find($id);

        if (!$user) {
            return redirect()->route('admin.users.index')->with('error', 'User not found.');
        }

        $user->delete();
        return redirect()->route('admin.users.index')->with('success', 'User deleted successfully.');
    }

    public function show($id)
    {
        checkAdminHasPermissionAndThrowException('users.view');

        $user = User::find($id);

        if (!$user) {
            return redirect()->route('admin.users.index')->with('error', 'User not found.');
        }

        if ($user->user_type === 'technician') {
            $user->load(['availabilities', 'subscriptionPlan']);
        }

        return view('admin.users.show', compact('user'));
    }

    public function verifyDocument(Request $request, User $user)
    {
        checkAdminHasPermissionAndThrowException('users.edit');

        $request->validate([
            'field' => 'required|in:cnic_front,cnic_back,photo,certificates',
        ]);

        if ($user->user_type !== 'technician') {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => 'Only technician documents can be verified.'], 422);
            }

            return redirect()->back()->with('error', 'Only technician documents can be verified.');
        }

        $wasPending = $user->status !== 'active';
        $column = $request->field . '_verified';
        $user->update([$column => true]);
        $user->fresh()->syncActivationStatus();

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Verified successfully.',
                'status_updated' => $wasPending && $user->fresh()->status === 'active',
            ]);
        }

        return redirect()
            ->route('admin.users.show', $user)
            ->with('success', 'Verified successfully.');
    }

    public function verifyPayment(Request $request, User $user)
    {
        checkAdminHasPermissionAndThrowException('users.edit');

        if ($user->user_type !== 'technician') {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => 'Only technician payments can be verified.'], 422);
            }

            return redirect()->back()->with('error', 'Only technician payments can be verified.');
        }

        $user->update([
            'payment_status' => 'verified',
            'subscription' => 'active',
        ]);
        $user->fresh()->syncActivationStatus();

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Verified successfully.',
            ]);
        }

        return redirect()
            ->route('admin.users.show', $user)
            ->with('success', 'Verified successfully.');
    }

	public function verifyEmail(Request $request, User $user)
{
    checkAdminHasPermissionAndThrowException('users.edit');

    try {
        // Check if already verified
        if ($user->is_verified == 1) {
            return response()->json([
                'success' => false,
                'message' => 'Email is already verified!'
            ]);
        }
        
        // Update is_verified to 1
        $user->is_verified = 1;
        $user->save();
        
        return response()->json([
            'success' => true,
            'message' => 'Verified successfully.',
        ]);
        
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Failed to verify email: ' . $e->getMessage()
        ]);
    }
}

    public function changeStatus($id)
    {
        checkAdminHasPermissionAndThrowException('users.edit');

        $user = User::findOrFail($id);
        $user->status = $user->status === 'active' ? 'inactive' : 'active';
        $user->save();

        if ($user->status === 'inactive' && !empty($user->email)) {
            try {
                $this->sendAccountDeactivatedMail($user);
            } catch (\Exception $e) {
                Log::error('Account deactivation email failed: ' . $e->getMessage());
            }
        }

        return response()->json([
            'success' => true,
            'message' => $user->status === 'active' ? 'User activated.' : 'User deactivated.',
            'status' => $user->status,
        ]);
    }

    private function sendAccountDeactivatedMail(User $user): void
    {
        $appName = loyalBrandName();
        $subject = $appName . ' - Account Deactivated';
        $message = '<p style="margin:0 0 12px;">Dear ' . e($user->name) . ',</p>'
            . '<p style="margin:0 0 12px;">Your account has been deactivated by the admin.</p>'
            . '<p style="margin:0;">For further information, please contact the admin.</p>';

        $this->sendMail($user->email, $subject, $message, [], true);
    }
}
