<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Plan;
use App\Models\Site;
use App\Models\TicketState;
use App\Services\OperatorScopeService;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class ClientController extends Controller
{
    public function __construct(
        protected OperatorScopeService $operatorScope
    ) {}

    protected const INDUSTRIES = [
        'Tecnología', 'Retail', 'Manufactura', 'Salud',
        'Educación', 'Logística', 'Finanzas',
        'Gobierno', 'Servicios', 'Otro',
    ];

    private function canViewAllClients(): bool
    {
        return $this->operatorScope->bypassesOperatorScope(auth()->user());
    }

    private function clientQuery(): Builder
    {
        return $this->operatorScope->applyOnClients(Client::query(), auth()->user());
    }

    private function authorizeClient(Client $client): void
    {
        $this->operatorScope->authorizeClient(auth()->user(), $client);
    }

    public function index(): Response
    {
        $user = auth()->user();
        $showOperatorColumn = $this->operatorScope->bypassesOperatorScope($user);

        $clientIds = $this->clientQuery()->pluck('id');

        $summary = [
            'total'         => $clientIds->count(),
            'active'        => $this->clientQuery()->where('is_active', true)->count(),
            'total_users'   => \App\Models\User::whereIn('client_id', $clientIds)->count(),
            'total_tickets' => \App\Models\Ticket::whereIn('client_id', $clientIds)->count(),
        ];

        $isPlatformAdmin = $this->operatorScope->bypassesOperatorScope($user);

        $query = $this->clientQuery()
            ->withCount(['sites', 'tickets', 'users'])
            ->orderBy('name');

        if ($showOperatorColumn) {
            $query->with('operatorUser:id,name');
        }

        if ($isPlatformAdmin) {
            $query->with('plan:id,name,slug,type,price_monthly');
        }

        $clients = $query->paginate(20);

        return Inertia::render('Clients/Index', [
            'clients'             => $clients,
            'summary'             => $summary,
            'showOperatorColumn'  => $showOperatorColumn,
            'isPlatformAdmin'     => $isPlatformAdmin,
            'portalBaseDomain'    => config('tenancy.base_domain'),
            'portalScheme'        => config('tenancy.portal_scheme', 'http'),
            'availablePlans'      => $isPlatformAdmin
                ? Plan::activePublic()->get(['id', 'name', 'slug', 'type', 'price_monthly'])
                : [],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Clients/Form', [
            'client' => null,
            'industries' => self::INDUSTRIES,
            'sites' => [],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validateClient($request);

        $client = DB::transaction(function () use ($request, $validated) {
            $logoPath = null;
            if ($request->hasFile('logo')) {
                $logoPath = $request->file('logo')->store(
                    'client-logos/'.auth()->id(),
                    'public'
                );
            }

            $client = Client::create([
                'name' => $validated['business_name'],
                'industry' => $validated['industry'] ?? null,
                'tax_id' => $validated['rfc'] ?? null,
                'contact_name' => $validated['contact_name'] ?? null,
                'contact_email' => $validated['contact_email'] ?? null,
                'contact_phone' => $validated['phone'] ?? null,
                'website' => $validated['website'] ?? null,
                'address' => $validated['address'] ?? null,
                'latitude' => $validated['latitude'] ?? null,
                'longitude' => $validated['longitude'] ?? null,
                'logo_path' => $logoPath,
                'is_active' => $validated['is_active'] ?? true,
                'operator_user_id' => auth()->id(),
                'portal_slug' => TenantContextService::generateUniquePortalSlug($validated['business_name']),
            ]);

            foreach ($validated['sites'] ?? [] as $site) {
                if (empty($site['name'])) {
                    continue;
                }
                Site::create([
                    'client_id' => $client->id,
                    'name' => $site['name'],
                    'code' => $site['code'] ?? null,
                    'type' => $site['type'] ?? 'physical',
                    'address' => $site['address'] ?? null,
                    'city' => $site['city'] ?? null,
                    'latitude' => $site['latitude'] ?? null,
                    'longitude' => $site['longitude'] ?? null,
                    'contact_name' => $site['contact_name'] ?? null,
                    'contact_phone' => $site['contact_phone'] ?? null,
                    'contact_email' => $site['contact_email'] ?? null,
                    'is_active' => $site['is_active'] ?? true,
                ]);
            }

            return $client;
        });

        return redirect()
            ->route('clients.show', $client)
            ->with('success', 'Cliente creado correctamente');
    }

    public function show(Client $client): Response
    {
        $this->authorizeClient($client);
        $client->load(['sites', 'plan', 'users' => fn ($q) => $q->select(
            'id', 'first_name', 'paternal_last_name', 'maternal_last_name', 'email', 'status', 'client_id'
        )->with('roles:id,name')]);

        $blockedFromInternals = $this->operatorScope->isPlatformAdminBlockedFromInternals(auth()->user());

        $ticketsSummary = null;
        if (! $blockedFromInternals) {
            $finalStateIds = TicketState::where('is_final', true)->pluck('id');

            $ticketsSummary = [
                'open' => $client->tickets()->whereNotIn('ticket_state_id', $finalStateIds)->count(),
                'closed' => $client->tickets()->whereIn('ticket_state_id', $finalStateIds)->count(),
                'overdue' => $client->tickets()
                    ->whereNotIn('ticket_state_id', $finalStateIds)
                    ->where(function ($q) {
                        $q->whereNotNull('due_at')->where('due_at', '<', now())
                            ->orWhere(function ($q2) {
                                $q2->whereNull('due_at')
                                    ->where('created_at', '<', now()->subHours(72));
                            });
                    })
                    ->count(),
            ];
        }

        return Inertia::render('Clients/Show', [
            'client' => $client,
            'tickets_summary' => $ticketsSummary,
            'sites' => $client->sites,
            'users' => $client->users,
            'industries' => self::INDUSTRIES,
            'can' => [
                'view_internals' => ! $blockedFromInternals,
            ],
        ]);
    }

    public function edit(Client $client): Response
    {
        $this->authorizeClient($client);

        return Inertia::render('Clients/Form', [
            'client' => $client->load('sites'),
            'industries' => self::INDUSTRIES,
            'sites' => $client->sites,
        ]);
    }

    public function update(Request $request, Client $client)
    {
        $this->authorizeClient($client);
        $validated = $this->validateClient($request, $client);

        DB::transaction(function () use ($request, $client, $validated) {
            if ($request->hasFile('logo')) {
                if ($client->logo_path) {
                    Storage::disk('public')->delete($client->logo_path);
                }
                $client->logo_path = $request->file('logo')->store(
                    'client-logos/'.auth()->id(),
                    'public'
                );
            }

            $client->update([
                'name' => $validated['business_name'],
                'industry' => $validated['industry'] ?? null,
                'tax_id' => $validated['rfc'] ?? null,
                'contact_name' => $validated['contact_name'] ?? null,
                'contact_email' => $validated['contact_email'] ?? null,
                'contact_phone' => $validated['phone'] ?? null,
                'website' => $validated['website'] ?? null,
                'address' => $validated['address'] ?? null,
                'latitude' => $validated['latitude'] ?? null,
                'longitude' => $validated['longitude'] ?? null,
                'is_active' => $validated['is_active'] ?? true,
            ]);

            $this->syncSites($client, $validated['sites'] ?? []);
        });

        return redirect()
            ->route('clients.show', $client)
            ->with('success', 'Cliente actualizado');
    }

    public function destroy(Client $client)
    {
        $this->authorizeClient($client);

        $finalStateIds = TicketState::where('is_final', true)->pluck('id');
        $openTickets = $client->tickets()->whereNotIn('ticket_state_id', $finalStateIds)->count();

        if ($openTickets > 0) {
            return back()->withErrors([
                'client' => 'No se puede eliminar un cliente con tickets abiertos',
            ]);
        }

        if ($client->logo_path) {
            Storage::disk('public')->delete($client->logo_path);
        }

        $client->delete();

        return redirect()
            ->route('clients.index')
            ->with('success', 'Cliente eliminado');
    }

    /** Solo super_admin: cancela la cuenta (desactiva) sin borrar datos. */
    public function cancel(Client $client): RedirectResponse
    {
        abort_unless($this->operatorScope->bypassesOperatorScope(auth()->user()), 403);

        $client->update([
            'is_active'    => false,
            'cancelled_at' => now(),
        ]);

        return redirect()
            ->route('clients.index')
            ->with('success', "Cuenta de \"{$client->name}\" cancelada. Los datos se conservan.");
    }

    /** Solo super_admin: reactiva una cuenta cancelada. */
    public function reactivate(Client $client): RedirectResponse
    {
        abort_unless($this->operatorScope->bypassesOperatorScope(auth()->user()), 403);

        $client->update([
            'is_active'    => true,
            'cancelled_at' => null,
        ]);

        return redirect()
            ->route('clients.index')
            ->with('success', "Cuenta de \"{$client->name}\" reactivada.");
    }

    /** Solo super_admin: actualiza plan y fecha de vencimiento. */
    public function updatePlan(Request $request, Client $client): RedirectResponse
    {
        abort_unless($this->operatorScope->bypassesOperatorScope(auth()->user()), 403);

        $validated = $request->validate([
            'plan_id'                => 'nullable|exists:plans,id',
            'subscription_expires_at'=> 'nullable|date|after:today',
            'billing_email'          => 'nullable|email|max:255',
        ]);

        $client->update($validated);

        return back()->with('success', 'Plan actualizado.');
    }

    private function validateClient(Request $request, ?Client $client = null): array
    {
        $uniqueName = 'unique:clients,name';
        if ($client) {
            $uniqueName .= ','.$client->id;
        }

        return $request->validate([
            'business_name' => ['required', 'string', 'max:255', $uniqueName],
            'industry' => 'nullable|string|max:255',
            'rfc' => ['nullable', 'string', 'min:12', 'max:13'],
            'phone' => 'nullable|string|size:10|regex:/^\d{10}$/',
            'contact_name' => 'nullable|string|max:255',
            'contact_email' => 'nullable|email|max:255',
            'website' => 'nullable|url|max:255',
            'address' => 'nullable|string|max:500',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'is_active' => 'boolean',
            'logo' => 'nullable|image|max:2048',
            'sites' => 'nullable|array|max:20',
            'sites.*.id' => 'nullable|integer|exists:sites,id',
            'sites.*.name' => 'required_with:sites|string|max:255',
            'sites.*.code' => 'nullable|string|max:255',
            'sites.*.type' => 'nullable|in:physical,virtual',
            'sites.*.address' => 'nullable|string|max:500',
            'sites.*.city' => 'nullable|string|max:255',
            'sites.*.latitude' => 'nullable|numeric|between:-90,90',
            'sites.*.longitude' => 'nullable|numeric|between:-180,180',
            'sites.*.contact_name' => 'nullable|string|max:255',
            'sites.*.contact_phone' => 'nullable|string|max:50',
            'sites.*.contact_email' => 'nullable|email|max:255',
            'sites.*.is_active' => 'boolean',
        ]);
    }

    private function syncSites(Client $client, array $sites): void
    {
        $submittedIds = collect($sites)->pluck('id')->filter()->map(fn ($id) => (int) $id)->all();
        $client->sites()->whereNotIn('id', $submittedIds)->delete();

        foreach ($sites as $site) {
            if (empty($site['name'])) {
                continue;
            }
            if (! empty($site['id'])) {
                $client->sites()->where('id', $site['id'])->update([
                    'name' => $site['name'],
                    'code' => $site['code'] ?? null,
                    'type' => $site['type'] ?? 'physical',
                    'address' => $site['address'] ?? null,
                    'city' => $site['city'] ?? null,
                    'latitude' => $site['latitude'] ?? null,
                    'longitude' => $site['longitude'] ?? null,
                    'contact_name' => $site['contact_name'] ?? null,
                    'contact_phone' => $site['contact_phone'] ?? null,
                    'contact_email' => $site['contact_email'] ?? null,
                    'is_active' => $site['is_active'] ?? true,
                ]);
            } else {
                Site::create([
                    'client_id' => $client->id,
                    'name' => $site['name'],
                    'code' => $site['code'] ?? null,
                    'type' => $site['type'] ?? 'physical',
                    'address' => $site['address'] ?? null,
                    'city' => $site['city'] ?? null,
                    'latitude' => $site['latitude'] ?? null,
                    'longitude' => $site['longitude'] ?? null,
                    'contact_name' => $site['contact_name'] ?? null,
                    'contact_phone' => $site['contact_phone'] ?? null,
                    'contact_email' => $site['contact_email'] ?? null,
                    'is_active' => $site['is_active'] ?? true,
                ]);
            }
        }
    }
}
