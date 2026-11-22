<?php

namespace App\Http\Controllers;

use App\Models\product_warehouse;
use App\Models\Role;
use App\Models\role_user;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserWarehouse;
use App\Models\Warehouse;
use App\Services\Pos\PosApprovalPin;
use App\utils\helpers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Intervention\Image\ImageManagerStatic as Image;

class UserController extends BaseController
{
    /** Staff password policy — same as the installer, password reset and storefront. */
    private static function passwordRule(): \Illuminate\Validation\Rules\Password
    {
        return \Illuminate\Validation\Rules\Password::min(8)->mixedCase()->numbers()->symbols();
    }

    // ------------- GET ALL USERS---------\\

    public function index(request $request)
    {

        $this->authorizeForUser($request->user('api'), 'view', User::class);
        // How many items do you want to display.
        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        // Start displaying items from this number;
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField;
        $dir = strtolower((string) $request->input('SortType')) === 'asc' ? 'asc' : 'desc';
        $helpers = new helpers;
        // Filter fields With Params to retrieve
        $columns = [0 => 'username', 1 => 'statut', 2 => 'phone', 3 => 'email'];
        $param = [0 => 'like', 1 => '=', 2 => 'like', 3 => 'like'];
        $data = [];

        $user = Auth::user();
        // New way: Check user's record_view field (user-level boolean)
        // Backward compatibility: If record_view is null, fall back to role permission check
        $ShowRecord = $user->hasRecordView();

        $users = User::where('deleted_at', '=', null)
            ->where(function ($query) use ($ShowRecord) {
            if (! $ShowRecord) {
                return $query->where('id', '=', Auth::user()->id);
            }
        });

        // Multiple Filter
        $Filtred = $helpers->filter($users, $columns, $param, $request)
        // Search With Multiple Param
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    return $query->where('username', 'LIKE', "%{$request->search}%")
                        ->orWhere('firstname', 'LIKE', "%{$request->search}%")
                        ->orWhere('lastname', 'LIKE', "%{$request->search}%")
                        ->orWhere('email', 'LIKE', "%{$request->search}%")
                        ->orWhere('phone', 'LIKE', "%{$request->search}%");
                });
            });
        $totalRows = $Filtred->count();
        if ($perPage == '-1') {
            $perPage = $totalRows;
        }
        $users = $Filtred->offset($offSet)
            ->limit($perPage)
            ->orderBy($order, $dir)
            ->get();

        $roles = Role::where('deleted_at', null)->get(['id', 'name']);
        $warehouses = Warehouse::where('deleted_at', '=', null)->get(['id', 'name']);

        return response()->json([
            'users' => $users,
            'roles' => $roles,
            'warehouses' => $warehouses,
            'totalRows' => $totalRows,
        ]);
    }

    // ------------- GET USER Auth ---------\\

    public function GetUserAuth(Request $request)
    {
        $helpers = new Helpers;
        $user = Auth::user();
        $settings = Setting::first();
        $branding = $settings ? $settings->effectiveBranding() : ['footer' => '', 'developed_by' => ''];

        $userData = [
            'id' => $user->id,
            'avatar' => $user->avatar,
            'username' => $user->username,
            'currency' => $helpers->Get_Currency(),
            // Multi-Currency module: master toggle + the base currency id, read
            // by document forms/POS to show a currency picker (default off)
            'enable_multi_currency' => (bool) ($settings->enable_multi_currency ?? false),
            // Settings → Features. The customer page reads the first to decide
            // whether to render the performance card; the second is informational
            // for the UI (the backend enforces the scope itself either way).
            'customer_performance_enabled' => (bool) ($settings->customer_performance_enabled ?? true),
            'restrict_master_data_by_warehouse' => (bool) ($settings->restrict_master_data_by_warehouse ?? false),
            'restrict_products_by_warehouse' => (bool) ($settings->restrict_products_by_warehouse ?? false),
            'product_supplier_enabled' => (bool) ($settings->product_supplier_enabled ?? false),
            'default_currency_id' => $settings->currency_id ?? null,
            'logo' => $settings->logo ?? null,
            'default_language' => $settings->default_language ?? 'en',
            'show_language' => $settings->show_language ?? false,
            // Platform branding unless the plan includes white-labeling
            // (remove_branding); the settings forms hide both fields then.
            'footer' => $branding['footer'],
            'developed_by' => $branding['developed_by'],
            'can_remove_branding' => $branding['can_remove_branding'] ?? false,
            'app_name' => $settings->app_name ?? config('app.name'),
            'page_title_suffix' => $settings->page_title_suffix ?? '',
            'customize_button_visible' => (bool) ($settings->customize_button_visible ?? true),
            'hide_site_name' => (bool) ($settings->hide_site_name ?? false),
            // Sidebar brand (System Settings → General) — read by the layout.
            'sidebar_show_logo' => (bool) ($settings->sidebar_show_logo ?? true),
            'sidebar_logo_width' => (int) ($settings->sidebar_logo_width ?: 32),
            'sidebar_logo_height' => (int) ($settings->sidebar_logo_height ?: 32),
            'company' => $settings->CompanyName ?? '',
            'date_format' => $settings->date_format ?? 'YYYY-MM-DD',
            'price_format' => $settings->price_format ?? null,
            // Currency symbol before or after the amount (System Settings)
            'currency_position' => $settings->currency_position ?? 'before',
            // Monetary precision (3 when 3-decimal pricing is enabled, else 2) — read by the SPA at boot
            'price_decimals' => (bool) ($settings->enable_3_decimal_pricing ?? false) ? 3 : 2,
            // Whole-number amounts drop their .00 fraction on screen (System Settings)
            'hide_zero_decimals' => (bool) ($settings->hide_zero_decimals ?? false),
            'dark_mode' => (bool) ($settings->dark_mode ?? false),
            'enable_kitchen_display' => (bool) ($settings->enable_kitchen_display ?? false),
            'timezone' => $settings->timezone ?? 'UTC',
            // System Settings → Dashboard: initial date range the dashboard opens with.
            'default_dashboard_date_range' => in_array($settings->default_dashboard_date_range ?? 'week', ['today', 'week', 'month'], true)
                ? $settings->default_dashboard_date_range
                : 'week',
            // Settings → Modules: {key: bool} map of switched-off business
            // modules (config/modules.js keys). Null = everything enabled.
            'module_flags' => ($settings && $settings->module_flags)
                ? json_decode($settings->module_flags, true)
                : null,
            // System Settings → Export: global list/report export defaults
            // (scope, totals, pdf_orientation, filename_date, pdf_meta) read by
            // lib/exporters.js; null = built-in defaults.
            'export_settings' => ($settings && $settings->export_settings)
                ? json_decode($settings->export_settings, true)
                : null,
            // Role's register discount limit in % (null = no limit); the POS
            // checks it before payment and the server again on save.
            'max_discount_percent' => \App\Services\PosDiscountLimit::limitFor($user),
        ];

        $permissions = $user->roles()->first()?->permissions->pluck('name') ?? [];

        $productsAlerts = product_warehouse::join('products', 'product_warehouse.product_id', '=', 'products.id')
            ->tap(fn ($q) => $this->scopeProductsToWarehouses($q, 'products'))
            ->tap(fn ($q) => $this->scopeStockRowsToOwnership($q))
            ->whereRaw('qte <= stock_alert')
            ->tap(fn ($q) => \App\Services\ComboStockService::excludeVirtualCombos($q))
            ->whereNull('product_warehouse.deleted_at')
            ->whereIn('product_warehouse.warehouse_id', $this->userWarehouseIds())
            ->count();

        return response()->json([
            'success' => true,
            'user' => $userData,
            'notifs' => $productsAlerts,
            'permissions' => $permissions,
        ]);
    }

    // ------------- GET USER ROLES ---------\\

    public function GetUserRole(Request $request)
    {

        $roles = Auth::user()->roles()->with('permissions')->first();

        $data = [];
        if ($roles) {
            foreach ($roles->permissions as $permission) {
                $data[] = $permission->name;

            }

            return response()->json(['success' => true, 'data' => $data]);
        }

    }

    // ------------- STORE NEW USER ---------\\

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', User::class);
        $this->validate($request, [
            'email' => 'required|email|unique:users',
            'password' => ['required', 'string', self::passwordRule()],
            // Only validated when a file is actually sent (see below); a client that
            // echoes the current filename back as a string must keep working.
            'avatar' => $request->hasFile('avatar') ? 'file|image|mimes:jpg,jpeg,png,webp|max:5120' : 'nullable',
        ], [
            'email.unique' => 'This Email already taken.',
        ]);
        // POS manager PIN: refuse a bad one before anything is saved or uploaded.
        if ($request->filled('pos_pin') && PosApprovalPin::ready()) {
            PosApprovalPin::throttleSet($request->user('api'));
            PosApprovalPin::assertValid((string) $request->input('pos_pin'));
        }

        \DB::transaction(function () use ($request) {
            if ($request->hasFile('avatar')) {

                $image = $request->file('avatar');
                $filename = \App\Support\SafeUpload::name(\App\Support\SafeUpload::imageExtension($image, 'avatar'));

                $image_resize = Image::make($image->getRealPath());
                $image_resize->resize(128, 128);
                $avatarDir = upload_public_path('avatar');
                if (! is_dir($avatarDir)) {
                    mkdir($avatarDir, 0755, true);
                }
                $image_resize->save($avatarDir.'/'.$filename);

            } else {
                $filename = 'no_avatar.png';
            }

            if ($request['is_all_warehouses'] == '1' || $request['is_all_warehouses'] == 'true') {
                $is_all_warehouses = 1;
            } else {
                $is_all_warehouses = 0;
            }

            $User = new User;
            $User->firstname = $request['firstname'];
            $User->lastname = $request['lastname'];
            $User->username = $request['username'];
            $User->email = $request['email'];
            // users.phone is NOT NULL and ConvertEmptyStringsToNull turns '' into null
            $User->phone = $request['phone'] ?? '';
            $User->password = Hash::make($request['password']);
            $User->avatar = $filename;
            $User->role_id = $request['role'];
            $User->is_all_warehouses = $is_all_warehouses;
            
            // Set record_view from request (default to false if not provided)
            if (isset($request['record_view'])) {
                $User->record_view = ($request['record_view'] == '1' || $request['record_view'] == 'true' || $request['record_view'] == 1) ? 1 : 0;
            } else {
                $User->record_view = 0;
            }
            
            $User->save();

            // POS manager PIN (write-only; see PosApprovalPin)
            if ($request->filled('pos_pin') && PosApprovalPin::ready()) {
                PosApprovalPin::set($User, (string) $request->input('pos_pin'));
            }

            $role_user = new role_user;
            $role_user->user_id = $User->id;
            $role_user->role_id = $request['role'];
            $role_user->save();

            if (! $User->is_all_warehouses) {
                $User->assignedWarehouses()->sync($request['assigned_to']);
            }

        }, 10);

        return response()->json(['success' => true]);
    }

    // ------------ function show -----------\\

    public function show($id)
    {
        //

    }

    public function edit(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', User::class);

        $user = User::where('deleted_at', '=', null)->findOrFail($id);
        $assigned_warehouses = UserWarehouse::where('user_id', $id)->pluck('warehouse_id')->toArray();
        $warehouses = Warehouse::where('deleted_at', '=', null)->whereIn('id', $assigned_warehouses)->pluck('id')->toArray();
        $roles = Role::where('deleted_at', null)->get(['id', 'name']);
        $all_warehouses = Warehouse::where('deleted_at', '=', null)->get(['id', 'name']);

        return response()->json([
            'user' => $user,
            // The PIN itself is never sent back; the form only shows whether one is set.
            'has_pos_pin' => PosApprovalPin::ready() && ! empty($user->pos_pin_hash),
            'assigned_warehouses' => $warehouses,
            'roles' => $roles,
            'warehouses' => $all_warehouses,
        ]);
    }

    // ------------- UPDATE  USER ---------\\

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', User::class);

        $this->validate($request, [
            'email' => ['required', 'email', Rule::unique('users')->ignore($id)],
            // Only validated when a file is actually sent (see below); a client that
            // echoes the current filename back as a string must keep working.
            'avatar' => $request->hasFile('avatar') ? 'file|image|mimes:jpg,jpeg,png,webp|max:5120' : 'nullable',
        ], [
            'email.unique' => 'This Email already taken.',
        ]);

        // The legacy form sends the string 'null' when the password is left empty.
        if ($request->filled('NewPassword') && $request->NewPassword !== 'null') {
            $this->validate($request, ['NewPassword' => ['string', self::passwordRule()]]);
        }

        // POS manager PIN: refuse a bad one before the avatar is replaced.
        if ($request->filled('pos_pin') && PosApprovalPin::ready()) {
            PosApprovalPin::throttleSet($request->user('api'));
            PosApprovalPin::assertValid((string) $request->input('pos_pin'));
        }

        \DB::transaction(function () use ($id, $request) {
            $user = User::findOrFail($id);
            $current = $user->password;

            // Blank/absent means "keep the current password". The legacy client
            // sent the literal string 'null' when unchanged; the SPA sends an
            // empty field, which ConvertEmptyStringsToNull turns into null —
            // both must NOT be hashed, or the password gets silently wiped.
            if ($request->filled('NewPassword') && $request->NewPassword !== 'null' && $request->NewPassword !== $current) {
                $pass = Hash::make($request->NewPassword);
            } else {
                $pass = $user->password;
            }

            $currentAvatar = $user->avatar;
            // Only treat this as an avatar change when a file was actually
            // uploaded — the SPA omits the field entirely when it is untouched.
            if ($request->hasFile('avatar')) {

                $image = $request->file('avatar');
                $path = upload_public_path('avatar');
                $filename = \App\Support\SafeUpload::name(\App\Support\SafeUpload::imageExtension($image, 'avatar'));

                $image_resize = Image::make($image->getRealPath());
                $image_resize->resize(128, 128);
                $avatarDir = upload_public_path('avatar');
                if (! is_dir($avatarDir)) {
                    mkdir($avatarDir, 0755, true);
                }
                $image_resize->save($avatarDir.'/'.$filename);

                $userPhoto = $path.'/'.$currentAvatar;
                if (file_exists($userPhoto)) {
                    if ($user->avatar != 'no_avatar.png') {
                        @unlink($userPhoto);
                    }
                }
            } else {
                $filename = $currentAvatar;
            }

            if ($request['is_all_warehouses'] == '1' || $request['is_all_warehouses'] == 'true') {
                $is_all_warehouses = 1;
            } else {
                $is_all_warehouses = 0;
            }

            // Set record_view from request (default to false if not provided)
            $record_view = 0;
            if (isset($request['record_view'])) {
                $record_view = ($request['record_view'] == '1' || $request['record_view'] == 'true' || $request['record_view'] == 1) ? 1 : 0;
            }

            User::whereId($id)->update([
                'firstname' => $request['firstname'],
                'lastname' => $request['lastname'],
                'username' => $request['username'],
                'email' => $request['email'],
                'phone' => $request['phone'] ?? '',
                'password' => $pass,
                'record_view' => $record_view,
                'avatar' => $filename,
                'statut' => $request['statut'],
                'is_all_warehouses' => $is_all_warehouses,
                'role_id' => $request['role'],

            ]);

            role_user::where('user_id', $id)->update([
                'user_id' => $id,
                'role_id' => $request['role'],
            ]);

            // Deactivated through the edit form: cut existing logins now, like IsActivated.
            if (isset($request['statut']) && (! $request['statut'] || $request['statut'] === 'false')) {
                \App\Support\Security\RevokeUserAccess::all((int) $id);
            }

            $user_saved = User::where('deleted_at', '=', null)->findOrFail($id);
            $user_saved->assignedWarehouses()->sync($request['assigned_to']);

            // POS manager PIN: a new one replaces the old, remove_pos_pin clears it, blank keeps it.
            if (PosApprovalPin::ready()) {
                if ($request->filled('pos_pin')) {
                    PosApprovalPin::set($user_saved, (string) $request->input('pos_pin'));
                } elseif ($request->input('remove_pos_pin') == '1') {
                    PosApprovalPin::set($user_saved, null);
                }
            }

        }, 10);

        return response()->json(['success' => true]);

    }

    // ------------- UPDATE PROFILE ---------\\

    public function updateProfile(Request $request, $id)
    {

        // Always the signed-in user's own profile: the uniqueness check must
        // ignore THEIR row, not the route id (passing another user's id let
        // you take an e-mail address that is already in use).
        $id = Auth::user()->id;

        $this->validate($request, [
            'firstname' => 'required',
            'lastname' => 'required',
            'username' => 'required',
            'email' => ['required', 'email', Rule::unique('users')->ignore($id)],
            // Only validated when a file is actually sent (see below); a client that
            // echoes the current filename back as a string must keep working.
            'avatar' => $request->hasFile('avatar') ? 'file|image|mimes:jpg,jpeg,png,webp|max:5120' : 'nullable',
            'phone' => 'required',
        ]
        );

        $user = User::findOrFail($id);

        $currentAvatar = $user->avatar;
        if ($request->hasFile('avatar')) {

            $image = $request->file('avatar');
            $path = upload_public_path('avatar');
            $filename = \App\Support\SafeUpload::name(\App\Support\SafeUpload::imageExtension($image, 'avatar'));

            $image_resize = Image::make($image->getRealPath());
            // Phone photos carry an EXIF rotation; apply it so the avatar isn't sideways.
            if (function_exists('exif_read_data')) {
                try { $image_resize->orientate(); } catch (\Throwable $e) {}
            }
            $image_resize->fit(128, 128);
            $avatarDir = upload_public_path('avatar');
            if (! is_dir($avatarDir)) {
                mkdir($avatarDir, 0755, true);
            }
            $image_resize->save($avatarDir.'/'.$filename);

            $userPhoto = $path.'/'.$currentAvatar;

            if (file_exists($userPhoto)) {
                if ($user->avatar != 'no_avatar.png') {
                    @unlink($userPhoto);
                }
            }
        } else {
            $filename = $currentAvatar;
        }

            User::whereId($id)->update([
                'firstname' => $request['firstname'],
                'lastname' => $request['lastname'],
                'username' => $request['username'],
                'email' => $request['email'],
                'phone' => $request['phone'],
                // Password is updated via dedicated endpoint
                'avatar' => $filename,

            ]);

        return response()->json(['avatar' => $filename, 'user' => $request['username']]);

    }

    // ------------- UPDATE PASSWORD (Profile) ---------\\

    public function updatePassword(Request $request)
    {
        $user = Auth::user();

        $this->validate($request, [
            'current_password' => ['required'],
            // No upper cap (max:14 blocked strong passphrases); same rule as user create/reset.
            'new_password' => ['required', 'confirmed', self::passwordRule()],
        ]);

        if (! Hash::check($request->input('current_password'), $user->password)) {
            return response()->json([
                'success' => false,
                'message' => trans('translate.CurrentPasswordIncorrect'),
            ], 422);
        }

        $user->password = Hash::make($request->input('new_password'));
        $user->save();

        return response()->json([
            'success' => true,
            'message' => trans('translate.PasswordUpdated'),
        ]);
    }

    // ----------- IsActivated (Update Statut User) -------\\

    public function IsActivated(request $request, $id)
    {

        $this->authorizeForUser($request->user('api'), 'update', User::class);

        $user = Auth::user();
        // Compare the user actually being updated (route id) — the old check
        // compared the body's id strictly against an int, which never matched,
        // so a user could deactivate themselves.
        if ((int) $id !== (int) $user->id) {
            User::whereId($id)->update([
                'statut' => $request['statut'],
            ]);

            // Deactivation must take effect now, not when old tokens expire.
            if (! $request['statut'] || $request['statut'] === 'false') {
                \App\Support\Security\RevokeUserAccess::all((int) $id);
            }

            return response()->json([
                'success' => true,
            ]);
        } else {
            return response()->json([
                'success' => false,
            ]);
        }
    }

    // ------------- DELETE USER ---------\\
    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', User::class);

        $user = Auth::user();
        
        // Prevent user from deleting their own account
        if ($id == $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot delete your own account.',
            ], 403);
        }

        $userToDelete = User::where('deleted_at', '=', null)->findOrFail($id);

        // Soft delete the user (bypass mass-assignment on deleted_at)
        // and mark them as inactive (statut = 0) so any legacy checks
        // that still rely on statut will treat deleted users as disabled.
        $userToDelete->deleted_at = \Carbon\Carbon::now();
        $userToDelete->statut = 0;
        // Free the POS approval PIN (it is unique) and make sure it cannot approve again.
        if (PosApprovalPin::ready()) {
            $userToDelete->pos_pin_hash = null;
        }
        $userToDelete->save();

        \App\Support\Security\RevokeUserAccess::all((int) $userToDelete->id);

        return response()->json(['success' => true]);
    }

    /**
     * Lightweight users list for select dropdowns (e.g. linking a sales agent to a user).
     * Only requires authentication — no `view User` permission check, since the call
     * site only needs id + display name to render a picker. E-mail addresses
     * are only included for users who may already see the user list.
     */
    public function listForSelect(Request $request)
    {
        $columns = ['id', 'firstname', 'lastname', 'username'];
        if (optional($request->user('api'))->can('view', User::class)) {
            $columns[] = 'email';
        }

        $users = User::where('deleted_at', null)
            ->where('statut', 1)
            ->orderBy('firstname')
            ->orderBy('lastname')
            ->get($columns);

        return response()->json(['users' => $users]);
    }

    public function GetPermissions()
    {
        $roles = Auth::user()->roles()->with('permissions')->first();
        $data = [];
        if ($roles) {
            foreach ($roles->permissions as $permission) {
                $item[$permission->name]['slug'] = $permission->name;
                $item[$permission->name]['id'] = $permission->id;

            }
            $data[] = $item;
        }

        return $data[0];

    }

    // ------------- GET USER Auth ---------\\

    public function GetInfoProfile(Request $request)
    {
        $data = Auth::user();

        return response()->json(['success' => true, 'user' => $data]);
    }

}
