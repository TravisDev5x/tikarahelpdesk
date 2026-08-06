<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'client_id',
        'auditable_type',
        'auditable_id',
        'action',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * Para compatibilidad con centro de auditoría de tickets: cuando el log es de un Ticket, auditable_id = ticket_id.
     */
    protected $appends = ['ticket_id'];

    public function getTicketIdAttribute(): ?int
    {
        return $this->auditable_type === Ticket::class ? (int) $this->auditable_id : null;
    }

    protected static function booted(): void
    {
        static::creating(function (self $log) {
            if (empty($log->created_at)) {
                $log->created_at = now();
            }
        });
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }
}
