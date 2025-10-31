<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Casts\Attribute; // Ajouté pour les Accessors modernes

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name', 'email', 'password', 'role', 'status', 'port_id'
    ];

    protected $hidden = [
        'password', 'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    /**
     * ATTENTION: Il faut s'assurer que le modèle App\Models\Port existe
     * et est correctement défini pour que cette relation fonctionne.
     */
    public function port()
    {
        // Assurez-vous que le modèle Port existe.
        return $this->belongsTo(Port::class);
    }

    // public function decisions()
    // {
    //     return $this->hasMany(Decision::class);
    // }

    // --- Nouveauté: Assesseur et Appends pour port_name ---

    /**
     * Indique au modèle d'inclure l'attribut 'port_name'
     * lorsqu'il est sérialisé en tableau ou JSON.
     * * @var array
     */
    protected $appends = ['port_name'];

    /**
     * Accesseur pour l'attribut port_name.
     * Il récupère le nom du port via la relation.
     * * @return \Illuminate\Database\Eloquent\Casts\Attribute
     */
    protected function portName(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->port ? $this->port->name : 'Non assigné',
        );
    }
}
