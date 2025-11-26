<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use App\Models\User;
use App\Models\Decision;
use App\Models\Ticket;
use App\Models\Port;
use Carbon\Carbon;

class AdminTicketController extends Controller
{
    // Durée de séjour légale pour les étrangers (en jours)
    const STAY_DURATION_DAYS = 90;

    /**
     * Liste tous les tickets avec filtres avancés et statistiques.
     */
    public function tickets(Request $request)
    {
        try {
            $timeFilter = $request->input('filter', 'month');
            $searchQuery = $request->input('search', null);
            $statusFilter = $request->input('status', null);
            $portFilter = $request->input('port_id', null);
            $agentFilter = $request->input('agent_id', null);
            $passengerTypeFilter = $request->input('passenger_type', null);
            $nationalityFilter = $request->input('nationality', null);
            $startDate = $request->input('start_date', null);
            $endDate = $request->input('end_date', null);

            // Paramètres de pagination
            $page = $request->input('page', 1);
            $perPage = $request->input('per_page', 20);

            $dateStart = $startDate ? Carbon::parse($startDate)->startOfDay() : $this->getStartDate($timeFilter);
            $dateEnd = $endDate ? Carbon::parse($endDate)->endOfDay() : Carbon::now()->endOfDay();

            Log::info("Récupération tickets - Filtre: {$timeFilter}, Recherche: {$searchQuery}, Page: {$page}");

            // Construction de la requête de base
            $query = Ticket::with(['passengerForm.portOfEntry', 'decisions' => function ($q) {
                $q->orderBy('created_at', 'desc')->with(['user', 'port']);
            }]);

            // Filtre temporel
            $query->whereBetween('created_at', [$dateStart, $dateEnd]);

            // Filtre de recherche (ticket_no, nom passager, passeport)
            if ($searchQuery) {
                $query->where(function ($q) use ($searchQuery) {
                    $q->where('ticket_no', 'like', "%{$searchQuery}%")
                      ->orWhereHas('passengerForm', function ($subq) use ($searchQuery) {
                          $subq->whereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$searchQuery}%"])
                               ->orWhere('passport_number', 'like', "%{$searchQuery}%")
                               ->orWhere('nationality', 'like', "%{$searchQuery}%");
                      });
                });
            }

            // Filtre par port
            if ($portFilter) {
                $query->where(function ($q) use ($portFilter) {
                    // Vérifier dans les décisions
                    $q->whereHas('decisions', function ($subq) use ($portFilter) {
                        $subq->where('port_of_action', $portFilter);
                    })
                    // Ou vérifier dans le formulaire du passager
                    ->orWhereHas('passengerForm', function ($subq) use ($portFilter) {
                        $subq->where('port_of_entry', $portFilter);
                    });
                });
            }

            // Filtre par agent
            if ($agentFilter) {
                $query->whereHas('decisions', function ($q) use ($agentFilter) {
                    $q->where('user_id', $agentFilter);
                });
            }

            // Filtre par statut
            if ($statusFilter && $statusFilter !== 'all') {
                $query->where('status', $statusFilter);
            }

            // Filtre par type de passager
            if ($passengerTypeFilter && $passengerTypeFilter !== 'all') {
                $query->where('passenger_type', $passengerTypeFilter);
            }

            // Filtre par nationalité
            if ($nationalityFilter) {
                $query->whereHas('passengerForm', function ($q) use ($nationalityFilter) {
                    $q->where('nationality', $nationalityFilter);
                });
            }

            // Récupération avec pagination
            $tickets = $query->orderBy('created_at', 'desc')->paginate($perPage, ['*'], 'page', $page);

            // Transformation des données
            $ticketsData = $tickets->getCollection()->map(function ($ticket) {
                $latestDecision = $ticket->decisions->first();
                $passenger = $ticket->passengerForm;
                $fullName = trim(($passenger->first_name ?? '') . ' ' . ($passenger->last_name ?? ''));

                // Calcul du temps de traitement
                $processingTime = null;
                if ($latestDecision && $ticket->created_at) {
                    $processingTime = Carbon::parse($ticket->created_at)
                        ->diffInMinutes(Carbon::parse($latestDecision->created_at));
                }

                // Vérification dépassement de séjour pour étrangers
                $overstayInfo = null;
                if ($ticket->passenger_type === 'foreigner') {
                    $arrivalDecision = Decision::where('ticket_id', $ticket->id)
                        ->where('action_type', 'arrival')
                        ->where('decision', 'accepted')
                        ->first();

                    if ($arrivalDecision) {
                        $daysInCountry = Carbon::parse($arrivalDecision->created_at)->diffInDays(Carbon::now());
                        $isOverstay = $daysInCountry > self::STAY_DURATION_DAYS;

                        $overstayInfo = [
                            'days_in_country' => $daysInCountry,
                            'is_overstay' => $isOverstay,
                            'days_overstay' => $isOverstay ? ($daysInCountry - self::STAY_DURATION_DAYS) : 0,
                            'arrival_date' => $arrivalDecision->created_at->toIso8601String(),
                        ];
                    }
                }

                // Détermination du nom du port
                $portName = 'N/A';
                if ($latestDecision && $latestDecision->port) {
                    $portName = $latestDecision->port->name;
                } elseif ($passenger && $passenger->portOfEntry) {
                    $portName = $passenger->portOfEntry->name;
                }

                return [
                    'id' => $ticket->id,
                    'ticket_no' => $ticket->ticket_no ?? 'N/A',
                    'status' => $ticket->status,
                    'passenger_type' => $ticket->passenger_type,
                    'created_at' => $ticket->created_at->toIso8601String(),
                    'updated_at' => $ticket->updated_at->toIso8601String(),

                    // Informations passager
                    'passenger' => [
                        'full_name' => $fullName ?: 'Inconnu',
                        'first_name' => $passenger->first_name ?? 'N/A',
                        'last_name' => $passenger->last_name ?? 'N/A',
                        'gender' => $passenger->gender ?? 'N/A',
                        'passport_number' => $passenger->passport_number ?? 'N/A',
                        'nationality' => $passenger->nationality ?? 'N/A',
                        'date_of_birth' => $passenger->date_of_birth ?? null,
                        'residence_country' => $passenger->residence_country ?? 'N/A',
                        'residence_city' => $passenger->residence_city ?? 'N/A',
                        'haiti_address' => $passenger->haiti_street ?? 'N/A',
                        'phone' => $passenger->phone ?? 'N/A',
                        'haiti_city' => $passenger->haiti_city ?? 'N/A',
                    ],

                    // Décision la plus récente
                    'latest_decision' => $latestDecision ? [
                        'decision' => $latestDecision->decision,
                        'action_type' => $latestDecision->action_type,
                        'comment' => $latestDecision->comment,
                        'created_at' => $latestDecision->created_at->toIso8601String(),
                    ] : null,

                    // Informations contextuelles
                    'port_name' => $portName,
                    'agent_name' => $latestDecision && $latestDecision->user ? $latestDecision->user->name : 'Système',
                    'decisions_count' => $ticket->decisions->count(),
                    'processing_time_minutes' => $processingTime,

                    // Historique complet des décisions
                    'decisions_history' => $ticket->decisions->map(function($decision) {
                        return [
                            'id' => $decision->id,
                            'action_type' => $decision->action_type,
                            'decision' => $decision->decision,
                            'comment' => $decision->comment,
                            'created_at' => $decision->created_at->toIso8601String(),
                            'agent' => $decision->user ? [
                                'id' => $decision->user->id,
                                'name' => $decision->user->name,
                                'email' => $decision->user->email,
                            ] : null,
                            'port' => $decision->port ? [
                                'id' => $decision->port->id,
                                'name' => $decision->port->name,
                            ] : null,
                        ];
                    }),

                    // Informations de dépassement de séjour
                    'overstay_info' => $overstayInfo,
                ];
            });

            // Statistiques globales
            $allTickets = Ticket::whereBetween('created_at', [$dateStart, $dateEnd])->get();
            $allDecisions = Decision::whereBetween('created_at', [$dateStart, $dateEnd])->get();

            $stats = [
                'total_tickets' => $allTickets->count(),
                'tickets_with_decisions' => $allTickets->filter(fn($t) => $t->decisions->count() > 0)->count(),
                'processing_rate' => $allTickets->count() > 0
                    ? round(($allTickets->filter(fn($t) => $t->decisions->count() > 0)->count() / $allTickets->count()) * 100, 1)
                    : 0,

                // Par statut
                'by_status' => [
                    'draft' => $allTickets->where('status', 'draft')->count(),
                    'pending' => $allTickets->where('status', 'pending')->count(),
                    'accepted_arrival' => $allTickets->where('status', 'accepted_arrival')->count(),
                    'rejected_arrival' => $allTickets->where('status', 'rejected_arrival')->count(),
                    'accepted_departure' => $allTickets->where('status', 'accepted_departure')->count(),
                    'rejected_departure' => $allTickets->where('status', 'rejected_departure')->count(),
                ],

                // Par type de passager
                'by_passenger_type' => [
                    'national' => $allTickets->where('passenger_type', 'haitian')->count(),
                    'foreigner' => $allTickets->where('passenger_type', 'foreigner')->count(),
                ],

                // Décisions
                'decisions' => [
                    'total' => $allDecisions->count(),
                    'arrivals' => $allDecisions->where('action_type', 'arrival')->count(),
                    'departures' => $allDecisions->where('action_type', 'departure')->count(),
                    'accepted' => $allDecisions->where('decision', 'accepted')->count(),
                    'rejected' => $allDecisions->where('decision', 'rejected')->count(),
                    'acceptance_rate' => $allDecisions->count() > 0
                        ? round(($allDecisions->where('decision', 'accepted')->count() / $allDecisions->count()) * 100, 1)
                        : 0,
                ],

                // Temps moyen de traitement
                'avg_processing_time' => $allDecisions
                    ->filter(fn($d) => $d->ticket && $d->ticket->created_at)
                    ->map(fn($d) => Carbon::parse($d->ticket->created_at)->diffInMinutes(Carbon::parse($d->created_at)))
                    ->avg() ?? 0,
            ];

            // Top nationalités
            $topNationalities = $allTickets
                ->filter(fn($t) => $t->passengerForm && $t->passengerForm->nationality)
                ->map(fn($t) => $t->passengerForm->nationality)
                ->countBy()
                ->sortDesc()
                ->take(10)
                ->map(function($count, $nationality) {
                    return ['nationality' => $nationality, 'count' => $count];
                })
                ->values();

            // Étrangers en dépassement de séjour
            $overstayDeadline = Carbon::now()->subDays(self::STAY_DURATION_DAYS);
            $overstayCount = Ticket::query()
                ->where('passenger_type', 'foreigner')
                ->whereHas('decisions', function($q) use ($overstayDeadline) {
                    $q->where('action_type', 'arrival')
                      ->where('decision', 'accepted')
                      ->where('created_at', '<', $overstayDeadline);
                })
                ->whereDoesntHave('decisions', function($q) {
                    $q->where('action_type', 'departure')
                      ->where('decision', 'accepted');
                })
                ->count();

            // Options de filtres
            $ports = Port::where('status', 'active')->get(['id', 'name']);
            $agents = User::where('role', 'agent')->where('status', 'active')->get(['id', 'name']);

            $nationalities = $allTickets
                ->filter(fn($t) => $t->passengerForm && $t->passengerForm->nationality)
                ->map(fn($t) => $t->passengerForm->nationality)
                ->unique()
                ->sort()
                ->values();

            // Alertes et insights
            $alerts = $this->generateTicketAlerts($stats, $overstayCount);

            Log::info("Trouvé {$ticketsData->count()} tickets sur page {$page}");

            // Remplacer la collection de tickets par les données transformées
            $tickets->setCollection($ticketsData);

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
                'statistics' => $stats,
                'insights' => [
                    'top_nationalities' => $topNationalities,
                    'overstay_foreigners_count' => $overstayCount,
                    'alerts' => $alerts,
                ],
                'filter_options' => [
                    'ports' => $ports,
                    'agents' => $agents,
                    'nationalities' => $nationalities,
                ],
                'filters' => [
                    'time_filter' => $timeFilter,
                    'search' => $searchQuery,
                    'status' => $statusFilter,
                    'port_id' => $portFilter,
                    'agent_id' => $agentFilter,
                    'passenger_type' => $passengerTypeFilter,
                    'nationality' => $nationalityFilter,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                ],
                'period_start' => $dateStart->toIso8601String(),
                'period_end' => $dateEnd->toIso8601String(),
            ], 200);

        } catch (\Exception $e) {
            Log::error("Erreur récupération tickets: " . $e->getMessage());
            Log::error($e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des tickets.',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Détails complets d'un ticket spécifique.
     */
    public function getTicketDetails(Ticket $ticket)
    {
        try {
            $ticket->load(['passengerForm.portOfEntry', 'decisions.user', 'decisions.port']);

            $passenger = $ticket->passengerForm;
            $fullName = trim(($passenger->first_name ?? '') . ' ' . ($passenger->last_name ?? ''));

            // Analyse du parcours du ticket
            $arrivals = $ticket->decisions->where('action_type', 'arrival');
            $departures = $ticket->decisions->where('action_type', 'departure');

            $journey = [
                'total_arrivals' => $arrivals->count(),
                'total_departures' => $departures->count(),
                'accepted_arrivals' => $arrivals->where('decision', 'accepted')->count(),
                'rejected_arrivals' => $arrivals->where('decision', 'rejected')->count(),
                'accepted_departures' => $departures->where('decision', 'accepted')->count(),
                'rejected_departures' => $departures->where('decision', 'rejected')->count(),
                'is_currently_in_country' => $arrivals->where('decision', 'accepted')->count() > $departures->where('decision', 'accepted')->count(),
            ];

            // Informations de séjour pour étrangers
            $stayInfo = null;
            if ($ticket->passenger_type === 'foreigner') {
                $lastArrival = Decision::where('ticket_id', $ticket->id)
                    ->where('action_type', 'arrival')
                    ->where('decision', 'accepted')
                    ->latest()
                    ->first();

                if ($lastArrival && $journey['is_currently_in_country']) {
                    $daysInCountry = Carbon::parse($lastArrival->created_at)->diffInDays(Carbon::now());
                    $remainingDays = self::STAY_DURATION_DAYS - $daysInCountry;
                    $isOverstay = $daysInCountry > self::STAY_DURATION_DAYS;

                    $stayInfo = [
                        'arrival_date' => $lastArrival->created_at->toIso8601String(),
                        'days_in_country' => $daysInCountry,
                        'remaining_days' => $remainingDays,
                        'is_overstay' => $isOverstay,
                        'days_overstay' => $isOverstay ? abs($remainingDays) : 0,
                        'legal_limit_days' => self::STAY_DURATION_DAYS,
                        'expiry_date' => Carbon::parse($lastArrival->created_at)->addDays(self::STAY_DURATION_DAYS)->toIso8601String(),
                    ];
                }
            }

            // Ports visités
            $portsVisited = $ticket->decisions->pluck('port')->filter()->unique('id')->map(function($port) {
                return [
                    'id' => $port->id,
                    'name' => $port->name,
                    'type' => $port->type,
                ];
            })->values();

            // Ajouter le port d'entrée s'il n'est pas déjà dans la liste
            if ($passenger && $passenger->portOfEntry) {
                $entryPort = $passenger->portOfEntry;
                if (!$portsVisited->contains('id', $entryPort->id)) {
                    $portsVisited->prepend([
                        'id' => $entryPort->id,
                        'name' => $entryPort->name,
                        'type' => $entryPort->type,
                    ]);
                }
            }

            // Agents ayant traité le ticket
            $agentsInvolved = $ticket->decisions->pluck('user')->filter()->unique('id')->map(function($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                ];
            })->values();

            // Détermination du nom du port principal
            $portName = 'N/A';
            $latestDecision = $ticket->decisions->first();
            if ($latestDecision && $latestDecision->port) {
                $portName = $latestDecision->port->name;
            } elseif ($passenger && $passenger->portOfEntry) {
                $portName = $passenger->portOfEntry->name;
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'ticket' => [
                        'id' => $ticket->id,
                        'ticket_no' => $ticket->ticket_no,
                        'status' => $ticket->status,
                        'passenger_type' => $ticket->passenger_type,
                        'created_at' => $ticket->created_at->toIso8601String(),
                        'updated_at' => $ticket->updated_at->toIso8601String(),
                    ],
                    'passenger' => $passenger ? [
                        'full_name' => $fullName,
                        'first_name' => $passenger->first_name,
                        'last_name' => $passenger->last_name,
                        'passport_number' => $passenger->passport_number,
                        'nationality' => $passenger->nationality,
                        'date_of_birth' => $passenger->date_of_birth,
                        'gender' => $passenger->gender ?? 'N/A',
                        'residence_country' => $passenger->residence_country,
                        'residence_city' => $passenger->residence_city,
                        'haiti_address' => $passenger->haiti_address ?? 'N/A',
                        'haiti_city' => $passenger->haiti_city ?? 'N/A',
                        'phone' => $passenger->phone ?? 'N/A',
                        'email' => $passenger->email ?? 'N/A',
                        'port_of_entry' => $passenger->portOfEntry ? [
                            'id' => $passenger->portOfEntry->id,
                            'name' => $passenger->portOfEntry->name,
                            'type' => $passenger->portOfEntry->type,
                        ] : null,
                    ] : null,
                    'decisions' => $ticket->decisions->map(function($decision) {
                        return [
                            'id' => $decision->id,
                            'action_type' => $decision->action_type,
                            'decision' => $decision->decision,
                            'comment' => $decision->comment,
                            'created_at' => $decision->created_at->toIso8601String(),
                            'agent' => [
                                'id' => $decision->user->id,
                                'name' => $decision->user->name,
                                'email' => $decision->user->email,
                                'role' => $decision->user->role,
                            ],
                            'port' => [
                                'id' => $decision->port->id,
                                'name' => $decision->port->name,
                                'type' => $decision->port->type,
                            ],
                        ];
                    }),
                    'journey_analysis' => $journey,
                    'stay_info' => $stayInfo,
                    'ports_visited' => $portsVisited,
                    'agents_involved' => $agentsInvolved,
                    'port_name' => $portName,
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error("Erreur récupération détails ticket: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des détails.',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Met à jour un ticket.
     */
    public function updateTicket(Request $request, Ticket $ticket)
    {
        try {
            $validated = $request->validate([
                'status' => 'sometimes|in:draft,pending,accepted_arrival,rejected_arrival,accepted_departure,rejected_departure',
                'passenger_form' => 'sometimes|array',
                'passenger_form.first_name' => 'sometimes|string|max:255',
                'passenger_form.last_name' => 'sometimes|string|max:255',
                'passenger_form.passport_number' => 'sometimes|string|max:50',
                'passenger_form.nationality' => 'sometimes|string|max:100',
                'passenger_form.date_of_birth' => 'sometimes|date',
                'passenger_form.residence_country' => 'sometimes|string|max:100',
                'passenger_form.residence_city' => 'sometimes|string|max:100',
                'passenger_form.haiti_address' => 'sometimes|string|max:255',
                'passenger_form.haiti_city' => 'sometimes|string|max:100',
            ]);

            if (isset($validated['status'])) {
                $ticket->status = $validated['status'];
                $ticket->save();
            }

            if (isset($validated['passenger_form']) && $ticket->passengerForm) {
                $ticket->passengerForm->update($validated['passenger_form']);
            }

            Log::info("Ticket {$ticket->id} mis à jour par admin " . Auth::id());

            return response()->json([
                'success' => true,
                'message' => 'Ticket mis à jour avec succès',
                'data' => $ticket->fresh(['passengerForm.portOfEntry', 'decisions'])
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error("Erreur mise à jour ticket: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour.',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Supprime un ticket.
     */
    public function deleteTicket(Ticket $ticket)
    {
        try {
            $ticketNo = $ticket->ticket_no;
            $ticketId = $ticket->id;

            // Supprimer les décisions associées
            $ticket->decisions()->delete();

            // Supprimer le formulaire passager
            if ($ticket->passengerForm) {
                $ticket->passengerForm->delete();
            }

            // Supprimer le ticket
            $ticket->delete();

            Log::info("Ticket {$ticketId} ({$ticketNo}) supprimé par admin " . Auth::id());

            return response()->json([
                'success' => true,
                'message' => "Le ticket {$ticketNo} a été supprimé avec succès."
            ], 200);

        } catch (\Exception $e) {
            Log::error("Erreur suppression ticket: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression.',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    // ===== MÉTHODES PRIVÉES =====

    /**
     * Détermine la date de début selon le filtre.
     */
    private function getStartDate($timeFilter)
    {
        return match($timeFilter) {
            'day' => Carbon::now()->startOfDay(),
            '3-days' => Carbon::now()->subDays(3)->startOfDay(),
            'week' => Carbon::now()->startOfWeek(),
            'month' => Carbon::now()->startOfMonth(),
            '3-months' => Carbon::now()->subMonths(3)->startOfDay(),
            'year' => Carbon::now()->startOfYear(),
            'all' => Carbon::now()->subYears(10),
            default => Carbon::now()->startOfMonth(),
        };
    }

    /**
     * Génère des alertes et insights sur les tickets.
     */
    private function generateTicketAlerts($stats, $overstayCount)
    {
        $alerts = [];

        // Tickets non traités
        $unprocessedCount = $stats['total_tickets'] - $stats['tickets_with_decisions'];
        if ($unprocessedCount > 50) {
            $alerts[] = [
                'type' => 'warning',
                'category' => 'processing',
                'message' => "{$unprocessedCount} tickets créés mais jamais traités. Action requise.",
            ];
        }

        // Taux de traitement faible
        if ($stats['processing_rate'] < 70 && $stats['total_tickets'] > 20) {
            $alerts[] = [
                'type' => 'danger',
                'category' => 'performance',
                'message' => "Taux de traitement faible ({$stats['processing_rate']}%). Amélioration nécessaire.",
            ];
        }

        // Taux de rejet élevé
        if ($stats['decisions']['acceptance_rate'] < 70 && $stats['decisions']['total'] > 20) {
            $alerts[] = [
                'type' => 'warning',
                'category' => 'decisions',
                'message' => "Taux de rejet élevé (" . (100 - $stats['decisions']['acceptance_rate']) . "%). Vérifier les processus.",
            ];
        }

        // Dépassements de séjour
        if ($overstayCount > 0) {
            $alerts[] = [
                'type' => 'danger',
                'category' => 'overstay',
                'message' => "{$overstayCount} étranger(s) en dépassement de séjour légal (>90 jours).",
            ];
        }

        // Performance positive
        if ($stats['processing_rate'] >= 90) {
            $alerts[] = [
                'type' => 'success',
                'category' => 'performance',
                'message' => "Excellent taux de traitement ({$stats['processing_rate']}%).",
            ];
        }

        // Temps de traitement
        if ($stats['avg_processing_time'] > 60) {
            $alerts[] = [
                'type' => 'warning',
                'category' => 'efficiency',
                'message' => "Temps moyen de traitement élevé (" . round($stats['avg_processing_time']) . " min). Optimisation recommandée.",
            ];
        }

        return $alerts;
    }
}
