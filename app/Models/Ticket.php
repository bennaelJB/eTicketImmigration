<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Ticket extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'ticket_no',
        'status',
        'passenger_type',
        'email',
        'parent_no',
        'children_no',
    ];

    protected $attributes = [
        'status' => 'pending',
    ];

    protected $casts = [
        'children_no' => 'array',
    ];

    protected $dates = ['deleted_at'];

    /**
     * Relation : Un ticket a un formulaire passager
     */
    public function passengerForm()
    {
        return $this->hasOne(PassengerForm::class);
    }

    /**
     * Relation : Un ticket a plusieurs décisions
     */
    public function decisions()
    {
        return $this->hasMany(Decision::class);
    }

    /**
     * Scope : Filtrer les tickets par préfixe
     */
    public function scopeByPrefix($query, $prefix)
    {
        return $query->where('ticket_no', 'LIKE', $prefix . '%');
    }

    /**
     * Scope : Filtrer les tickets mixtes (préfixe 'G')
     */
    public function scopeMixte($query)
    {
        return $query->where('ticket_no', 'LIKE', 'G%');
    }

    /**
     * Accessor : Vérifier si le ticket est mixte
     */
    public function getIsMixteAttribute()
    {
        return substr($this->ticket_no, 0, 1) === 'G';
    }

    /**
     * Accessor : Obtenir le type de service basé sur le préfixe
     */
    public function getServiceTypeAttribute()
    {
        $prefix = substr($this->ticket_no, 0, 1);

        return match($prefix) {
            'G' => 'mixte',
            'J' => 'immigration',
            'C' => 'customs',
            default => 'unknown',
        };
    }
}
