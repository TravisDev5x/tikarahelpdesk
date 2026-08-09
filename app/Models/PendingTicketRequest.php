<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PendingTicketRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const REASON_UNREGISTERED = 'unregistered';

    public const REASON_INACTIVE = 'inactive';

    public const REASON_WRONG_TENANT = 'wrong_tenant';

    protected $fillable = [
        'client_id',
        'from_email',
        'from_name',
        'subject',
        'body',
        'reason',
        'matched_user_id',
        'origin_message_id',
        'status',
        'reviewed_by',
        'reviewed_at',
        'review_note',
        'resulting_ticket_id',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function matchedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'matched_user_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function resultingTicket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'resulting_ticket_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeResolved(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_APPROVED, self::STATUS_REJECTED]);
    }
}
