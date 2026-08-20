<?php

namespace App\Services;

use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/**
 * Catálogos maestros: plataforma (operator_user_id NULL) + operador MSP.
 *
 * Por defecto NO se aísla por client_id; ver docs/CATALOG_TENANCY_MODEL.md.
 */
class OperatorCatalogScopeService
{
    public const CATALOG_TABLES = [
        'priorities',
        'ticket_states',
        'ticket_types',
        'impact_levels',
        'urgency_levels',
        'incident_types',
        'incident_severities',
        'incident_statuses',
        'areas',
        'campaigns',
        'positions',
        'inv_categories',
        'inv_statuses',
        'inv_labels',
        'inv_maintenance_origins',
        'inv_maintenance_modalities',
    ];

    public function __construct(
        protected OperatorScopeService $operatorScope,
        protected TenantContextService $tenantContext
    ) {}

    public function usesPerClientCatalogInPortal(): bool
    {
        return (bool) config('tenancy.catalog_per_client', false);
    }

    public function apply(Builder|QueryBuilder $query, ?User $user, string $table): Builder|QueryBuilder
    {
        if ($this->shouldScopeCatalogByPortalClientId($table)) {
            $clientId = $this->tenantContext->enforcedClientId();
            $col = $query instanceof QueryBuilder ? $table.'.client_id' : 'client_id';

            return $query->where($col, $clientId);
        }

        if ($this->shouldScopeCatalogByPortalOperator($table)) {
            return $this->applyPortalOperatorCatalogScope($query, $table);
        }

        if (! Schema::hasColumn($table, 'operator_user_id')) {
            return $query;
        }

        if (! $user || $this->operatorScope->bypassesOperatorScope($user)) {
            return $query;
        }

        $operatorId = $this->operatorScope->resolveOperatorUserId($user);
        $col = $query instanceof QueryBuilder ? $table.'.operator_user_id' : 'operator_user_id';

        if (! $operatorId) {
            return $query->whereNull($col);
        }

        return $query->where(function ($q) use ($col, $operatorId) {
            $q->whereNull($col)->orWhere($col, $operatorId);
        });
    }

    public function authorizeRow(User $user, Model $row): void
    {
        if ($this->operatorScope->bypassesOperatorScope($user)) {
            return;
        }

        if ($this->shouldScopeCatalogByPortalClientId($row->getTable())) {
            $enforced = $this->tenantContext->enforcedClientId();
            if ($enforced && Schema::hasColumn($row->getTable(), 'client_id')
                && (int) $row->client_id !== (int) $enforced) {
                abort(403, 'No tienes acceso a este registro de catálogo.');
            }

            return;
        }

        if (! Schema::hasColumn($row->getTable(), 'operator_user_id')) {
            return;
        }

        $portalOperatorId = $this->resolvePortalOperatorUserId();
        if ($portalOperatorId !== null) {
            $rowOperator = $row->operator_user_id;
            if ($rowOperator === null || (int) $rowOperator === $portalOperatorId) {
                return;
            }
            abort(403, 'No tienes acceso a este registro de catálogo.');
        }

        $operatorId = $this->operatorScope->resolveOperatorUserId($user);
        $rowOperator = $row->operator_user_id;

        if ($rowOperator === null) {
            return;
        }

        if (! $operatorId || (int) $rowOperator !== $operatorId) {
            abort(403, 'No tienes acceso a este registro de catálogo.');
        }
    }

    /** @return array{operator_user_id?: int|null, client_id?: int|null} */
    public function operatorAttributesForCreate(User $user): array
    {
        if ($this->shouldScopeCatalogByPortalClientId('priorities')) {
            $clientId = $this->tenantContext->enforcedClientId();

            return [
                'client_id' => $clientId,
                'operator_user_id' => Client::where('id', $clientId)->value('operator_user_id'),
            ];
        }

        $portalOperatorId = $this->resolvePortalOperatorUserId();
        if ($portalOperatorId !== null && Schema::hasColumn('priorities', 'operator_user_id')) {
            return [
                'operator_user_id' => $portalOperatorId,
                'client_id' => null,
            ];
        }

        if (! Schema::hasColumn('priorities', 'operator_user_id')) {
            return [];
        }

        if ($this->operatorScope->bypassesOperatorScope($user)) {
            return ['operator_user_id' => null, 'client_id' => null];
        }

        $operatorId = $this->operatorScope->resolveOperatorUserId($user)
            ?? ($user->is_operator ? (int) $user->id : null);

        return ['operator_user_id' => $operatorId, 'client_id' => null];
    }

