<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class InvComponent extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $fillable = [
        'asset_id',
        'origin_asset_id',
        'name',
        'marca',
        'modelo',
        'serie',
        'capacidad',
        'observacion',
        'status',
        'client_id',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(InvAsset::class, 'asset_id');
    }

    public function originAsset(): BelongsTo
    {
        return $this->belongsTo(InvAsset::class, 'origin_asset_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InvComponentMovement::class, 'component_id');
    }
}
