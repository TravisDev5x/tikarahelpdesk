<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvIntegration extends Model
{
    use Auditable;

    protected $fillable = [
        'client_id',
        'provider',
        'config',
        'status',
        'status_message',
        'last_tested_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'encrypted:array',
            'last_tested_at' => 'datetime',
        ];
    }

    /**
     * `config` guarda credenciales (client_secret/bind_password) -- aunque ya
     * viaja cifrado en `$attributes`, no tiene caso dejarlo en audit_logs.
     */
    protected static function auditableHiddenAttributes(): array
    {
        return ['password', 'password_confirmation', 'remember_token', 'updated_at', 'config'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
