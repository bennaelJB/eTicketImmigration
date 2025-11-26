<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Port extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'code', 'type', 'location', 'status'];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function passengerForms()
    {
        return $this->hasMany(PassengerForm::class, 'port_of_entry');
    }

    public function decisions(): HasMany
    {
        // On spécifie la clé étrangère ('port_of_action') et la clé locale ('id').
        return $this->hasMany(Decision::class, 'port_of_action');
    }

    public function decisionsStats()
    {
        return $this->hasOne(Decision::class, 'port_of_action')
            ->selectRaw('port_of_action')
            ->selectRaw('COUNT(*) as total_decisions')
            ->selectRaw("SUM(CASE WHEN action_type = 'arrival' THEN 1 ELSE 0 END) as arrivals")
            ->selectRaw("SUM(CASE WHEN action_type = 'departure' THEN 1 ELSE 0 END) as departures")
            ->selectRaw("SUM(CASE WHEN decision = 'accepted' THEN 1 ELSE 0 END) as accepted")
            ->selectRaw("SUM(CASE WHEN decision = 'rejected' THEN 1 ELSE 0 END) as rejected")
            ->groupBy('port_of_action');
    }
}
