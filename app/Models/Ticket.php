<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Ticket extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'ticket_no', 'passenger_type', 'email', 'email_verified_at', 'status'
    ];

    /**
     * The "booting" method of the model.
     *
     * Cette méthode est appelée au démarrage du modèle et nous permet
     * d'intercepter des événements. Ici, nous générons un ticket_no
     * avant qu'un nouveau ticket ne soit créé.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($ticket) {
            // Trouve le dernier ticket_no existant en base de données, trié par ordre décroissant
            $lastTicket = self::orderBy('ticket_no', 'desc')->first();

            if ($lastTicket) {
                // Extrait la partie numérique, la convertit en décimal, l'incrémente et la reconvertit en hexadécimal
                $lastNumber = substr($lastTicket->ticket_no, 1); // Enlève le préfixe 'J'
                $decimal = hexdec($lastNumber);
                $newDecimal = $decimal + 1;
                $newHex = dechex($newDecimal);
                $ticket_no = 'J' . str_pad(strtoupper($newHex), 8, '0', STR_PAD_LEFT);
            } else {
                // S'il n'y a pas de ticket existant, commence la numérotation à 1
                $ticket_no = 'J00000001';
            }

            $ticket->ticket_no = $ticket_no;
        });
    }

    protected $dates = ['email_verified_at', 'deleted_at'];

    public function passengerForm()
    {
        return $this->hasOne(PassengerForm::class);
    }

    // public function decisions()
    // {
    //     return $this->hasMany(Decision::class);
    // }
}
