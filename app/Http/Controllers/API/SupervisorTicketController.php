<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Decision;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class SupervisorTicketController extends Controller
{
    /**
     * Liste paginée et filtrable de tous les tickets traités dans le port du superviseur.
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        $portId = $user->port_id;

        // Paramètres de pagination et de tri
        $perPage = $request->input('per_page', 15);
        $currentPage = $request->input('page', 1);
        $sortBy = $request->input('sort_by', 'decisions.created_at');
        $sortDirection = $request->input('sort_direction', 'desc');

        // Filtres
        $searchQuery = $request->input('search', null);
        $statusFilter = $request->input('status', null);
        $decisionFilter = $request->input('decision', null);
        $travelTypeFilter = $request->input('travel_type', null);
        $agentFilter = $request->input('agent', null);
        $dateFrom = $request->input('date_from', null);
        $dateTo = $request->input('date_to', null);

        Log::info("Récupération des tickets du port {$portId}", [
            'search' => $searchQuery,
            'status' => $statusFilter,
            'decision' => $decisionFilter,
            'page' => $currentPage,
            'per_page' => $perPage
        ]);

        // Construction de la requête
        $query = DB::table('decisions')
            ->join('users', 'decisions.user_id', '=', 'users.id')
            ->join('tickets', 'decisions.ticket_id', '=', 'tickets.id')
            ->leftJoin('passenger_forms', 'tickets.id', '=', 'passenger_forms.ticket_id')
            ->select(
                'decisions.id',
                'tickets.ticket_no',
                'tickets.status',
                DB::raw("CONCAT(COALESCE(passenger_forms.first_name, ''), ' ', COALESCE(passenger_forms.last_name, '')) as traveler_name"),
                'passenger_forms.passport_number as passport_no',
                'passenger_forms.nationality',
                'decisions.action_type as travel_type',
                'decisions.decision',
                'decisions.comment',
                'users.name as agent_name',
                'users.id as agent_id',
                'decisions.created_at as action_date',
                'decisions.port_of_action'
            )
            ->where('users.port_id', $portId);

        // Filtre de recherche
        if ($searchQuery) {
            $query->where(function($q) use ($searchQuery) {
                $q->where('tickets.ticket_no', 'like', "%{$searchQuery}%")
                  ->orWhere('passenger_forms.first_name', 'like', "%{$searchQuery}%")
                  ->orWhere('passenger_forms.last_name', 'like', "%{$searchQuery}%")
                  ->orWhere('passenger_forms.passport_number', 'like', "%{$searchQuery}%")
                  ->orWhere('passenger_forms.nationality', 'like', "%{$searchQuery}%")
                  ->orWhere('users.name', 'like', "%{$searchQuery}%");
            });
        }

        // Filtre par statut du ticket
        if ($statusFilter) {
            $query->where('tickets.status', $statusFilter);
        }

        // Filtre par décision
        if ($decisionFilter) {
            $query->where('decisions.decision', $decisionFilter);
        }

        // Filtre par type de voyage
        if ($travelTypeFilter) {
            $query->where('decisions.action_type', $travelTypeFilter);
        }

        // Filtre par agent
        if ($agentFilter) {
            $query->where('users.id', $agentFilter);
        }

        // Filtre par date
        if ($dateFrom) {
            $query->where('decisions.created_at', '>=', Carbon::parse($dateFrom)->startOfDay());
        }
        if ($dateTo) {
            $query->where('decisions.created_at', '<=', Carbon::parse($dateTo)->endOfDay());
        }

        // Tri
        $allowedSorts = [
            'ticket_no' => 'tickets.ticket_no',
            'traveler_name' => 'traveler_name',
            'passport_no' => 'passenger_forms.passport_number',
            'nationality' => 'passenger_forms.nationality',
            'action_date' => 'decisions.created_at',
            'agent_name' => 'users.name',
            'decision' => 'decisions.decision',
            'travel_type' => 'decisions.action_type',
            'status' => 'tickets.status'
        ];

        $sortColumn = $allowedSorts[$sortBy] ?? 'decisions.created_at';
        $query->orderBy($sortColumn, $sortDirection);

        // Pagination
        $tickets = $query->paginate($perPage, ['*'], 'page', $currentPage);

        Log::info("Tickets récupérés : {$tickets->total()} trouvés");

        return response()->json([
            'success' => true,
            'message' => 'Tickets récupérés avec succès',
            'data' => $tickets->items(),
            'pagination' => [
                'current_page' => $tickets->currentPage(),
                'last_page' => $tickets->lastPage(),
                'per_page' => $tickets->perPage(),
                'total' => $tickets->total(),
                'from' => $tickets->firstItem(),
                'to' => $tickets->lastItem(),
            ],
            'filters' => [
                'search' => $searchQuery,
                'status' => $statusFilter,
                'decision' => $decisionFilter,
                'travel_type' => $travelTypeFilter,
                'agent' => $agentFilter,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
            'sorting' => [
                'sort_by' => $sortBy,
                'sort_direction' => $sortDirection,
            ]
        ], 200);
    }

    /**
     * Détails complets d'un ticket spécifique.
     */
    public function show(Request $request, $ticketId)
    {
        $user = Auth::user();
        $portId = $user->port_id;

        try {
            // Vérifier que le ticket a été traité dans ce port
            $ticket = Ticket::with(['passengerForm', 'decisions.user'])
                ->whereHas('decisions', function($query) use ($portId) {
                    $query->whereHas('user', function($q) use ($portId) {
                        $q->where('port_id', $portId);
                    });
                })
                ->findOrFail($ticketId);

            // Formater les décisions
            $decisionsFormatted = $ticket->decisions->map(function($decision) {
                return [
                    'id' => $decision->id,
                    'action_type' => $decision->action_type,
                    'decision' => $decision->decision,
                    'comment' => $decision->comment,
                    'port_of_action' => $decision->port_of_action,
                    'agent' => [
                        'id' => $decision->user?->id,
                        'name' => $decision->user?->name,
                        'email' => $decision->user?->email,
                    ],
                    'created_at' => $decision->created_at->toIso8601String(),
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Détails du ticket récupérés avec succès',
                'data' => [
                    'ticket' => [
                        'id' => $ticket->id,
                        'ticket_no' => $ticket->ticket_no,
                        'status' => $ticket->status,
                        'qr_code' => $ticket->qr_code,
                        'created_at' => $ticket->created_at->toIso8601String(),
                    ],
                    'passenger' => $ticket->passengerForm ? [
                        'first_name' => $ticket->passengerForm->first_name,
                        'last_name' => $ticket->passengerForm->last_name,
                        'passport_number' => $ticket->passengerForm->passport_number,
                        'nationality' => $ticket->passengerForm->nationality,
                        'date_of_birth' => $ticket->passengerForm->date_of_birth,
                        'gender' => $ticket->passengerForm->gender,
                        'travel_purpose' => $ticket->passengerForm->travel_purpose,
                        'email' => $ticket->passengerForm->email,
                        'phone' => $ticket->passengerForm->phone,
                    ] : null,
                    'decisions' => $decisionsFormatted,
                    'statistics' => [
                        'total_scans' => $ticket->decisions->count(),
                        'accepted' => $ticket->decisions->where('decision', 'accepted')->count(),
                        'rejected' => $ticket->decisions->where('decision', 'rejected')->count(),
                        'arrivals' => $ticket->decisions->where('action_type', 'arrival')->count(),
                        'departures' => $ticket->decisions->where('action_type', 'departure')->count(),
                    ]
                ]
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            Log::warning("Ticket {$ticketId} introuvable ou non accessible par le port {$portId}");
            return response()->json([
                'success' => false,
                'message' => 'Ticket introuvable ou non accessible depuis ce port.'
            ], 404);
        } catch (\Exception $e) {
            Log::error("Erreur lors de la récupération du ticket {$ticketId}: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération du ticket.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mise à jour d'un ticket (pour corrections administratives).
     */
    public function update(Request $request, $ticketId)
    {
        $user = Auth::user();
        $portId = $user->port_id;

        try {
            // Vérifier que le ticket existe et a été traité dans ce port
            $ticket = Ticket::whereHas('decisions', function($query) use ($portId) {
                    $query->whereHas('user', function($q) use ($portId) {
                        $q->where('port_id', $portId);
                    });
                })
                ->findOrFail($ticketId);

            $validatedData = $request->validate([
                'status' => ['sometimes', 'string', 'in:valid,used,expired,cancelled'],
                'comment' => ['sometimes', 'nullable', 'string', 'max:500'],
            ]);

            if (isset($validatedData['status'])) {
                $ticket->status = $validatedData['status'];
            }

            $ticket->save();

            Log::info("Ticket {$ticketId} mis à jour par le superviseur {$user->id}");

            return response()->json([
                'success' => true,
                'message' => 'Ticket mis à jour avec succès.',
                'data' => [
                    'ticket' => [
                        'id' => $ticket->id,
                        'ticket_no' => $ticket->ticket_no,
                        'status' => $ticket->status,
                    ]
                ]
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ticket introuvable ou non accessible.'
            ], 404);
        } catch (\Exception $e) {
            Log::error("Erreur mise à jour ticket: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export des tickets en CSV.
     */
    public function export(Request $request)
    {
        $user = Auth::user();
        $portId = $user->port_id;

        // Récupérer tous les tickets avec les mêmes filtres
        $searchQuery = $request->input('search');
        $statusFilter = $request->input('status');
        $decisionFilter = $request->input('decision');

        $query = DB::table('decisions')
            ->join('users', 'decisions.user_id', '=', 'users.id')
            ->join('tickets', 'decisions.ticket_id', '=', 'tickets.id')
            ->leftJoin('passenger_forms', 'tickets.id', '=', 'passenger_forms.ticket_id')
            ->select(
                'tickets.ticket_no',
                'passenger_forms.first_name',
                'passenger_forms.last_name',
                'passenger_forms.passport_number',
                'passenger_forms.nationality',
                'decisions.action_type',
                'tickets.status',
                'decisions.decision',
                'users.name as agent_name',
                'decisions.created_at as action_date',
                'decisions.comment'
            )
            ->where('users.port_id', $portId);

        if ($searchQuery) {
            $query->where(function($q) use ($searchQuery) {
                $q->where('tickets.ticket_no', 'like', "%{$searchQuery}%")
                  ->orWhere('passenger_forms.first_name', 'like', "%{$searchQuery}%")
                  ->orWhere('passenger_forms.last_name', 'like', "%{$searchQuery}%");
            });
        }

        if ($statusFilter) {
            $query->where('tickets.status', $statusFilter);
        }

        if ($decisionFilter) {
            $query->where('decisions.decision', $decisionFilter);
        }

        $tickets = $query->orderByDesc('decisions.created_at')->get();

        // Créer le CSV
        $filename = 'tickets_export_' . Carbon::now()->format('Y-m-d_His') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function() use ($tickets) {
            $file = fopen('php://output', 'w');

            // En-têtes CSV
            fputcsv($file, [
                'Numéro Ticket',
                'Prénom',
                'Nom',
                'Passeport',
                'Nationalité',
                'Type',
                'Statut',
                'Décision',
                'Agent',
                'Date Action',
                'Commentaire'
            ]);

            // Données
            foreach ($tickets as $ticket) {
                fputcsv($file, [
                    $ticket->ticket_no,
                    $ticket->first_name,
                    $ticket->last_name,
                    $ticket->passport_number,
                    $ticket->nationality,
                    $ticket->action_type === 'arrival' ? 'Arrivée' : 'Départ',
                    $ticket->status,
                    $ticket->decision === 'accepted' ? 'Accepté' : 'Rejeté',
                    $ticket->agent_name,
                    Carbon::parse($ticket->action_date)->format('d/m/Y H:i'),
                    $ticket->comment ?? ''
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }
}
