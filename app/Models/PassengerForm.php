<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Ticket;
use App\Models\Port;

class PassengerForm extends Model
{
    use HasFactory;

    protected $fillable = [
        'ticket_id', 'last_name', 'first_name', 'date_of_birth', 'sex',
        'birth_place', 'nationality', 'passport_number', 'number_of_family_members',
        'family_members', 'carrier_number','port_of_entry', 'travel_purpose',
        'visa_number', 'visa_issued_at','residence_street', 'residence_city',
        'residence_country', 'haiti_street', 'haiti_city', 'haiti_phone', 'travel_date'
    ];

    public function ticket()
    {
        return $this->belongsTo(Ticket::class);
    }

    public function portOfEntry()
    {
        return $this->belongsTo(Port::class, 'port_of_entry');
    }

    public function portOfEntryName()
    {
        return $this->portOfEntry ? $this->portOfEntry->name : null;
    }
}
