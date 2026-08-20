<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvAssetImage extends Model
{
    protected $fillable = [
        'inv_asset_id',
        'path',
        'disk',
        'type',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(InvAsset::class, 'inv_asset_id');
    }
}
