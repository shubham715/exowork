<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

class Candidate extends Authenticatable
{
    use HasFactory;

    protected $guarded = ['id', 'candidate_code'];

    protected $hidden = ['password', 'aadhaar_number', 'registration_ip', 'import_data'];

    protected function casts(): array
    {
        return [
            'import_data' => 'encrypted:array',
            'password' => 'hashed',
            'aadhaar_number' => 'encrypted',
            'preferred_locations' => 'array',
            'languages' => 'array',
            'batch_end_date' => 'date',
            'date_of_birth' => 'date',
            'location_consent' => 'boolean',
            'photo_consent' => 'boolean',
            'call_consent' => 'boolean',
            'sms_consent' => 'boolean',
            'whatsapp_consent' => 'boolean',
            'email_consent' => 'boolean',
            'terms_accepted_at' => 'datetime',
            'privacy_accepted_at' => 'datetime',
        ];
    }
}
