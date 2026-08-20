<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * RBAC v2 (Fase 6): catálogo GLOBAL (no por tenant) de objetos de
 * autorización -- capa de presentación sobre el catálogo de permisos de
 * Spatie. full_permission/read_permission/edit_permission son nombres de
 * filas reales en `permissions`, nunca permisos inventados por esta tabla.
 * edit_permission (fase 7.4 de Inventario) es el único objeto de 3 niveles
 * reales hoy -- todos los demás lo dejan null y siguen siendo full/read.
 */
class AuthorizationObject extends Model
{
    protected $fillable = [
        'key',
        'label',
        'parent_id',
        'full_permission',
        'read_permission',
        'edit_permission',
        'sort_order',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }
}
