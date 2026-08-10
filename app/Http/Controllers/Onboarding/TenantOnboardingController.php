<?php

namespace App\Http\Controllers\Onboarding;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use App\Services\TenantContextService;
use App\Support\Tenancy\PgsqlRowLevelSecurity;
use Database\Seeders\TenantRoleSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Fase 7 (onboarding de tenant nuevo, 2026-08-09): reemplaza por completo al
 * wizard legacy de operador (OperatorOnboardingController, retirado en esta
 * misma fase). Un Client siempre tiene portal_slug real desde su creación,
 * mode poblado (por ahora fijo en 'internal' -- 7.4 lo hace elegible de
 * verdad) y su Customer implícito vía el hook de Client::booted(), no un
 * flujo aparte.
 *
 * Solo cubre el sub-paso 7.2 (nombre del tenant) en este sprint -- 7.1
 * (registro + consentimiento LFPDPPP) ya vive en AuthController::register(),
 * no aquí. 7.3 en adelante (datos de empresa, modalidad real, Customers/
 * Sites externos, invitaciones, equipos) queda para la siguiente fase;
 * Client.onboarding_step queda en 'tenant_named' al completar este
 * controlador, listo para que esa fase continúe la máquina de estados.
 */
class TenantOnboardingController extends Controller
{
    public function show(): Response|\Illuminate\Http\RedirectResponse
    {
        $user = Auth::user();

        if ($user->client_id !== null) {
            return redirect('/home');
        }

        return Inertia::render('Onboarding/TenantName', [
            'user_name' => trim($user->first_name.' '.$user->paternal_last_name),
        ]);
    }

    public function store(Request $request)
    {
        $user = Auth::user();

        if ($user->client_id !== null) {
            return redirect('/home');
        }

        $validated = $request->validate([
            'business_name' => [
                'required',
                'string',
                'max:255',
                function ($attribute, $value, $fail) {
                    $slug = Str::slug($value);
                    if ($slug === '' || in_array($slug, config('tenancy.reserved_subdomains', []), true)) {
                        $fail('Ese nombre no está disponible, elige otro.');
                    }
                },
            ],
        ]);

        DB::transaction(function () use ($user, $validated) {
            $client = Client::create([
                'name' => $validated['business_name'],
                'portal_slug' => TenantContextService::generateUniquePortalSlug($validated['business_name']),
                // mode: default 'internal' desde la migración -- 7.4 lo captura de
                // verdad (internal/msp/hybrid); no se decide en este sub-paso.
                'onboarding_step' => 'tenant_named',
                'is_active' => true,
            ]);
            // ticket_prefix (TicketPrefixService) y el Customer implícito
            // (InternalCustomerService::ensureForClient, con su propio
            // manejo acotado de RLS) ya quedaron asignados automáticamente
            // por Client::booted(), sin llamada explícita aquí.

            $user->update([
                'client_id' => $client->id,
                'onboarding_completed' => true,
            ]);

            if (PgsqlRowLevelSecurity::enabled()) {
                // Recalcula las variables de sesión RLS con el client_id que
                // el usuario acaba de recibir -- por si algo más adelante en
                // el mismo request toca una tabla con RLS (sites, tickets...).
                // ApplyPgsqlTenantRls las había fijado al entrar sin tenant.
                PgsqlRowLevelSecurity::applyForUser($user->fresh());
            }

            $this->assignFoundingAdminRole($user, $client);
        });

        return redirect('/home')->with('success', '¡Tu empresa está lista!');
    }

    /**
     * Siembra las plantillas por defecto del tenant y promueve al usuario
     * fundador a 'admin' -- resolviendo el Role EXACTO por team_id, nunca
     * por nombre a secas (Role::findByName()/assignRole('nombre') ignoran
     * el team activo, documentado en docs/PENDING.md, mordió 2 veces).
     *
     * También limpia el placeholder de bootstrap que AuthController::verifyEmail()
     * le asignó en el team_id centinela (config('tenancy.super_admin_team_id'))
     * cuando el usuario todavía no tenía Client -- ver comentario ahí mismo.
     * Sin este detach, el usuario terminaría con DOS roles 'admin' en dos
     * teams distintos (el centinela y el real), reabriendo el mismo riesgo
     * de ambigüedad que este re-scoping existe para cerrar.
     */
    private function assignFoundingAdminRole(User $user, Client $client): void
    {
        DB::table('model_has_roles')
            ->where('model_id', $user->id)
            ->where('model_type', User::class)
            ->where('team_id', config('tenancy.super_admin_team_id'))
            ->delete();

        setPermissionsTeamId($client->id);
        (new TenantRoleSeeder)->run();

        $adminRole = Role::where('name', 'admin')
            ->where('team_id', $client->id)
            ->where('guard_name', 'web')
            ->firstOrFail();

        $user->syncRoles([$adminRole]);
        User::forgetPermissionCache($user);
    }
}
