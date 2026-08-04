<?php

namespace App\Services;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InvitationAcceptanceService
{
    public function __construct(
        protected InvitationTenancyService $invitationTenancy
    ) {}

    /**
     * @param  array{first_name: string, paternal_last_name: string, maternal_last_name?: ?string, password?: ?string, google_id?: ?string}  $profile
     */
    public function accept(UserInvitation $invitation, array $profile): User
    {
        $this->invitationTenancy->assertInvitationMatchesPortal($invitation);

        if ($invitation->status !== UserInvitation::STATUS_PENDING || $invitation->isExpired()) {
            throw ValidationException::withMessages([
                'token' => ['Esta invitación no es válida o ha expirado.'],
            ]);
        }

        if (User::where('email', $invitation->email)->exists()) {
            throw ValidationException::withMessages([
                'email' => ['Ya existe una cuenta con este correo.'],
            ]);
        }

        return DB::transaction(function () use ($invitation, $profile) {
            $locked = UserInvitation::where('id', $invitation->id)->lockForUpdate()->first();
            if (! $locked || $locked->status !== UserInvitation::STATUS_PENDING || $locked->isExpired()) {
                throw ValidationException::withMessages([
                    'token' => ['Esta invitación no es válida o ha expirado.'],
                ]);
            }

            $isOperator = $locked->client_id === null;

            $user = User::create([
                'first_name' => $profile['first_name'],
                'paternal_last_name' => $profile['paternal_last_name'],
                'maternal_last_name' => $profile['maternal_last_name'] ?? null,
                'email' => $locked->email,
                'password' => $profile['password'] ?? Str::password(32),
                'google_id' => $profile['google_id'] ?? null,
                'status' => 'pending_admin',
                'client_id' => $locked->client_id,
                'is_operator' => $isOperator,
                'onboarding_completed' => ! $isOperator,
                'site_id' => $this->resolveSedeIdForInvitation($locked),
            ]);

            $user->forceFill(['email_verified_at' => now()])->save();

            if ($locked->role_id) {
                $role = $this->resolveRoleForGuard((int) $locked->role_id);
                if ($role) {
                    // RBAC v2 (Fase 6): syncRoles() necesita team_id resuelto
                    // -- mismo fallback que ApplyPgsqlTenantRls (client_id de
                    // la invitación, o el centinela de plataforma si es un
                    // alta de operador sin client_id, $isOperator arriba).
                    setPermissionsTeamId($locked->client_id ?? config('tenancy.super_admin_team_id'));
                    $user->syncRoles([$role]);
                    User::forgetPermissionCache($user);
                    $user->update(['status' => 'active']);
                }
            }

            $locked->markAccepted();

            return $user;
        });
    }

    public function resolveSedeIdForInvitation(UserInvitation $invitation): int
    {
        if ($invitation->client_id) {
            $sedeId = Site::where('client_id', $invitation->client_id)->value('id');
            if ($sedeId) {
                return (int) $sedeId;
            }
        }

        $remotoId = Site::where('code', 'REMOTO')->value('id');

        return (int) ($remotoId ?? Site::query()->value('id'));
    }

    protected function resolveRoleForGuard(int $roleId): ?Role
    {
        $role = Role::find($roleId);
        if (! $role) {
            return null;
        }

        if ($role->guard_name === 'web') {
            return $role;
        }

        // RBAC v2 (Fase 6, Paso 3): scoped por team_id -- sin esto, buscaría
        // por nombre en TODOS los tenants (no hay global scope de Spatie
        // sobre el modelo Role, confirmado leyendo el paquete).
        return Role::where('team_id', $role->team_id)
            ->where('name', $role->name)
            ->where('guard_name', 'web')
            ->first() ?? $role;
    }
}
