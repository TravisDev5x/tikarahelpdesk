<?php

namespace App\Http\Controllers\Onboarding;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\InvitationController;
use App\Models\Client;
use App\Models\Customer;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\UserInvitation;
use App\Services\OnboardingRedirectService;
use App\Services\TenantContextService;
use App\Support\Tenancy\PgsqlRowLevelSecurity;
use Database\Seeders\TenantRoleSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Fase 7 (onboarding de tenant nuevo): reemplaza por completo al wizard
 * legacy de operador (OperatorOnboardingController, retirado en 7.1-7.2).
 * Un Client siempre tiene portal_slug real desde su creación (7.2) y su
 * Customer implícito vía el hook de Client::booted(), no un flujo aparte.
 *
 * Este sprint agrega 7.6 (invitar personal, reusando InvitationController/
 * InvitationAcceptanceService tal cual -- sin reimplementar su lógica). 7.7
 * (equipos/sites/horarios) y 7.8 (TenantWelcomeMail) quedan para el
 * siguiente sprint.
 *
 * Máquina de estados (Client.onboarding_step), en orden:
 *   tenant_named -> company_data -> modality_set -> customers_added
 *                                                 -> customers_skipped (si mode=internal)
 *                                                 -> staff_invited
 * OnboardingRedirectService::redirectPath() es la ÚNICA fuente de verdad
 * de "a qué paso le toca ir" -- cada show() de aquí abajo se lo pregunta
 * en vez de reimplementar la máquina de estados.
 */
class TenantOnboardingController extends Controller
{
    public function __construct(protected OnboardingRedirectService $onboarding) {}

    public function show(): Response|RedirectResponse
    {
        $user = Auth::user();

        if ($user->client_id !== null) {
            return redirect($this->onboarding->redirectPath($user) ?? '/home');
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

            $user->update(['client_id' => $client->id]);
            // onboarding_completed se queda en false -- 7.3/7.4(/7.5) siguen
            // pendientes, a diferencia de cuando este era el último paso
            // construido.

            if (PgsqlRowLevelSecurity::enabled()) {
                // Recalcula las variables de sesión RLS con el client_id que
                // el usuario acaba de recibir -- por si algo más adelante en
                // el mismo request toca una tabla con RLS (sites, tickets...).
                // ApplyPgsqlTenantRls las había fijado al entrar sin tenant.
                PgsqlRowLevelSecurity::applyForUser($user->fresh());
            }

            $this->assignFoundingAdminRole($user, $client);
        });

