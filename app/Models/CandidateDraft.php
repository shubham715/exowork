<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CandidateDraft extends Model
{
    protected $fillable = ['token_hash', 'payload', 'last_step', 'registration_source', 'expires_at'];

    protected $hidden = ['token_hash', 'payload'];

    protected function casts(): array
    {
        return [
            'payload' => 'encrypted:array',
            'expires_at' => 'datetime',
        ];
    }
}
