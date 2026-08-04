<?php

namespace App\Http\Controllers\Inertia;

use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\AuthorizationObject;
use App\Services\OperatorScopeService;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\ImpactLevel;
use App\Models\Permission;
use App\Models\Position;
use App\Models\Priority;
use App\Models\PriorityMatrix;
use App\Models\Role;
use App\Models\Site;
use App\Models\TicketMacro;
use App\Models\TicketState;
use App\Models\TicketType;
use App\Models\Location;
use App\Models\UrgencyLevel;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Páginas Inertia de catálogos (SSR inicial).
 * Mutaciones siguen en /api/*.
 */
class CatalogPageController extends Controller
{
    public function __construct(
        protected OperatorScopeService $operatorScope
    ) {}

    public function areas(): Response
    {
        return Inertia::render('Catalogs/Areas', [
            'areas' => Area::orderBy('name')->get(['id', 'name', 'is_active', 'created_at']),
        ]);
    }

    public function priorities(): Response
    {
        return Inertia::render('Catalogs/Prioridades', [
            'priorities' => Priority::orderBy('level')->orderBy('name')->get(),
        ]);
    }

    public function impactLevels(): Response
    {
        return Inertia::render('Catalogs/ImpactLevels', [
            'impactLevels' => ImpactLevel::orderBy('weight')->orderBy('name')->get(),
        ]);
    }

    public function urgencyLevels(): Response
    {
        return Inertia::render('Catalogs/UrgencyLevels', [
            'urgencyLevels' => UrgencyLevel::orderBy('weight')->orderBy('name')->get(),
        ]);
    }

    public function campaigns(): Response
    {
        return Inertia::render('Catalogs/Campaigns', [
            'campaigns' => Campaign::orderBy('name')->get(['id', 'name', 'is_active', 'created_at']),
        ]);
    }

    public function positions(): Response
    {
        return Inertia::render('Catalogs/Positions', [
            'positions' => Position::orderBy('name')->get(['id', 'name', 'is_active', 'created_at']),
        ]);
    }

    public function roles(): Response
    {
        // RBAC v2 (Fase 6, Paso 3): scoped por team_id, mismo criterio que
        // RoleController::index().
        $teamId = getPermissionsTeamId();

        return Inertia::render('Catalogs/Roles', [
            // RBAC v2 (Fase 7.2): permissions:id,name para reconstruir en el
            // cliente la selección Full/Solo lectura/Ninguno al editar.
            'roles' => Role::with('permissions:id,name')
                ->where(fn ($q) => $q->whereNull('team_id')->orWhere('team_id', $teamId))
                ->orderBy('guard_name')
                ->orderBy('name')
                ->get(['id', 'name', 'slug', 'guard_name', 'scope_archetype', 'created_at']),
            // RBAC v2 (Fase 6/7.1): catálogo global para el editor de plantillas
            // (mismo query que RoleTemplateController::catalog()).
            'authorizationObjects' => AuthorizationObject::whereNull('parent_id')
                ->with('children')
                ->orderBy('sort_order')
                ->get(),
        ]);
    }

    public function sessions(): Response
    {
        return Inertia::render('Catalogs/Sessions');
    }

    public function ticketStates(): Response
    {
        return Inertia::render('Catalogs/TicketStates', [
            'ticketStates' => TicketState::orderBy('is_final')->orderBy('name')->get(),
        ]);
    }

