<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvDisposal extends Model
{
    use Auditable;

    protected $fillable = [
        'asset_id',
        'movement_id',
        'method',
        'authorized_by',
        'residual_value',
        'client_id',
    ];

    protected function casts(): array
    {
        return [
            'residual_value' => 'decimal:2',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(InvAsset::class, 'asset_id');
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(InvMovement::class, 'movement_id');
    }

    public function authorizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorized_by');
    }
}
