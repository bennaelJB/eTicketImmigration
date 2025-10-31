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
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;

class TicketController extends Controller
{
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
     * Enregistrer un nouveau ticket + formulaire passager
     */
    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'passenger_type' => 'required|in:haitian,foreigner',
            'last_name' => 'required|string|max:255',
            'first_name' => 'required|string|max:255',
            'date_of_birth' => 'required|date',
            'sex' => 'required|in:M,F',
            'birth_place' => 'required|string|max:255',
            'passport_number' => 'required|string|max:255',
            'carrier_number' => 'required|string|max:255',
            'port_of_entry' => 'required',
            'residence_street' => 'required|string|max:255',
            'residence_city' => 'required|string|max:255',
            'residence_country' => 'required|string|max:255',
            'haiti_street' => 'required|string|max:255',
            'haiti_city' => 'required|string|max:255',
            'haiti_phone' => 'required|string|max:255',
            'nationality' => 'nullable|string|max:255',
            'travel_purpose' => 'nullable|in:business,recreation,other',
            'visa_number' => 'nullable|string|max:255',
            'visa_issued_at' => 'nullable|date',
        ]);

        if ($validatedData['passenger_type'] === 'haitian') {
            $validatedData['nationality'] = 'Haïtienne';
        }

        foreach (['date_of_birth', 'visa_issued_at'] as $field) {
            if (!empty($validatedData[$field])) {
                $validatedData[$field] = Carbon::parse($validatedData[$field])->toDateTimeString();
            }
        }

        $ticket = Ticket::create([
            'passenger_type' => $validatedData['passenger_type'],
        ]);

        $passengerForm = new PassengerForm($validatedData);
        $ticket->passengerForm()->save($passengerForm);

        return response()->json([
            'message' => 'Ticket créé avec succès.',
            'ticket_no' => $ticket->ticket_no
        ], 200);
    }

    /**
     * Voir un ticket existant en fournissant ticket_no + passport_number
     */
    public function showTicket(Request $request)
    {
        $ticketNo = $request->query('ticket_no');
        $passportNumber = $request->query('passport_number');

        if (!$ticketNo || !$passportNumber) {
            return response()->json(['message' => 'Les paramètres ticket_no et passport_number sont requis.'], 400);
        }

        $ticket = Ticket::where('ticket_no', $ticketNo)
                        ->whereHas('passengerForm', function($q) use ($passportNumber) {
                            $q->where('passport_number', $passportNumber);
                        })
                        ->with('passengerForm')
                        ->first();

        if (!$ticket) {
            return response()->json([
                'message' => 'Ticket introuvable ou numéro de passeport incorrect.'
            ], 404);
        }

        return response()->json([
            'message' => 'Ticket récupéré avec succès.',
            'ticket' => $ticket,
            'passengerForm' => $ticket->passengerForm,
            'passport_number' => $ticket->passengerForm->passport_number,
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
     * Envoyer le ticket par email (si l'email a été renseigné après coup)
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
                'message' => 'Le ticket a été envoyé avec succès à ' . $ticket->email . '.',
            ]);

        } catch (\Exception $e) {
            Log::error("Erreur d'envoi d'email pour le ticket {$ticket->id}: " . $e->getMessage());

            return response()->json([
                'message' => 'Une erreur est survenue lors de l\'envoi de l\'e-mail.',
            ], 500);
        }
    }
}
