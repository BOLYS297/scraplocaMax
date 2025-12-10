<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;

class rental_sources extends Model
{
    use HasFactory,Notifiable;

    protected $fillable = [
        'source_url',
        'source_type',
        'name_or_title',
        'phone_number',
        'email',
        'property_type',
        'city',
        'district',
        'is_qualified',
    ];
}
