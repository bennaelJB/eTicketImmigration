<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Ticket;
use App\Models\PassengerForm;
use App\Models\Port;
use Carbon\Carbon;
use PDF;
use Illuminate\Support\Facades\Mail;
use App\Mail\TicketMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;

class TicketController extends Controller
{
    /**
     * Récupère le dernier ticket avec préfixe 'G' (mixte)
     */
    public function getLastMixteTicket()
    {
        try {
            $lastMixteTicket = Ticket::where('ticket_no', 'LIKE', 'G%')
                                    ->orderBy('id', 'desc')
                                    ->first();

            if ($lastMixteTicket) {
                return response()->json([
                    'success' => true,
                    'ticket_no' => $lastMixteTicket->ticket_no,
                    'created_at' => $lastMixteTicket->created_at,
                ]);
            }

            return response()->json([
                'success' => true,
                'ticket_no' => null,
                'message' => 'Aucun ticket mixte trouvé'
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération du dernier ticket mixte: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération du dernier ticket mixte',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Génère le prochain numéro de ticket selon le type
     * @param string|null $ticketNo - Numéro de ticket pré-défini (pour tickets mixtes)
     * @return string
     */
    private function generateTicketNumber($ticketNo = null)
    {
        // Si un ticket_no est fourni (cas des tickets mixtes avec préfixe 'G'), l'utiliser
        if (!empty($ticketNo)) {
            return $ticketNo;
        }

        // Génération normale avec préfixe C (Customs) ou J (Immigration)
        $prefix = env('TICKET_PREFIX', 'J'); // 'C' pour Customs, 'J' pour Immigration

        $lastTicket = Ticket::withTrashed()
                        ->where('ticket_no', 'LIKE', $prefix . '%')
                        ->orderBy('id', 'desc')
                        ->first();

        if ($lastTicket && $lastTicket->ticket_no) {
            $lastNumber = substr($lastTicket->ticket_no, 1);
            $decimal = hexdec($lastNumber);
            $newDecimal = $decimal + 1;
            $newHex = dechex($newDecimal);
            return $prefix . str_pad(strtoupper($newHex), 8, '0', STR_PAD_LEFT);
        }

        return $prefix . '00000001';
    }

    /**
     * Prépare les données du ticket pour PDF/JSON : relations + QR code
     */
    private function prepareTicketData(Ticket $ticket): array
    {
        $ticket->load('passengerForm');
        $passengerForm = $ticket->passengerForm;

        if ($passengerForm && $passengerForm->port_of_entry) {
            $port = Port::find($passengerForm->port_of_entry);
            if ($port) {
                $passengerForm->port_of_entry_name = $port->name;
            }
        }

        $fullName = "{$passengerForm->first_name} {$passengerForm->last_name}";

        $qrCode = new QrCode(
            data: route('tickets.show', ['ticket_no' => $ticket->ticket_no]),
            size: 200,
            margin: 10
        );

        $writer = new PngWriter();
        $result = $writer->write($qrCode);
        $qrCodeBase64 = base64_encode($result->getString());

        return [
            'ticket_no' => $ticket->ticket_no,
            'ticket' => $ticket,
            'passengerForm' => $passengerForm,
            'qrCodeBase64' => $qrCodeBase64,
            'full_name' => $fullName,
        ];
    }

    /**
     * Créer un nouveau ticket avec gestion des membres de famille
     */
    public function store(Request $request)
    {
        // Validation de base
        $request->validate([
            'passenger_type' => 'required|string',
            'last_name' => 'required|string',
            'first_name' => 'required|string',
            'date_of_birth' => 'required|date',
            'sex' => 'required|string',
            'passport_number' => 'required|string',
            'nationality' => 'required|string',
            'number_of_family_members' => 'nullable|integer|min:0',
            'family_members' => 'nullable|array',
            'travel_date' => 'required|date|after_or_equal:today',
            'port_of_entry' => 'required|string',
            // Support pour tickets mixtes
            'ticket_no' => 'nullable|string|regex:/^G[0-9A-F]{8}$/i',
            'is_mixte' => 'nullable|boolean',
        ]);

        DB::beginTransaction();

        try {
            // Convertir le code du port en ID
            $portId = null;
            if ($request->filled('port_of_entry')) {
                $port = Port::where('code', $request->port_of_entry)->first();

                if (!$port) {
                    return response()->json([
                        'success' => false,
                        'message' => "Port invalide : {$request->port_of_entry}"
                    ], 422);
                }

                $portId = $port->id;
            }

            // Générer le numéro de ticket (ou utiliser celui fourni pour les tickets mixtes)
            $ticketNumber = $this->generateTicketNumber($request->ticket_no ?? null);

            // 1️⃣ Création du ticket principal
            $ticket = Ticket::create([
                'ticket_no' => $ticketNumber,
                'passenger_type' => $request->passenger_type,
                'email' => $request->email ?? null,
                'email_verified_at' => $request->email_verified_at ?? null,
                'status' => 'draft',
                'parent_no' => null,
                'children_no' => [],
            ]);

            // 2️⃣ Création du PassengerForm principal
            $passengerFormData = $request->only([
                'last_name', 'first_name', 'date_of_birth', 'sex', 'birth_place',
                'nationality', 'passport_number', 'carrier_number',
                'travel_purpose', 'visa_number', 'visa_issued_at',
                'residence_street', 'residence_city', 'residence_country',
                'haiti_street', 'haiti_city', 'haiti_phone', 'travel_date',
                'length_of_stay', 'flight_number', 'residence_state', 'residence_postal_code',
                'have_merchandise', 'is_carrying_expensive', 'have_special_product',
                'declared_items'
            ]);

            // Ajouter le port_of_entry converti
            $passengerFormData['port_of_entry'] = $portId;

            $passengerForm = $ticket->passengerForm()->create($passengerFormData);

            $childrenTicketNos = [];
            $childrenTickets = [];

            // 3️⃣ Gestion des membres de la famille
            if ($request->filled('family_members') && is_array($request->family_members)) {
                foreach ($request->family_members as $member) {
                    // Générer un numéro pour chaque enfant (toujours avec le préfixe normal)
                    $childTicketNumber = $this->generateTicketNumber();

                    // Fusionner les données de base avec les données du membre
                    $childData = array_merge($passengerFormData, $member);
                    $childData['port_of_entry'] = $portId;

                    $childTicket = Ticket::create([
                        'ticket_no' => $childTicketNumber,
                        'passenger_type' => $ticket->passenger_type,
                        'email' => $ticket->email,
                        'email_verified_at' => $ticket->email_verified_at,
                        'status' => 'draft', // ← status à draft dès la création
                        'parent_no' => $ticket->ticket_no,
                        'children_no' => null,
                    ]);

                    $childTicket->passengerForm()->create($childData);

                    $childrenTicketNos[] = $childTicket->ticket_no;
                    $childrenTickets[] = $childTicket;
                }

                // Mettre à jour le ticket parent avec les numéros des enfants
                $ticket->children_no = $childrenTicketNos;
                $ticket->passengerForm->update([
                    'family_members' => $request->family_members,
                    'number_of_family_members' => count($request->family_members),
                ]);
                $ticket->save();
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'ticket_no' => $ticket->ticket_no,
                'passport_number' => $ticket->passengerForm->passport_number,
                'children_tickets' => $childrenTicketNos,
            ], 201);

        } catch (\Exception $e) {
            // Supprime tous les tickets enfants créés si erreur
            if (!empty($childrenTickets)) {
                foreach ($childrenTickets as $child) {
                    $child->passengerForm()->delete();
                    $child->delete();
                }
            }

            DB::rollBack();

            Log::error('Erreur lors de la création du ticket : ' . $e->getMessage() . ' | Data: ' . json_encode($request->all()));

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création du ticket : ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Récupérer un ticket par son ticket_no
     */
    public function getTicket($ticket_no)
    {
        try {
            $ticket = Ticket::where('ticket_no', $ticket_no)
                           ->with('passengerForm')
                           ->first();

            if (!$ticket) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ticket introuvable.'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'ticket' => $ticket,
                'passengerForm' => $ticket->passengerForm,
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération du ticket: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération du ticket',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Voir un ticket existant en fournissant ticket_no + passport_number
     */
    public function showTicket(Request $request)
    {
        $ticketNo = $request->query('ticket_no');
        $passportNumber = $request->query('passport_number');

        if (!$ticketNo || !$passportNumber) {
            return response()->json([
                'success' => false,
                'message' => 'Les paramètres ticket_no et passport_number sont requis.'
            ], 400);
        }

        $ticket = Ticket::where('ticket_no', $ticketNo)
                        ->whereHas('passengerForm', function($q) use ($passportNumber) {
                            $q->where('passport_number', $passportNumber);
                        })
                        ->with('passengerForm')
                        ->first();

        if (!$ticket) {
            return response()->json([
                'success' => false,
                'message' => 'Ticket introuvable ou numéro de passeport incorrect.'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Ticket récupéré avec succès.',
            'ticket' => $ticket,
            'passengerForm' => $ticket->passengerForm,
        ]);
    }

    /**
     * Télécharger le ticket en PDF
     */
    public function downloadPdf($ticket_no)
    {
        $ticket = Ticket::where('ticket_no', $ticket_no)->firstOrFail();
        $data = $this->prepareTicketData($ticket);
        $fileName = "e-ticket-{$data['ticket']->ticket_no}.pdf";
        $pdf = PDF::loadView('pdf.ticket', $data);
        return $pdf->download($fileName);
    }

    /**
     * Télécharger le ticket (alias de downloadPdf)
     */
    public function download($ticket_no)
    {
        return $this->downloadPdf($ticket_no);
    }

    /**
     * Envoyer le ticket par email
     */
    public function sendEmail(Request $request, $ticket_no)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $ticket = Ticket::where('ticket_no', $ticket_no)->firstOrFail();
        $ticket->email = $request->email;
        $ticket->save();

        try {
            $data = $this->prepareTicketData($ticket);
            $pdf = PDF::loadView('pdf.ticket', $data);
            $pdfData = $pdf->output();
            Mail::to($ticket->email)->send(new TicketMail($data['ticket'], $pdfData, $data['qrCodeBase64'], $data['full_name']));

            return response()->json([
                'success' => true,
                'message' => 'Le ticket a été envoyé avec succès à ' . $ticket->email . '.',
            ]);

        } catch (\Exception $e) {
            Log::error("Erreur d'envoi d'email pour le ticket {$ticket->id}: " . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de l\'envoi de l\'e-mail.',
            ], 500);
        }
    }

    /**
     * Rechercher des tickets (avec index sur ticket_no, status, passenger_type)
     */
    public function search(Request $request)
    {
        try {
            $query = Ticket::with('passengerForm');

            // Filtre par ticket_no (index)
            if ($request->has('ticket_no')) {
                $query->where('ticket_no', $request->ticket_no);
            }

            // Filtre par status (index)
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            // Filtre par passenger_type (index)
            if ($request->has('passenger_type')) {
                $query->where('passenger_type', $request->passenger_type);
            }

            // Filtre par date
            if ($request->has('from_date')) {
                $query->whereDate('created_at', '>=', $request->from_date);
            }

            if ($request->has('to_date')) {
                $query->whereDate('created_at', '<=', $request->to_date);
            }

            // Recherche par nom (via relation)
            if ($request->has('name')) {
                $query->whereHas('passengerForm', function($q) use ($request) {
                    $q->where('first_name', 'LIKE', "%{$request->name}%")
                      ->orWhere('last_name', 'LIKE', "%{$request->name}%");
                });
            }

            // Recherche par passeport (via relation, index recommandé)
            if ($request->has('passport_number')) {
                $query->whereHas('passengerForm', function($q) use ($request) {
                    $q->where('passport_number', $request->passport_number);
                });
            }

            $tickets = $query->orderBy('created_at', 'desc')->paginate(20);

            return response()->json([
                'success' => true,
                'tickets' => $tickets
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur lors de la recherche de tickets: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la recherche',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Rechercher des voyageurs
     */
    public function searchTravelers(Request $request)
    {
        try {
            $query = PassengerForm::with('ticket');

            // Recherche par nom
            if ($request->has('name')) {
                $query->where(function($q) use ($request) {
                    $q->where('first_name', 'LIKE', "%{$request->name}%")
                      ->orWhere('last_name', 'LIKE', "%{$request->name}%");
                });
            }

            // Recherche par passeport (index recommandé)
            if ($request->has('passport_number')) {
                $query->where('passport_number', $request->passport_number);
            }

            // Recherche par nationalité
            if ($request->has('nationality')) {
                $query->where('nationality', $request->nationality);
            }

            $travelers = $query->orderBy('created_at', 'desc')->paginate(20);

            return response()->json([
                'success' => true,
                'travelers' => $travelers
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur lors de la recherche de voyageurs: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la recherche',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
