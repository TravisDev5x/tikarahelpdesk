<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\VerifyEmail;
use App\Models\User;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use App\Services\ClientScopeService;

class UserController extends Controller
{
    public function __construct(
        protected ClientScopeService $clientScope
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = User::with(['campaign', 'area', 'position', 'site', 'location', 'roles']);

        $actor = Auth::user();
        if ($actor) {
            $this->clientScope->applyUserScope($query, $actor);
        }

        if ($request->input('status') === 'only') {
            $query->onlyTrashed();
        } else {
            if ($request->filled('user_status') && in_array($request->input('user_status'), ['active', 'pending_admin', 'pending_email', 'blocked'], true)) {
                $query->where('status', $request->input('user_status'));
            }
            if ($request->filled('blacklist')) {
                if ($request->input('blacklist') === '1' || $request->input('blacklist') === 'yes') {
                    $query->where('is_blacklisted', true);
                }
                if ($request->input('blacklist') === '0' || $request->input('blacklist') === 'no') {
                    $query->where('is_blacklisted', false);
                }
            }
        }

        if ($search = $request->input('search')) {
            $term = '%' . trim($search) . '%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                    ->orWhere('first_name', 'like', $term)
                    ->orWhere('paternal_last_name', 'like', $term)
                    ->orWhere('maternal_last_name', 'like', $term)
                    ->orWhere('employee_number', 'like', $term)
                    ->orWhere('email', 'like', $term);
            });
        }

        if ($request->filled('campaign')) {
            $campaignId = \App\Models\Campaign::where('name', $request->input('campaign'))->value('id');
            if ($campaignId !== null) {
                $query->where('campaign_id', $campaignId);
            }
        }
        if ($request->filled('area')) {
            $areaId = \App\Models\Area::where('name', $request->input('area'))->value('id');
            if ($areaId !== null) {
                $query->where('area_id', $areaId);
            }
        }
        if ($request->filled('site')) {
            $siteId = \App\Models\Site::where('name', $request->input('site'))->value('id');
            if ($siteId !== null) {
                $query->where('site_id', $siteId);
            }
        }
        if ($request->filled('location')) {
            $locationId = \App\Models\Location::where('name', $request->input('location'))->value('id');
            if ($locationId !== null) {
                $query->where('location_id', $locationId);
            }
        }
        if ($request->filled('role_id')) {
            $roleId = (int) $request->input('role_id');
            if ($roleId > 0) {
                $query->whereHas('roles', fn ($q) => $q->where('roles.id', $roleId));
            }
        }

        $sortable = ['id', 'name', 'employee_number', 'email', 'status', 'created_at'];
        $sort = $request->input('sort', 'id');
        $direction = $request->input('direction', 'desc') === 'asc' ? 'asc' : 'desc';
        if (!in_array($sort, $sortable, true)) {
            $sort = 'id';
        }

        $perPage = (int) $request->input('per_page', 10);
        if ($perPage < 5) $perPage = 5;
        if ($perPage > 100) $perPage = 100;

        $users = $query->orderBy($sort, $direction)
            ->paginate($perPage)
            ->through(function ($user) {
                return [
                    'id' => $user->id,
                    'employee_number' => $user->employee_number,
                    'name' => $user->name,
                    'first_name' => $user->first_name,
                    'paternal_last_name' => $user->paternal_last_name,
                    'maternal_last_name' => $user->maternal_last_name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'campaign' => $user->campaign->name ?? 'Sin Asignar',
                    'area' => $user->area->name ?? 'Sin Asignar',
                    'position' => $user->position->name ?? 'Sin Asignar',
                    'site' => $user->site->name ?? 'Sin Asignar',
                    'site_type' => $user->site->type ?? null,
                    'location' => $user->location->name ?? null,
                    'status' => $user->status,
                    'is_blacklisted' => $user->is_blacklisted,
                    'roles' => $user->roles->map(fn ($r) => ['id' => $r->id, 'name' => $r->name]),
                    'deleted_at' => $user->deleted_at,
                ];
            });

        return response()->json($users);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'paternal_last_name' => 'required|string|max:255',
            'maternal_last_name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255|unique:users,email',
            'employee_number' => 'nullable|unique:users,employee_number',
            'phone' => 'nullable|string|size:10|regex:/^\d{10}$/',
            'role_id' => 'required|exists:roles,id,deleted_at,NULL',
            'password' => [
                'required',
                'string',
                'min:12',
                'regex:/[a-z]/',
                'regex:/[A-Z]/',
                'regex:/[0-9]/',
                'regex:/[^A-Za-z0-9]/',
            ],
            'campaign' => 'required|exists:campaigns,name',
            'area' => 'required|exists:areas,name',
            'position' => 'required|exists:positions,name',
            'site' => 'nullable|exists:sites,name',
            'location' => 'nullable|exists:locations,name',
        ]);

        $campaignId = \App\Models\Campaign::where('name', $request->campaign)->first()->id;
        $areaId = \App\Models\Area::where('name', $request->area)->first()->id;
        $positionId = \App\Models\Position::where('name', $request->position)->first()->id;
        $siteId = $request->filled('site')
            ? \App\Models\Site::where('name', $request->site)->first()->id
            : \App\Models\Site::where('code', 'REMOTO')->value('id');
        $actor = Auth::user();
        if ($actor && ! $this->clientScope->assertSiteAccessible($actor, (int) $siteId)) {
            return response()->json(['message' => 'La sede no pertenece a tu cliente'], 422);
        }
        $clientId = $siteId ? \App\Models\Site::where('id', $siteId)->value('client_id') : null;
        $locationId = null;
        if ($request->filled('location')) {
            $locationId = \App\Models\Location::where('name', $request->location)
                ->where('site_id', $siteId)
                ->value('id');
            if (!$locationId) {
                return response()->json(['message' => 'Ubicación no pertenece a la sede seleccionada'], 422);
            }
        }

        $role = null;
        if (!empty($validated['role_id'])) {
            $role = $this->resolveRoleForGuard((int) $validated['role_id']);
            if (!$role) {
                return response()->json([
                    'message' => 'Rol incompatible con el guard actual',
                ], 422);
            }
        }

        $user = User::create([
            'first_name' => $request->first_name,
            'paternal_last_name' => $request->paternal_last_name,
            'maternal_last_name' => $request->maternal_last_name,
            'email' => $request->filled('email') ? $request->email : null,
            'employee_number' => $request->filled('employee_number') ? $request->employee_number : null,
            'phone' => $request->filled('phone') ? $request->phone : null,
            'password' => Hash::make($request->password),
            'campaign_id' => $campaignId,
            'area_id' => $areaId,
            'position_id' => $positionId,
            'site_id' => $siteId,
            'client_id' => $clientId ? (int) $clientId : null,
            'location_id' => $locationId,
        ]);

        if ($role) {
            $user->syncRoles([$role]);
            User::forgetPermissionCache($user);
        }

        return response()->json($user, 201);
    }

    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'paternal_last_name' => 'required|string|max:255',
            'maternal_last_name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255|unique:users,email,' . $user->id,
            'employee_number' => 'nullable|unique:users,employee_number,' . $user->id,
            'phone' => 'nullable|string|size:10|regex:/^\d{10}$/',
            'role_id' => 'required|exists:roles,id,deleted_at,NULL',
            'status' => 'sometimes|in:pending_email,pending_admin,active,blocked',
            'password' => [
                'nullable',
                'string',
                'min:12',
                'regex:/[a-z]/',
                'regex:/[A-Z]/',
                'regex:/[0-9]/',
                'regex:/[^A-Za-z0-9]/',
            ],
            'campaign' => 'required|exists:campaigns,name',
            'area' => 'required|exists:areas,name',
            'position' => 'required|exists:positions,name',
            'site' => 'nullable|exists:sites,name',
            'location' => 'nullable|exists:locations,name',
        ]);

        if ($request->has('campaign')) {
            $user->campaign_id = \App\Models\Campaign::where('name', $request->campaign)->first()->id;
        }
        if ($request->has('area')) {
            $user->area_id = \App\Models\Area::where('name', $request->area)->first()->id;
        }
        if ($request->has('position')) {
            $user->position_id = \App\Models\Position::where('name', $request->position)->first()->id;
        }
        if ($request->has('site')) {
            $newSiteId = \App\Models\Site::where('name', $request->site)->first()->id;
            $actor = Auth::user();
            if ($actor && ! $this->clientScope->assertSiteAccessible($actor, (int) $newSiteId)) {
                return response()->json(['message' => 'La sede no pertenece a tu cliente'], 422);
            }
            $user->site_id = $newSiteId;
        }
        if ($request->has('location')) {
            $location = \App\Models\Location::where('name', $request->location)->first();
            if ($location && $user->site_id && $location->site_id !== $user->site_id) {
                return response()->json(['message' => 'Ubicación no pertenece a la sede seleccionada'], 422);
            }
            $user->location_id = $location?->id;
        }

        $originalEmail = $user->email;

        $user->fill($request->except(['campaign', 'area', 'position', 'site', 'site_id', 'location', 'location_id', 'password', 'role_id', 'name']));
        $user->email = $request->filled('email') ? $request->email : null;
        $user->phone = $request->filled('phone') ? $request->phone : null;

        if ($request->filled('password')) {
            $user->password = Hash::make($request->password);
        }

        $emailChanged = $request->has('email') && $request->email !== $originalEmail;

        $role = null;
        if ($request->filled('role_id')) {
            $role = $this->resolveRoleForGuard((int) $request->role_id);
            if (!$role) {
                return response()->json([
                    'message' => 'Rol incompatible con el guard actual',
                ], 422);
            }
        }

        $user->save();

        if ($request->filled('role_id')) {
            $user->syncRoles($role ? [$role] : []);
            User::forgetPermissionCache($user);
        }

        // Activar solo si tiene un rol distinto de visitante (visitante es solo lectura hasta que admin asigne rol)
        if ($user->status === 'pending_admin' && $user->roles()->count() > 0 && !($user->roles()->count() === 1 && $user->hasRole('visitante'))) {
            $user->update(['status' => 'active']);
        }

        if ($emailChanged && $user->email) {
            $user->email_verified_at = null;
            $user->status = 'pending_email';
            $user->save();

            $token = Str::uuid()->toString();
            DB::table('email_verification_tokens')->insert([
                'user_id' => $user->id,
                'token' => $token,
                'expires_at' => now()->addHours(24),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $url = url("/verify-email?token={$token}");
            try {
                Mail::to($user->email)->send(new VerifyEmail($url));
            } catch (\Throwable $e) {
                // Si falla el correo, el usuario queda en pending_email
            }
        }

        return response()->json(['message' => 'Usuario actualizado', 'user' => $user]);
    }

    /**
     * Resuelve el rol por ID aceptando guards web y sanctum; devuelve la versión con guard 'web' si existe.
     */
    protected function resolveRoleForGuard(int $roleId): ?Role
    {
        $role = Role::find($roleId);
        if (!$role) {
            return null;
        }

        $allowedGuards = ['web', 'sanctum'];
        if (!in_array($role->guard_name, $allowedGuards, true)) {
            return null;
        }

        if ($role->guard_name === 'web') {
            return $role;
        }

        // RBAC v2 (Fase 6, Paso 3): scoped por team_id, ver mismo fix en
        // InvitationAcceptanceService::resolveRoleForGuard().
        return Role::where('team_id', $role->team_id)
            ->where('name', $role->name)
            ->where('guard_name', 'web')
            ->first() ?? $role;
    }

    /**
     * Baja técnica (SoftDelete).
     */
    public function destroy(Request $request, User $user)
    {
        $actor = Auth::user();
        if ($actor && ! $this->clientScope->assertUserAccessible($actor, $user->id)) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $reason = $request->input('reason');
        if (is_string($reason) && strlen(trim($reason)) >= 5) {
            $user->update(['deletion_reason' => trim($reason)]);
        }

        $user->delete();
        return response()->json(['message' => 'Usuario eliminado']);
    }

    /**
     * Baja técnica masiva (SoftDelete).
     */
    public function massDestroy(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:users,id',
            'reason' => 'required|string|min:5',
        ]);

        $actor = Auth::user();
        $scopedQuery = User::whereIn('id', $request->ids);
        if ($actor) {
            $this->clientScope->applyUserScope($scopedQuery, $actor);
        }
        $accessibleIds = $scopedQuery->pluck('id')->toArray();

        if (empty($accessibleIds)) {
            return response()->json(['message' => 'No se encontraron usuarios válidos para eliminar'], 422);
        }

        User::whereIn('id', $accessibleIds)->update(['deletion_reason' => $request->reason]);
        User::whereIn('id', $accessibleIds)->delete();

        return response()->json(['message' => 'Usuarios eliminados correctamente']);
    }

    public function restore($id)
    {
        $user = User::onlyTrashed()->findOrFail($id);
        $actor = Auth::user();
        if ($actor && ! $this->clientScope->assertUserAccessible($actor, $user->id)) {
            return response()->json(['message' => 'No autorizado'], 403);
        }
        $user->restore();
        return response()->json(['message' => 'Usuario restaurado']);
    }

    public function forceDelete($id)
    {
        $user = User::onlyTrashed()->findOrFail($id);
        $actor = Auth::user();
        if ($actor && ! $this->clientScope->assertUserAccessible($actor, $user->id)) {
            return response()->json(['message' => 'No autorizado'], 403);
        }
        $user->forceDelete();
        return response()->json(['message' => 'Usuario eliminado permanentemente']);
    }

    /**
     * Alternar lista negra (vetar / quitar veto). Solo usuarios activos (no eliminados).
     */
    public function toggleBlacklist(Request $request, User $user)
    {
        $actor = Auth::user();
        if ($actor && ! $this->clientScope->assertUserAccessible($actor, $user->id)) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $request->validate([
            'blacklist' => 'required|boolean',
        ]);

        $user->update(['is_blacklisted' => $request->boolean('blacklist')]);
        $message = $request->boolean('blacklist')
            ? 'Usuario agregado a lista negra (vetado).'
            : 'Usuario quitado de lista negra.';

        return response()->json(['message' => $message, 'is_blacklisted' => $user->is_blacklisted]);
    }
}