    /**
     * @return array<int, \Illuminate\Contracts\Validation\ValidationRule|string>
     */
    public function uniqueNameRule(User $user, string $table, ?int $ignoreId = null, string $column = 'name'): array
    {
        return ['required', 'string', 'min:2', 'max:255', $this->uniqueRule($user, $table, $column, $ignoreId)];
    }

    /**
     * @return array<int, \Illuminate\Contracts\Validation\ValidationRule|string>
     */
    public function uniqueCodeRule(User $user, string $table, ?int $ignoreId = null): array
    {
        return ['nullable', 'string', 'max:50', $this->uniqueRule($user, $table, 'code', $ignoreId)];
    }

    /**
     * @return array<int, \Illuminate\Contracts\Validation\ValidationRule|string>
     */
    public function requiredUniqueCodeRule(User $user, string $table, ?int $ignoreId = null): array
    {
        return ['required', 'string', 'min:2', 'max:50', $this->uniqueRule($user, $table, 'code', $ignoreId)];
    }

    private function shouldScopeCatalogByPortalClientId(string $table): bool
    {
        return $this->usesPerClientCatalogInPortal()
            && $this->tenantContext->isStrictClientPortal()
            && Schema::hasColumn($table, 'client_id');
    }

    private function shouldScopeCatalogByPortalOperator(string $table): bool
    {
        return ! $this->usesPerClientCatalogInPortal()
            && $this->tenantContext->isStrictClientPortal()
            && Schema::hasColumn($table, 'operator_user_id')
            && $this->resolvePortalOperatorUserId() !== null;
    }

    private function applyPortalOperatorCatalogScope(Builder|QueryBuilder $query, string $table): Builder|QueryBuilder
    {
        $operatorId = $this->resolvePortalOperatorUserId();
        $col = $query instanceof QueryBuilder ? $table.'.operator_user_id' : 'operator_user_id';

        return $query->where(function ($q) use ($col, $operatorId) {
            $q->whereNull($col)->orWhere($col, $operatorId);
        });
    }

    private function resolvePortalOperatorUserId(): ?int
    {
        if (! $this->tenantContext->isStrictClientPortal()) {
            return null;
        }

        $clientId = $this->tenantContext->enforcedClientId();
        if (! $clientId) {
            return null;
        }

        $operatorId = Client::where('id', $clientId)->value('operator_user_id');

        return $operatorId ? (int) $operatorId : null;
    }

    private function uniqueRule(User $user, string $table, string $column, ?int $ignoreId): \Illuminate\Validation\Rules\Unique
    {
        $rule = Rule::unique($table, $column)->ignore($ignoreId);

        if ($this->operatorScope->bypassesOperatorScope($user)) {
            return $rule;
        }

        $operatorId = $this->resolveUniqueScopeOperatorId($user);

        if ($operatorId !== null) {
            return $rule->where(fn ($q) => $q->where('operator_user_id', $operatorId));
        }

        return $rule->where(fn ($q) => $q->whereNull('operator_user_id'));
    }

    private function resolveUniqueScopeOperatorId(User $user): ?int
    {
        $portalOperatorId = $this->resolvePortalOperatorUserId();
        if ($portalOperatorId !== null && $this->tenantContext->isStrictClientPortal()
            && ! $this->usesPerClientCatalogInPortal()) {
            return $portalOperatorId;
        }

        return $this->operatorScope->resolveOperatorUserId($user)
            ?? ($user->is_operator ? (int) $user->id : null);
    }
}
