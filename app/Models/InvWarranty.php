<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvWarranty extends Model
{
    use Auditable;

    protected $fillable = [
        'asset_id',
        'provider',
        'warranty_number',
        'coverage',
        'starts_at',
        'ends_at',
        'client_id',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'date',
            'ends_at' => 'date',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(InvAsset::class, 'asset_id');
    }
}
