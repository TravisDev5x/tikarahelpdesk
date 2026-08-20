<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvComponentMovement extends Model
{
    use Auditable;

    protected $fillable = [
        'component_id',
        'asset_id',
        'origin_asset_id',
        'type',
        'admin_id',
        'reason',
        'notes',
        'client_id',
        'date',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'datetime',
        ];
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(InvComponent::class, 'component_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(InvAsset::class, 'asset_id');
    }

    public function originAsset(): BelongsTo
    {
        return $this->belongsTo(InvAsset::class, 'origin_asset_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }
}