    public function ticketTypes(): Response
    {
        return Inertia::render('Catalogs/TicketTypes', [
            'ticketTypes' => TicketType::with('areas:id,name')->orderBy('name')->get(),
            'areas' => Area::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function sites(): Response
    {
        $user = auth()->user();

        $sitesQuery = $this->operatorScope->applyOnSites(
            Site::with('client:id,name'),
            $user
        );

        return Inertia::render('Catalogs/Sites', [
            'sites' => $sitesQuery->orderBy('type')->orderBy('name')->get(),
            'clients' => $user
                ? $this->operatorScope->clientsForCatalog($user)
                : [],
        ]);
    }

    public function locations(): Response
    {
        return Inertia::render('Catalogs/Locations', [
            'locations' => Location::with('site:id,name,type')->orderBy('site_id')->orderBy('name')->get(),
            'sites' => Site::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function ticketMacros(): Response
    {
        return Inertia::render('Catalogs/TicketMacros', [
            'ticketMacros' => TicketMacro::orderBy('category')->orderBy('name')->get(['id', 'name', 'category', 'is_active', 'created_at']),
        ]);
    }

    public function priorityMatrix(): Response
    {
        return Inertia::render('Catalogs/PriorityMatrix', [
            'matrix' => PriorityMatrix::all(['impact_level_id', 'urgency_level_id', 'priority_id']),
            'impactLevels' => ImpactLevel::where('is_active', true)->orderBy('weight')->get(['id', 'name', 'weight']),
            'urgencyLevels' => UrgencyLevel::where('is_active', true)->orderBy('weight')->get(['id', 'name', 'weight']),
            'priorities' => Priority::where('is_active', true)->orderBy('level')->orderBy('name')->get(['id', 'name', 'level']),
        ]);
    }

    public function permissions(): Response
    {
        // RBAC v2 (Fase 6, Paso 3): roles scoped por team_id, mismo criterio
        // que roles()/RoleController::index(). Permission NO está team-scoped
        // (catálogo global, ver migración 2026_07_15_000001), se queda igual.
        $teamId = getPermissionsTeamId();

        return Inertia::render('System/Permissions', [
            'roles' => Role::with('permissions')
                ->where(fn ($q) => $q->whereNull('team_id')->orWhere('team_id', $teamId))
                ->orderBy('name')
                ->get(),
            'permissions' => Permission::orderBy('name')->get(['id', 'name', 'guard_name']),
        ]);
    }

    public function incidentTypes(): Response
    {
        return Inertia::render('Incidents/Types', [
            'incidentTypes' => \App\Models\IncidentType::orderBy('name')->get(['id', 'name', 'code', 'is_active', 'created_at']),
        ]);
    }

    public function incidentSeverities(): Response
    {
        return Inertia::render('Incidents/Severities', [
            'incidentSeverities' => \App\Models\IncidentSeverity::orderBy('level')->orderBy('name')->get(['id', 'name', 'code', 'level', 'is_active', 'created_at']),
        ]);
    }

    public function incidentStatuses(): Response
    {
        return Inertia::render('Incidents/Statuses', [
            'incidentStatuses' => \App\Models\IncidentStatus::orderBy('name')->get(['id', 'name', 'code', 'is_final', 'is_active', 'created_at']),
        ]);
    }

    public function resolbebCreateCatalogs(): array
    {
        return [
            'areas' => Area::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'ticket_types' => TicketType::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'priorities' => Priority::where('is_active', true)->orderBy('level')->orderBy('name')->get(['id', 'name', 'level']),
            'sites' => Site::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'impact_levels' => ImpactLevel::where('is_active', true)->orderBy('weight')->orderBy('name')->get(['id', 'name']),
            'urgency_levels' => UrgencyLevel::where('is_active', true)->orderBy('weight')->orderBy('name')->get(['id', 'name']),
            'ticket_states' => TicketState::orderBy('name')->get(['id', 'name', 'code', 'is_final']),
            'priority_matrix' => PriorityMatrix::all(['impact_level_id', 'urgency_level_id', 'priority_id']),
        ];
    }

    public function resolbebDashboardCatalogs(): array
    {
        return [
            'sites' => Site::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'area_users' => User::where('status', 'active')
                ->whereNotNull('area_id')
                ->orderBy('name')
                ->get(['id', 'name']),
        ];
    }

    public function incidentIndexCatalogs(): array
    {
        return [
            'areas' => Area::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'sites' => Site::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'incident_types' => \App\Models\IncidentType::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'incident_severities' => \App\Models\IncidentSeverity::where('is_active', true)->orderBy('level')->get(['id', 'name', 'level', 'code']),
            'incident_statuses' => \App\Models\IncidentStatus::where('is_active', true)->orderBy('name')->get(['id', 'name', 'code', 'is_final']),
            'area_users' => User::where('status', 'active')->whereNotNull('area_id')->orderBy('name')->get(['id', 'name']),
        ];
    }

    public function incidentDetalleCatalogs(): array
    {
        return $this->incidentIndexCatalogs();
    }

    public function resolbebDetalleCatalogs(): array
    {
        return [
            'areas' => Area::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'ticket_states' => TicketState::orderBy('name')->get(['id', 'name', 'code', 'is_final']),
            'priorities' => Priority::where('is_active', true)->orderBy('level')->get(['id', 'name', 'level']),
            'ticket_types' => TicketType::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ];
    }
}
