<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\Decision;
use App\Models\Port;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AgentController extends Controller
{
    /**
     * Met à jour les informations du passager associées à un ticket.
     */
    public function update(Request $request)
    {
        if (Auth::user()->role !== 'agent') {
            return response()->json([
                'success' => false,
                'message' => 'Accès non autorisé. Seuls les agents peuvent mettre à jour les tickets.'
            ], 403);
        }

        try {
            $validatedData = $request->validate([
                'ticket_id' => 'required|exists:tickets,id',
                'passenger_form' => 'required|array',
                'passenger_form.first_name' => 'required|string',
                'passenger_form.last_name' => 'required|string',
                'passenger_form.initials' => 'nullable|string',
                'passenger_form.date_of_birth' => 'required|date',
                'passenger_form.nationality' => 'required|string',
                'passenger_form.passport_number' => 'required|string',
                'passenger_form.carrier_number' => 'nullable|string',
                'passenger_form.company' => 'nullable|string',
                'passenger_form.port_of_entry_id' => 'nullable|integer|exists:ports,id', // ✅ Correction
                'passenger_form.residence_country' => 'nullable|string',
                'passenger_form.residence_city' => 'nullable|string',
                'passenger_form.residence_state' => 'nullable|string',
                'passenger_form.residence_postal_code' => 'nullable|string',
                'passenger_form.haiti_street' => 'nullable|string',
                'passenger_form.haiti_city' => 'nullable|string',
                'passenger_form.family_members' => 'nullable|array',
                'passenger_form.declared_items' => 'nullable|array',
            ]);

            $ticket = Ticket::findOrFail($validatedData['ticket_id']);

            if (!$ticket->passengerForm) {
                return response()->json([
                    'success' => false,
                    'message' => 'Formulaire passager introuvable pour ce ticket.'
                ], 404);
            }

            $updateData = $validatedData['passenger_form'];

            // ✅ Gérer port_of_entry : extraire uniquement l'ID
            if (isset($updateData['port_of_entry_id'])) {
                $updateData['port_of_entry'] = $updateData['port_of_entry_id'];
                unset($updateData['port_of_entry_id']);
            }

            // ✅ Gérer family_members : convertir en JSON
            if (isset($updateData['family_members']) && is_array($updateData['family_members'])) {
                $updateData['family_members'] = json_encode($updateData['family_members']);
            }

            // ✅ Gérer declared_items : convertir en JSON
            if (isset($updateData['declared_items']) && is_array($updateData['declared_items'])) {
                $updateData['declared_items'] = json_encode($updateData['declared_items']);
            }

            // ✅ Convertir date_of_birth au bon format
            if (isset($updateData['date_of_birth'])) {
                $updateData['date_of_birth'] = Carbon::parse($updateData['date_of_birth'])->format('Y-m-d');
            }

            $ticket->passengerForm->update($updateData);

            Log::info("Ticket {$ticket->ticket_no} mis à jour par l'agent " . Auth::id());

            return response()->json([
                'success' => true,
                'message' => 'Ticket mis à jour avec succès.',
                'data' => [
                    'ticket' => $ticket->fresh(['passengerForm.portOfEntry'])
                ]
            ], 200);

        } catch (ValidationException $e) {
            Log::error("Erreur de validation lors de la mise à jour d'un ticket: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Les données du formulaire sont invalides.',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error("Erreur inattendue lors de la mise à jour du ticket: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de la mise à jour du ticket.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Gère la soumission de la décision de l'agent.
     */
    public function decide(Request $request)
    {
        $validatedData = $request->validate([
            'ticket_id' => 'required|exists:tickets,id',
            'action_type' => ['required', Rule::in(['arrival', 'departure'])],
            'decision' => ['required', Rule::in(['accepted', 'rejected'])],
            'comment' => 'nullable|string',
            'passenger_form' => 'nullable|array',
            'passenger_form.port_of_entry_id' => 'nullable|integer|exists:ports,id',
        ]);

        $ticket = Ticket::with('passengerForm')->findOrFail($validatedData['ticket_id']);
        $user = Auth::user();

        if (!$user || !$user->port_id) {
            return response()->json([
                'success' => false,
                'message' => 'Utilisateur ou port non trouvé.'
            ], 403);
        }

        if ($ticket->passengerForm && $ticket->passengerForm->port_of_entry != $user->port_id) {
            return response()->json([
                'success' => false,
                'message' => 'Ce ticket n\'est pas associé à votre port d\'entrée.'
            ], 403);
        }

        $existingDecision = Decision::where('ticket_id', $ticket->id)
            ->where('action_type', $validatedData['action_type'])
            ->exists();

        if ($existingDecision) {
            return response()->json([
                'success' => false,
                'message' => "Ce formulaire a déjà été traité pour le type d'action '{$validatedData['action_type']}'.",
            ], 409);
        }

        $warning = null;
        if ($validatedData['action_type'] === 'departure' && !in_array($ticket->status, ['accepted_arrival', 'rejected_arrival'])) {
            $warning = "Ce ticket n'a pas encore été scanné pour une arrivée.";
        }

        DB::beginTransaction();

        try {
            if (isset($validatedData['passenger_form']) && $ticket->passengerForm) {
                $updateData = $validatedData['passenger_form'];

                // ✅ Gérer port_of_entry : extraire uniquement l'ID
                if (isset($updateData['port_of_entry_id'])) {
                    $updateData['port_of_entry'] = $updateData['port_of_entry_id'];
                    unset($updateData['port_of_entry_id']);
                }

                // ✅ Gérer family_members
                if (isset($updateData['family_members']) && is_array($updateData['family_members'])) {
                    $updateData['family_members'] = json_encode($updateData['family_members']);
                }

                // ✅ Gérer declared_items
                if (isset($updateData['declared_items']) && is_array($updateData['declared_items'])) {
                    $updateData['declared_items'] = json_encode($updateData['declared_items']);
                }

                // ✅ Convertir date_of_birth
                if (isset($updateData['date_of_birth'])) {
                    $updateData['date_of_birth'] = Carbon::parse($updateData['date_of_birth'])->format('Y-m-d');
                }

                $ticket->passengerForm->update($updateData);
                Log::info("Formulaire passager du ticket {$ticket->ticket_no} mis à jour avant décision.");
            }

            $newStatus = "{$validatedData['decision']}_{$validatedData['action_type']}";
            $ticket->status = $newStatus;
            $ticket->updated_at = Carbon::now();
            $ticket->save();

            Log::info("Ticket {$ticket->ticket_no} mis à jour au statut {$newStatus}.");

            // ✅ Créer la décision pour le ticket parent
            Decision::create([
                'ticket_id' => $ticket->id,
                'user_id' => $user->id,
                'action_type' => $validatedData['action_type'],
                'decision' => $validatedData['decision'],
                'port_of_action' => $user->port_id,
                'comment' => $validatedData['comment'],
            ]);

            Log::info("Décision enregistrée pour le ticket {$ticket->ticket_no} par l'agent {$user->id}.");

            // ✅ Gérer les enfants si c'est un ticket parent
            if (!empty($ticket->children_no) && is_array($ticket->children_no)) {
                $childStatus = "{$validatedData['decision']}_{$validatedData['action_type']}";
                $childComment = $validatedData['comment']
                    ? "Hérité du ticket parent {$ticket->ticket_no}: " . $validatedData['comment']
                    : "Décision héritée du ticket parent {$ticket->ticket_no}";

                foreach ($ticket->children_no as $childTicketNo) {
                    $childTicket = Ticket::where('ticket_no', $childTicketNo)->first();

                    if (!$childTicket) {
                        Log::warning("Ticket enfant {$childTicketNo} introuvable pour le ticket parent {$ticket->ticket_no}");
                        continue;
                    }

                    // Vérifier si une décision existe déjà pour cet enfant
                    $existingChildDecision = Decision::where('ticket_id', $childTicket->id)
                        ->where('action_type', $validatedData['action_type'])
                        ->exists();

                    if ($existingChildDecision) {
                        Log::info("Décision déjà existante pour le ticket enfant {$childTicketNo}");
                        continue;
                    }

                    // Mettre à jour le statut de l'enfant
                    $childTicket->status = $childStatus;
                    $childTicket->updated_at = Carbon::now();
                    $childTicket->save();

                    // Créer la décision pour l'enfant
                    Decision::create([
                        'ticket_id' => $childTicket->id,
                        'user_id' => $user->id,
                        'action_type' => $validatedData['action_type'],
                        'decision' => $validatedData['decision'],
                        'port_of_action' => $user->port_id,
                        'comment' => $childComment,
                    ]);

                    Log::info("Décision enregistrée pour le ticket enfant {$childTicketNo} (héritée du parent {$ticket->ticket_no})");
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Action enregistrée avec succès.',
                'warning' => $warning,
                'data' => [
                    'ticket_no' => $ticket->ticket_no,
                    'status' => $ticket->status,
                    'decision' => $validatedData['decision'],
                    'action_type' => $validatedData['action_type']
                ]
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Erreur lors de l'enregistrement de la décision: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de l\'enregistrement de la décision.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Scan d'un ticket
     */
    public function scan(Request $request, string $ticketNo)
    {
        $validated = $request->validate([
            'action_type' => ['required', Rule::in(['arrival', 'departure'])],
        ]);

        $actionType = $validated['action_type'];

        $ticket = Ticket::where('ticket_no', $ticketNo)
                        ->with('passengerForm.portOfEntry')
                        ->first();

        if (!$ticket) {
            return response()->json([
                'success' => false,
                'message' => 'Ticket non trouvé.'
            ], 404);
        }

        $departureScannedStatuses = ['accepted_departure', 'rejected_departure'];
        if ($actionType === 'arrival' && in_array($ticket->status, $departureScannedStatuses)) {
            return response()->json([
                'success' => false,
                'message' => 'Ce ticket a déjà été enregistré pour un départ.'
            ], 409);
        }

        $existingDecision = Decision::where('ticket_id', $ticket->id)
            ->where('action_type', $actionType)
            ->exists();

        if ($existingDecision) {
            return response()->json([
                'success' => false,
                'message' => "Ce ticket a déjà fait l'objet d'une décision ({$actionType})."
            ], 409);
        }

        if ($ticket->status === 'pending') {
            $updatedAt = Carbon::parse($ticket->updated_at);
            $pendingTimeout = $updatedAt->addMinutes(2);
            $now = Carbon::now();

            if ($now->lt($pendingTimeout)) {
                $timeRemainingInSeconds = (int) $now->diffInSeconds($pendingTimeout);
                return response()->json([
                    'success' => false,
                    'message' => "Un autre agent est en train de scanner ce ticket.",
                    'remaining_time' => $timeRemainingInSeconds,
                ], 409);
            }
        }

        $ticket->status = 'pending';
        $ticket->updated_at = Carbon::now();
        $ticket->save();

        Log::info("Ticket {$ticketNo} scanné et mis en statut 'pending' par l'agent ID: " . Auth::id());

        $port = $ticket->passengerForm?->portOfEntry;

        if (!$port) {
            return response()->json([
                'success' => false,
                'message' => "Le port d'entrée associé à ce ticket est introuvable."
            ], 404);
        }

        $allPorts = Port::where('status', 'active')->get();

        // ✅ Formater les données du ticket pour éviter les problèmes de référence circulaire
        $ticketData = $ticket->toArray();
        if (isset($ticketData['passenger_form']) && isset($ticketData['passenger_form']['port_of_entry'])) {
            // Remplacer l'objet port_of_entry par son ID pour éviter confusion
            $portId = is_array($ticketData['passenger_form']['port_of_entry'])
                ? $ticketData['passenger_form']['port_of_entry']['id']
                : $ticketData['passenger_form']['port_of_entry'];

            $ticketData['passenger_form']['port_of_entry_id'] = $portId;
        }

        return response()->json([
            'success' => true,
            'message' => 'Ticket scanné avec succès',
            'data' => [
                'ticket' => $ticketData,
                'port_of_action' => $port,
                'ports' => $allPorts,
            ]
        ], 200);
    }

    /**
     * Récupère les données du tableau de bord pour un agent avec insights détaillés.
     */
    public function dashboard(Request $request)
    {
        try {
            $timeFilter = $request->input('filter', 'day');
            $agent = Auth::user();

            if (!$agent) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non authentifié.'
                ], 401);
            }

            // Définir la date de début selon le filtre
            $startDate = match($timeFilter) {
                'day' => Carbon::now()->startOfDay(),
                '3-days' => Carbon::now()->subDays(3)->startOfDay(),
                'week' => Carbon::now()->startOfWeek(),
                'month' => Carbon::now()->startOfMonth(),
                '3-months' => Carbon::now()->subMonths(3)->startOfDay(),
                default => Carbon::now()->startOfDay(),
            };

            Log::info("Dashboard request for agent {$agent->id} with filter: {$timeFilter}, startDate: {$startDate}");

            // Récupérer toutes les décisions de l'agent dans la période
            $decisions = Decision::where('user_id', $agent->id)
                                ->where('created_at', '>=', $startDate)
                                ->with(['ticket.passengerForm'])
                                ->orderBy('created_at', 'desc')
                                ->get();

            Log::info("Found {$decisions->count()} decisions for agent {$agent->id}");

            // Statistiques de base
            $totalTickets = $decisions->count();
            $validatedTickets = $decisions->where('decision', 'accepted')->count();
            $rejectedTickets = $decisions->where('decision', 'rejected')->count();
            $validationRate = $totalTickets > 0 ? round(($validatedTickets / $totalTickets) * 100, 1) : 0;

            // Statistiques par type d'action
            $arrivalDecisions = $decisions->where('action_type', 'arrival');
            $departureDecisions = $decisions->where('action_type', 'departure');

            $arrivalTickets = $arrivalDecisions->count();
            $departureTickets = $departureDecisions->count();

            $acceptedArrivals = $arrivalDecisions->where('decision', 'accepted')->count();
            $rejectedArrivals = $arrivalDecisions->where('decision', 'rejected')->count();

            $acceptedDepartures = $departureDecisions->where('decision', 'accepted')->count();
            $rejectedDepartures = $departureDecisions->where('decision', 'rejected')->count();

            // Top 5 nationalités
            $topNationalities = $decisions
                ->filter(function ($decision) {
                    return $decision->ticket && $decision->ticket->passengerForm;
                })
                ->map(function ($decision) {
                    return $decision->ticket->passengerForm->nationality ?? 'Inconnu';
                })
                ->countBy()
                ->sortDesc()
                ->take(5)
                ->map(function ($count, $nationality) {
                    return [
                        'nationality' => $nationality,
                        'count' => $count
                    ];
                })
                ->values()
                ->toArray();

            // Activité par heure (pour aujourd'hui uniquement)
            $hourlyActivity = [];
            if ($timeFilter === 'day') {
                $hourlyActivity = $decisions
                    ->groupBy(function ($decision) {
                        return $decision->created_at->format('H:00');
                    })
                    ->map(function ($group) {
                        return $group->count();
                    })
                    ->toArray();
            }

            // Statistiques de temps de traitement (moyenne en minutes)
            $processingTimes = $decisions
                ->filter(function ($decision) {
                    return $decision->ticket && $decision->ticket->created_at;
                })
                ->map(function ($decision) {
                    $ticketCreated = Carbon::parse($decision->ticket->created_at);
                    $decisionMade = Carbon::parse($decision->created_at);
                    return $ticketCreated->diffInMinutes($decisionMade);
                });

            $avgProcessingTime = $processingTimes->count() > 0
                ? round($processingTimes->avg(), 1)
                : 0;

            // Dernières 5 actions
            $lastActions = $decisions
                ->take(5)
                ->map(function ($decision) {
                    $ticket = $decision->ticket;
                    $passengerForm = $ticket ? $ticket->passengerForm : null;

                    return [
                        'ticketNo' => $ticket ? $ticket->ticket_no : 'N/A',
                        'nationality' => $passengerForm ? $passengerForm->nationality : 'N/A',
                        'type' => $decision->action_type === 'arrival' ? 'Arrivée' : 'Départ',
                        'action' => $decision->decision === 'accepted' ? 'Accepté' : 'Rejeté',
                        'datetime' => $decision->created_at->toIso8601String(),
                    ];
                })
                ->values()
                ->toArray();

            // Graphique d'évolution
            $chartData = $this->getChartData($decisions, $timeFilter);

            Log::info("Dashboard stats prepared: totalTickets={$totalTickets}, chartDataCount=" . count($chartData));

            return response()->json([
                'success' => true,
                'message' => 'Dashboard data fetched successfully',
                'data' => [
                    // Statistiques principales
                    'totalTickets' => $totalTickets,
                    'validatedTickets' => $validatedTickets,
                    'rejectedTickets' => $rejectedTickets,
                    'validationRate' => $validationRate,

                    // Répartition par type
                    'arrivalTickets' => $arrivalTickets,
                    'departureTickets' => $departureTickets,
                    'acceptedArrivals' => $acceptedArrivals,
                    'rejectedArrivals' => $rejectedArrivals,
                    'acceptedDepartures' => $acceptedDepartures,
                    'rejectedDepartures' => $rejectedDepartures,

                    // Insights additionnels
                    'topNationalities' => $topNationalities,
                    'avgProcessingTime' => $avgProcessingTime,
                    'hourlyActivity' => $hourlyActivity,
                    'chartData' => $chartData,

                    // Dernières actions
                    'lastActions' => $lastActions,

                    // Métadonnées
                    'timeFilter' => $timeFilter,
                    'periodStart' => $startDate->toIso8601String(),
                    'periodEnd' => Carbon::now()->toIso8601String(),
                    'agent' => [
                        'id' => $agent->id,
                        'name' => $agent->name,
                        'email' => $agent->email,
                        'port_id' => $agent->port_id,
                    ]
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error("Erreur dans le dashboard agent: " . $e->getMessage());
            Log::error($e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du chargement du dashboard.',
                'error' => config('app.debug') ? $e->getMessage() : 'Une erreur est survenue.'
            ], 500);
        }
    }

    /**
     * Génère les données pour le graphique d'évolution.
     */
    private function getChartData($decisions, $timeFilter)
    {
        // Déterminer le format de date selon le filtre
        $format = 'Y-m-d';
        $dateKey = 'd/m';

        if ($timeFilter === 'day' || $timeFilter === '3-days') {
            $format = 'Y-m-d H:00';
            $dateKey = 'H:00';
        }

        // Si aucune décision, retourner un tableau vide
        if ($decisions->isEmpty()) {
            Log::info("No decisions found for chart generation");
            return [];
        }

        // Grouper les décisions par période
        $grouped = $decisions->groupBy(function ($decision) use ($format) {
            return $decision->created_at->format($format);
        });

        // Construire les données du graphique
        $chartData = $grouped->map(function ($group, $date) use ($dateKey, $format) {
            $accepted = $group->where('decision', 'accepted')->count();
            $rejected = $group->where('decision', 'rejected')->count();

            try {
                // Formater la date pour l'affichage
                $displayDate = Carbon::createFromFormat($format, $date)->format($dateKey);
            } catch (\Exception $e) {
                Log::warning("Error formatting date {$date}: " . $e->getMessage());
                $displayDate = $date;
            }

            return [
                'date' => $displayDate,
                'accepted' => $accepted,
                'rejected' => $rejected,
                'total' => $accepted + $rejected
            ];
        })->sortKeys()->values()->toArray();

        Log::info("Chart data generated: " . count($chartData) . " points");

        return $chartData;
    }

    /**
     * Affiche l'historique des actions de l'agent.
     */
    public function history(Request $request)
    {
        $searchQuery = $request->input('search', null);
        $actionFilter = $request->input('action', null);
        $dateFilter = $request->input('date', null);
        $typeFilter = $request->input('type', null);
        $perPage = $request->input('per_page', 10);

        $agent = Auth::user();
        $query = Decision::with(['ticket.passengerForm'])
                         ->where('user_id', $agent->id)
                         ->where('created_at', '>=', Carbon::now()->subMonths(3));

        // Filtre de recherche
        if ($searchQuery) {
            $query->where(function ($q) use ($searchQuery) {
                $q->whereHas('ticket', function ($q) use ($searchQuery) {
                    $q->where('ticket_no', 'like', "%{$searchQuery}%");
                })->orWhereHas('ticket.passengerForm', function ($q) use ($searchQuery) {
                    $q->where('nationality', 'like', "%{$searchQuery}%");
                });
            });
        }

        // Filtre d'action
        if ($actionFilter) {
            $query->where('decision', $actionFilter);
        }

        // Filtre de type
        if ($typeFilter) {
            $query->where('action_type', $typeFilter);
        }

        // Filtre de date
        if ($dateFilter) {
            $query->whereDate('created_at', $dateFilter);
        }

        $history = $query->latest()->paginate($perPage);

        $historyData = $history->map(function ($decision) {
            return [
                'ticketNo' => $decision->ticket->ticket_no ?? null,
                'nationality' => $decision->ticket->passengerForm->nationality ?? null,
                'type' => $decision->action_type,
                'action' => $decision->decision,
                'datetime' => $decision->created_at->toIso8601String(),
            ];
        });

        return response()->json([
            'success' => true,
            'message' => 'History fetched successfully',
            'data' => [
                'history' => $historyData,
                'pagination' => [
                    'current_page' => $history->currentPage(),
                    'last_page' => $history->lastPage(),
                    'per_page' => $history->perPage(),
                    'total' => $history->total(),
                ],
                'filters' => [
                    'search' => $searchQuery,
                    'action' => $actionFilter,
                    'date' => $dateFilter,
                    'type' => $typeFilter,
                ]
            ]
        ], 200);
    }

    /**
     * Traduit le type de décision en français pour l'affichage.
     *
     * @param  string  $decision
     * @return string
     */
    private function getFrenchAction($decision)
    {
        return match($decision) {
            'accepted' => 'Accepté',
            'rejected' => 'Rejeté',
            'departure_recorded' => 'Départ enregistré',
            default => ''
        };
    }
}
