<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvAssetTicket extends Model
{
    use Auditable;

    // El nombre de clase compuesto ("AssetTicket") pluraliza mal por
    // convención de Eloquent (inv_asset_tickets) -- la tabla real de la
    // migración es inv_asset_ticket (singular, mismo patrón que
    // inv_component_movements/inv_movements: nombre de tabla explícito en
    // la migración, no inferido).
    protected $table = 'inv_asset_ticket';

    protected $fillable = [
        'inv_asset_id',
        'ticket_id',
        'client_id',
        'linked_by',
        'notes',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(InvAsset::class, 'inv_asset_id');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id');
    }

    public function linkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_by');
    }
}
