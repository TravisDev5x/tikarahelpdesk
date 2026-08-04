<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\UserInvitation as UserInvitationMail;
use App\Models\Role;
use App\Models\User;
use App\Models\UserInvitation;
use App\Services\InvitationTenancyService;
use App\Services\OperatorScopeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InvitationController extends Controller
{
    public function __construct(
        protected InvitationTenancyService $invitationTenancy,
        protected OperatorScopeService $operatorScope
    ) {}

    public function index(Request $request)
    {
        $actor = Auth::user();
        $query = UserInvitation::with(['role:id,name', 'client:id,name', 'invitedBy:id,name,first_name,paternal_last_name,maternal_last_name'])
            ->orderByDesc('created_at');

        if (! $this->canSeeAllInvitations($actor)) {
            $query->where('invited_by', $actor->id);
        }

        if ($this->operatorScope->resolveOperatorUserId($actor) && ! $this->operatorScope->bypassesOperatorScope($actor)) {
            $operatorId = $this->operatorScope->resolveOperatorUserId($actor);
            $query->where(function ($q) use ($operatorId, $actor) {
                $q->whereNull('client_id')
                    ->orWhereIn('client_id', function ($sub) use ($operatorId) {
                        $sub->select('id')->from('clients')->where('operator_user_id', $operatorId);
                    })
                    ->orWhere('invited_by', $actor->id);
            });
        }

        if ($status = $request->input('status')) {
            if (in_array($status, ['pending', 'accepted', 'expired'], true)) {
                $query->where('status', $status);
            }
        }

        $perPage = min(max((int) $request->input('per_page', 15), 5), 100);
        $paginated = $query->paginate($perPage);

        $paginated->getCollection()->transform(function (UserInvitation $invitation) {
            return [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'role_name' => $invitation->role?->name,
                'client_name' => $invitation->client?->name,
                'status' => $this->effectiveStatus($invitation),
                'expires_at' => $invitation->expires_at?->toIso8601String(),
                'accepted_at' => $invitation->accepted_at?->toIso8601String(),
                'invited_by_name' => $invitation->invitedBy?->name,
                'created_at' => $invitation->created_at?->toIso8601String(),
            ];
        });

        return response()->json($paginated);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email|max:255',
            'role_id' => 'nullable|exists:roles,id,deleted_at,NULL',
            'client_id' => 'nullable|exists:clients,id',
        ]);

        $email = strtolower(trim($validated['email']));
        $actor = Auth::user();

        if (User::where('email', $email)->exists()) {
            throw ValidationException::withMessages([
                'email' => ['Ya existe un usuario con este correo.'],
            ]);
        }

        $hasActivePending = UserInvitation::query()
            ->where('email', $email)
            ->where('status', UserInvitation::STATUS_PENDING)
            ->where('expires_at', '>', now())
            ->exists();

        if ($hasActivePending) {
            throw ValidationException::withMessages([
                'email' => ['Ya hay una invitación pendiente para este correo.'],
            ]);
        }

        $clientId = $this->invitationTenancy->resolveClientIdForCreate(
            $actor,
            isset($validated['client_id']) ? (int) $validated['client_id'] : null
        );

        $role = null;
        if (! empty($validated['role_id'])) {
            $role = $this->resolveRoleForGuard((int) $validated['role_id']);
            if (! $role) {
                throw ValidationException::withMessages([
                    'role_id' => ['Rol incompatible con el guard actual.'],
                ]);
            }
        }

        UserInvitation::query()
            ->where('email', $email)
            ->where('status', UserInvitation::STATUS_PENDING)
            ->update(['status' => UserInvitation::STATUS_EXPIRED]);

        $invitation = UserInvitation::create([
            'email' => $email,
            'token' => (string) Str::uuid(),
            'invited_by' => Auth::id(),
            'client_id' => $clientId,
            'role_id' => $role?->id,
            'status' => UserInvitation::STATUS_PENDING,
            'expires_at' => now()->addHours(72),
        ]);

        $invitation->load(['role', 'client', 'invitedBy']);

        try {
            Mail::to($invitation->email)->send(new UserInvitationMail($invitation));
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json([
            'id' => $invitation->id,
            'email' => $invitation->email,
            'role_name' => $invitation->role?->name,
            'client_name' => $invitation->client?->name,
            'status' => UserInvitation::STATUS_PENDING,
            'expires_at' => $invitation->expires_at->toIso8601String(),
            'message' => 'Invitación enviada correctamente.',
        ], 201);
    }

    public function destroy(UserInvitation $invitation)
    {
        if ($invitation->status !== UserInvitation::STATUS_PENDING) {
            return response()->json(['message' => 'Solo se pueden cancelar invitaciones pendientes.'], 422);
        }

        $invitation->update(['status' => UserInvitation::STATUS_EXPIRED]);

        return response()->json(['message' => 'Invitación cancelada.']);
    }

    public function resend(UserInvitation $invitation)
    {
        if ($invitation->status === UserInvitation::STATUS_ACCEPTED) {
            return response()->json(['message' => 'La invitación ya fue aceptada.'], 422);
        }

        $invitation->update([
            'token' => (string) Str::uuid(),
            'expires_at' => now()->addHours(72),
            'status' => UserInvitation::STATUS_PENDING,
            'accepted_at' => null,
        ]);

        $invitation->load(['role', 'client', 'invitedBy']);

        try {
            Mail::to($invitation->email)->send(new UserInvitationMail($invitation));
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'No se pudo enviar el correo. Intenta más tarde.'], 500);
        }

        return response()->json([
            'message' => 'Invitación reenviada.',
            'expires_at' => $invitation->expires_at->toIso8601String(),
        ]);
    }

    protected function canSeeAllInvitations(User $user): bool
    {
        return $user->hasRole('admin');
    }

    protected function effectiveStatus(UserInvitation $invitation): string
    {
        if ($invitation->status === UserInvitation::STATUS_PENDING && $invitation->isExpired()) {
            return UserInvitation::STATUS_EXPIRED;
        }

        return $invitation->status;
    }

    protected function resolveRoleForGuard(int $roleId): ?Role
    {
        $role = Role::find($roleId);
        if (! $role) {
            return null;
        }

        $allowedGuards = ['web', 'sanctum'];
        if (! in_array($role->guard_name, $allowedGuards, true)) {
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
}
