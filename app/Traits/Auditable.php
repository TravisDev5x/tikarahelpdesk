<?php

namespace App\Traits;

use App\Models\AuditLog;
use App\Models\InvAsset;
use App\Models\InvAssetRelationship;
use App\Models\InvAssetSpec;
use App\Models\InvAssetTicket;
use App\Models\InvComponent;
use App\Models\InvComponentMovement;
use App\Models\InvDisposal;
use App\Models\InvDocument;
use App\Models\InvMaintenance;
use App\Models\InvMovement;
use App\Models\InvWarranty;
use App\Models\Ticket;
use Illuminate\Support\Facades\Log;

trait Auditable
{
    /**
     * Atributos que no deben guardarse en el log (contraseñas, tokens, etc.).
     */
    protected static function auditableHiddenAttributes(): array
    {
        return [
            'password',
            'password_confirmation',
            'remember_token',
            'updated_at',
        ];
    }

    protected static function bootAuditable(): void
    {
        static::created(function (self $model) {
            static::writeAuditLog($model, 'created', null, static::sanitizeForAudit($model->getAttributes()));
        });

        static::updated(function (self $model) {
            $changes = $model->getChanges();
            unset($changes['updated_at']);
            if ($changes === []) {
                return;
            }
            $original = $model->getOriginal();
            $oldValues = [];
            foreach (array_keys($changes) as $key) {
                $oldValues[$key] = $original[$key] ?? null;
            }
            static::writeAuditLog(
                $model,
                'updated',
                static::sanitizeForAudit($oldValues),
                static::sanitizeForAudit($changes)
            );
        });

        static::deleted(function (self $model) {
            static::writeAuditLog(
                $model,
                'deleted',
                static::sanitizeForAudit($model->getOriginal()),
                null
            );
        });

        // Corrección: Solo escuchar 'restored' si el modelo usa SoftDeletes
        if (method_exists(static::class, 'restored')) {
            static::restored(function (self $model) {
                static::writeAuditLog($model, 'restored', null, static::sanitizeForAudit($model->getAttributes()));
            });
        }
    }

    /**
     * Escribe un registro en audit_logs sin interrumpir la transacción principal.
     */
    protected static function writeAuditLog(
        self $model,
        string $action,
        ?array $oldValues,
        ?array $newValues
    ): void {
        try {
            $request = request();
            $payload = [
                'user_id' => auth()->id(),
                'auditable_type' => $model->getMorphClass(),
                'auditable_id' => $model->getKey(),
                'action' => $action,
                'old_values' => $oldValues,
                'new_values' => $newValues,
                'ip_address' => $request ? $request->ip() : null,
                'user_agent' => $request ? $request->userAgent() : null,
            ];

            if ($model instanceof Ticket) {
                $payload['client_id'] = $model->client_id
                    ?? ($model->relationLoaded('site') ? $model->site?->client_id : null)
                    ?? \App\Models\Site::where('id', $model->site_id)->value('client_id');
            }

            // InvAsset (fase 2 Inventario) ya trae client_id propio y
            // obligatorio -- no necesita resolverlo vía site como Ticket.
            if ($model instanceof InvAsset) {
                $payload['client_id'] = $model->client_id;
            }

            // InvMovement (fase 3) -- mismo caso, client_id propio.
            if ($model instanceof InvMovement) {
                $payload['client_id'] = $model->client_id;
            }

            // InvComponent / InvComponentMovement (fase 4) -- mismo caso.
            if ($model instanceof InvComponent || $model instanceof InvComponentMovement) {
                $payload['client_id'] = $model->client_id;
            }

            // InvMaintenance (fase 5) -- mismo caso, client_id propio. Hueco
            // real encontrado en la auditoría de Inventario (fase 1,
            // crítico): sin esta rama, audit_logs.client_id quedaba null
            // para cada cambio sobre un mantenimiento, invisible para
            // cualquier consulta de auditoría filtrada por tenant.
            if ($model instanceof InvMaintenance) {
                $payload['client_id'] = $model->client_id;
            }

            // InvAssetSpec (fase 2.1, ficha técnica estructurada) -- mismo caso.
            if ($model instanceof InvAssetSpec) {
                $payload['client_id'] = $model->client_id;
            }

            // InvDocument / InvDisposal (fase 2.2, documentos y bajas
            // estructuradas) -- mismo caso, client_id propio.
            if ($model instanceof InvDocument || $model instanceof InvDisposal) {
                $payload['client_id'] = $model->client_id;
            }

            // InvWarranty (fase 2.3, garantías) -- mismo caso.
            if ($model instanceof InvWarranty) {
                $payload['client_id'] = $model->client_id;
            }

            // InvAssetTicket (fase 3.1, relación Activo↔Ticket) -- mismo caso.
            if ($model instanceof InvAssetTicket) {
                $payload['client_id'] = $model->client_id;
            }

            // InvAssetRelationship (fase 3.2, relaciones entre activos) -- mismo caso.
            if ($model instanceof InvAssetRelationship) {
                $payload['client_id'] = $model->client_id;
            }

            AuditLog::create($payload);
        } catch (\Throwable $e) {
            Log::error('AuditLog failed', [
                'auditable_type' => $model->getMorphClass(),
                'auditable_id' => $model->getKey(),
                'action' => $action,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Convierte atributos a formato serializable para JSON y excluye sensibles.
     */
    protected static function sanitizeForAudit(array $attrs): array
    {
        $hidden = array_flip(static::auditableHiddenAttributes());
        $out = [];
        foreach ($attrs as $key => $value) {
            if (isset($hidden[$key])) {
                continue;
            }
            if ($value instanceof \DateTimeInterface) {
                $out[$key] = $value->format('Y-m-d H:i:s');
            } elseif (is_scalar($value) || $value === null) {
                $out[$key] = $value;
            } elseif (is_array($value)) {
                $out[$key] = $value;
            } else {
                $out[$key] = (string) $value;
            }
        }
        return $out;
    }

    /**
     * Relación: historial de auditoría de este modelo.
     */
    public function auditLogs()
    {
        return $this->morphMany(AuditLog::class, 'auditable')->orderBy('created_at', 'desc');
    }
}