        return redirect('/onboarding/company');
    }

    // ── 7.3: datos de empresa ────────────────────────────────────────────

    public function showCompany(): Response|RedirectResponse
    {
        $user = Auth::user();
        if ($redirect = $this->guardStep($user, '/onboarding/company')) {
            return $redirect;
        }

        return Inertia::render('Onboarding/CompanyData', [
            'client' => Client::findOrFail($user->client_id)
                ->only(['legal_name', 'tax_id', 'contact_phone', 'website', 'address', 'city', 'country']),
        ]);
    }

    public function storeCompany(Request $request)
    {
        $user = Auth::user();
        if ($redirect = $this->guardStep($user, '/onboarding/company')) {
            return $redirect;
        }

        $validated = $request->validate([
            'legal_name' => 'nullable|string|max:255',
            'tax_id' => ['nullable', 'string', 'min:12', 'max:13', 'regex:/^[A-Z&Ñ]{3,4}[0-9]{6}[A-Z0-9]{3}$/'],
            'contact_phone' => 'nullable|string|size:10|regex:/^\d{10}$/',
            'website' => 'nullable|url|max:255',
            'address' => 'required|string|max:500',
            'city' => 'required|string|max:255',
            'country' => 'required|string|size:2',
        ]);

        $client = Client::findOrFail($user->client_id);
        $client->update([
            ...$validated,
            'onboarding_step' => 'company_data',
        ]);

        return redirect('/onboarding/modality');
    }

    // ── 7.4: modalidad ───────────────────────────────────────────────────

    public function showModality(): Response|RedirectResponse
    {
        $user = Auth::user();
        if ($redirect = $this->guardStep($user, '/onboarding/modality')) {
            return $redirect;
        }

        return Inertia::render('Onboarding/Modality');
    }

    public function storeModality(Request $request)
    {
        $user = Auth::user();
        if ($redirect = $this->guardStep($user, '/onboarding/modality')) {
            return $redirect;
        }

        $validated = $request->validate([
            'mode' => 'required|in:internal,msp,hybrid',
        ]);

        $client = Client::findOrFail($user->client_id);
        $client->update([
            'mode' => $validated['mode'],
            'onboarding_step' => 'modality_set',
        ]);

        // internal: no hay Customers externos que dar de alta -- 7.5 no
        // aplica, salta directo a 7.6 (invitar personal). msp/hybrid: sigue
        // a 7.5 primero.
        if ($validated['mode'] === 'internal') {
            $client->update(['onboarding_step' => 'customers_skipped']);

            return redirect('/onboarding/staff');
        }

        return redirect('/onboarding/customers');
    }

    // ── 7.5: Customers/Sites externos (solo MSP/Hybrid) ─────────────────

    public function showCustomers(): Response|RedirectResponse
    {
        $user = Auth::user();
        if ($redirect = $this->guardStep($user, '/onboarding/customers')) {
            return $redirect;
        }

        $client = Client::findOrFail($user->client_id);

        return Inertia::render('Onboarding/Customers', [
            'customers' => Customer::where('client_id', $client->id)
                ->where('is_internal', false)
                ->with('sites:id,customer_id,name,address')
                ->get(['id', 'name']),
        ]);
    }

    public function storeCustomer(Request $request)
    {
        $user = Auth::user();
        if ($redirect = $this->guardStep($user, '/onboarding/customers')) {
            return $redirect;
        }

        // sites tiene unique(client_id, name) -- TENANT completo, no por
        // Customer (2026_07_11_000005_scope_sites_name_unique_per_client.php).
        // Con varios Customers externos bajo el mismo tenant es realista que
        // dos quieran una sede "Matriz"/"Oficina Central" -- se valida acá
        // con un mensaje claro en vez de dejar que reviente con el
        // constraint crudo de la BD (encontrado escribiendo el test de
        // integración de este mismo sprint). No se tocó el constraint en sí
        // -- cambiar su alcance a (client_id, customer_id, name) es una
        // decisión de modelo de datos más grande, fuera de este sprint.
        $validated = $request->validate([
            'customer_name' => 'required|string|max:255',
            'customer_address' => 'required|string|max:500',
            'sites' => 'required|array|min:1|max:20',
            'sites.*.name' => [
                'required',
                'string',
                'max:255',
                'distinct',
                function ($attribute, $value, $fail) use ($user) {
                    if (Site::where('client_id', $user->client_id)->where('name', $value)->exists()) {
                        $fail("Ya existe una sede llamada \"{$value}\" en tu cuenta -- usa un nombre distinto.");
                    }
                },
            ],
            'sites.*.address' => 'required|string|max:500',
        ]);

        $client = Client::findOrFail($user->client_id);

        // Mismo patrón que InternalCustomerService::ensureForClient(): a
        // esta altura del flujo ApplyPgsqlTenantRls YA fijó
        // app.tenant_user_client_id = $client->id al entrar al request
        // (el Client existe desde 7.2, a diferencia de la creación del
        // Client mismo en 7.2 -- ahí sí hacía falta bypass porque el
        // client_id no existía todavía en ningún lado). Sin bypass aquí;
        // verificado que basta contra Postgres real con RLS activo.
        DB::transaction(function () use ($client, $validated) {
            $customer = Customer::create([
                'client_id' => $client->id,
                'name' => $validated['customer_name'],
                'is_internal' => false,
            ]);

            foreach ($validated['sites'] as $site) {
                Site::create([
                    'client_id' => $client->id,
                    'customer_id' => $customer->id,
                    'name' => $site['name'],
                    'address' => $site['address'],
                    'type' => 'physical',
                    'is_active' => true,
                ]);
            }
        });

        return redirect('/onboarding/customers')->with('success', 'Cliente agregado.');
    }

    public function finishCustomers(Request $request)
    {
        $user = Auth::user();
        if ($redirect = $this->guardStep($user, '/onboarding/customers')) {
            return $redirect;
        }

        Client::findOrFail($user->client_id)->update(['onboarding_step' => 'customers_added']);

        return redirect('/onboarding/staff');
    }

    // ── 7.6: invitar personal ────────────────────────────────────────────

    /** Roles invitables desde el wizard -- admin queda excluido a propósito, solo existe el fundador de 7.2. */
    private const INVITABLE_ROLES = ['supervisor', 'agente', 'solicitante', 'Encargado TI'];

    public function showStaff(): Response|RedirectResponse
    {
        $user = Auth::user();
        if ($redirect = $this->guardStep($user, '/onboarding/staff')) {
            return $redirect;
        }

        $client = Client::findOrFail($user->client_id);

        return Inertia::render('Onboarding/Staff', [
            'invitations' => UserInvitation::where('client_id', $client->id)
                ->pending()
                ->with('role:id,name')
                ->orderByDesc('created_at')
                ->get(['id', 'email', 'role_id', 'created_at'])
                ->map(fn (UserInvitation $i) => [
                    'id' => $i->id,
                    'email' => $i->email,
                    'role_name' => $i->role?->name,
                ]),
            'role_options' => self::INVITABLE_ROLES,
            'seats' => $this->seatUsage($client),
        ]);
    }

    public function storeStaff(Request $request)
    {
        $user = Auth::user();
        if ($redirect = $this->guardStep($user, '/onboarding/staff')) {
            return $redirect;
        }

        $client = Client::findOrFail($user->client_id);

        $validated = $request->validate([
            'email' => 'required|email|max:255',
            'role' => ['required', 'string', Rule::in(self::INVITABLE_ROLES)],
        ]);

        // Cupo de plan: activos (status != blocked) + invitaciones pendientes
        // sin expirar, verificado ANTES de crear -- así nadie manda de golpe
        // más invitaciones que cupo y las acepta todas superando el plan. Sin
        // límite (plan sin max_users, o tenant sin plan) -> no bloquea nada.
        $seats = $this->seatUsage($client);
        if ($seats['max'] !== null && $seats['used'] >= $seats['max']) {
            throw ValidationException::withMessages([
                'email' => ["Tu plan permite hasta {$seats['max']} usuarios, ya tienes {$seats['used']} en uso."],
            ]);
        }

        $role = Role::where('team_id', $client->id)
            ->where('name', $validated['role'])
            ->where('guard_name', 'web')
            ->firstOrFail();

        // Reusa InvitationController::store() tal cual (dedup de pendientes,
        // expiración a 72h, envío de correo, resolución de rol por team_id
        // ya correcta ahí) -- client_id explícito porque
        // InvitationTenancyService::resolveClientIdForCreate() solo lo
        // asume del subdominio del portal, y este request no viene de ahí.
        app(InvitationController::class)->store(Request::create('', 'POST', [
            'email' => $validated['email'],
            'role_id' => $role->id,
            'client_id' => $client->id,
        ]));

        return redirect('/onboarding/staff')->with('success', 'Invitación enviada.');
    }

    public function finishStaff(Request $request)
    {
        $user = Auth::user();
        if ($redirect = $this->guardStep($user, '/onboarding/staff')) {
            return $redirect;
        }

        Client::findOrFail($user->client_id)->update(['onboarding_step' => 'staff_invited']);
        $user->update(['onboarding_completed' => true]);

        return redirect('/home')->with('success', '¡Tu empresa está lista!');
    }

    /** @return array{used: int, max: int|null} */
    private function seatUsage(Client $client): array
    {
        $used = User::where('client_id', $client->id)->where('status', '!=', 'blocked')->count()
            + UserInvitation::where('client_id', $client->id)->pending()->count();

        return ['used' => $used, 'max' => $client->plan?->max_users];
    }

    /**
     * Si el paso actual del usuario (según OnboardingRedirectService, única
     * fuente de verdad) no coincide con la página que se está pidiendo,
     * redirige a donde le toca en vez de dejarlo saltarse pasos o repetir
     * uno ya hecho. null = puede seguir en esta página.
     */
    private function guardStep(User $user, string $expectedPath): ?RedirectResponse
    {
        $target = $this->onboarding->redirectPath($user);

        if ($target === null) {
            return redirect('/home');
        }

        if ($target !== $expectedPath) {
            return redirect($target);
        }

        return null;
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
