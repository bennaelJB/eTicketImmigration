<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Port extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'type', 'location', 'status'];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    /**
     * Un port est le port d'action pour plusieurs décisions.
     * La clé étrangère est 'port_of_action' sur la table 'decisions'.
     *
     * @return HasMany
     */
    // public function decisions(): HasMany
    // {
    //     // On spécifie la clé étrangère ('port_of_action') et la clé locale ('id').
    //     return $this->hasMany(Decision::class, 'port_of_action');
    // }

    /**
     * Calcule et retourne les statistiques agrégées des décisions liées au port.
     * C'est une relation HasOne, car on veut une seule ligne d'agrégation par Port.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    // public function decisionsStats()
    // {
    //     return $this->hasOne(Decision::class, 'port_of_action')
    //         ->selectRaw('port_of_action')
    //         ->selectRaw('COUNT(*) as total_decisions')
    //         ->selectRaw("SUM(CASE WHEN action_type = 'arrival' THEN 1 ELSE 0 END) as arrivals")
    //         ->selectRaw("SUM(CASE WHEN action_type = 'departure' THEN 1 ELSE 0 END) as departures")
    //         ->selectRaw("SUM(CASE WHEN decision = 'accepted' THEN 1 ELSE 0 END) as accepted")
    //         ->selectRaw("SUM(CASE WHEN decision = 'rejected' THEN 1 ELSE 0 END) as rejected")
    //         ->groupBy('port_of_action');
    // }
}
