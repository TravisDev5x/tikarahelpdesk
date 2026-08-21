<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvAssetSpec extends Model
{
    use Auditable;

    protected $fillable = [
        'asset_id',
        'client_id',
        'key',
        'value',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(InvAsset::class, 'asset_id');
    }
}
