<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\User;
use App\Models\Decision;
use App\Models\Ticket;
use App\Models\Port;
use Carbon\Carbon;
use Illuminate\Validation\Rule;

class SupervisorController extends Controller
{
    /**
     * Tableau de bord du superviseur avec statistiques détaillées.
     */
    public function dashboard(Request $request)
    {
        $user = Auth::user();
        $portId = $user->port_id;
        $timeFilter = $request->input('filter', 'day');

        // Définir la période selon le filtre
        $startDate = $this->getStartDate($timeFilter);

        // === STATISTIQUES PRINCIPALES ===
        $totalAgents = User::where('port_id', $portId)
                           ->where('role', 'agent')
                           ->where('status', 'active')
                           ->count();

        $decisions = Decision::whereHas('user', fn($query) => $query->where('port_id', $portId))
                             ->where('created_at', '>=', $startDate)
                             ->get();

        $scannedTickets = $decisions->count();
        $acceptedTickets = $decisions->where('decision', 'accepted')->count();
        $rejectedTickets = $decisions->where('decision', 'rejected')->count();
        $acceptanceRate = $scannedTickets > 0 ? round(($acceptedTickets / $scannedTickets) * 100, 1) : 0;

        // === STATISTIQUES PAR TYPE D'ACTION ===
        $arrivalDecisions = $decisions->where('action_type', 'arrival');
        $departureDecisions = $decisions->where('action_type', 'departure');

        $arrivalStats = [
            'total' => $arrivalDecisions->count(),
            'accepted' => $arrivalDecisions->where('decision', 'accepted')->count(),
            'rejected' => $arrivalDecisions->where('decision', 'rejected')->count(),
            'acceptance_rate' => $arrivalDecisions->count() > 0
                ? round(($arrivalDecisions->where('decision', 'accepted')->count() / $arrivalDecisions->count()) * 100, 1)
                : 0
        ];

        $departureStats = [
            'total' => $departureDecisions->count(),
            'accepted' => $departureDecisions->where('decision', 'accepted')->count(),
            'rejected' => $departureDecisions->where('decision', 'rejected')->count(),
            'acceptance_rate' => $departureDecisions->count() > 0
                ? round(($departureDecisions->where('decision', 'accepted')->count() / $departureDecisions->count()) * 100, 1)
                : 0
        ];

        // === PERFORMANCE DES AGENTS ===
        $agentStats = User::where('port_id', $portId)
            ->where('role', 'agent')
            ->withCount([
                'decisions as total_scans' => function($query) use ($startDate) {
                    $query->where('created_at', '>=', $startDate);
                },
                'decisions as accepted' => function($query) use ($startDate) {
                    $query->where('created_at', '>=', $startDate)
                          ->where('decision', 'accepted');
                },
                'decisions as rejected' => function($query) use ($startDate) {
                    $query->where('created_at', '>=', $startDate)
                          ->where('decision', 'rejected');
                }
            ])
            ->get()
            ->map(function($agent) {
                $acceptanceRate = $agent->total_scans > 0
                    ? round(($agent->accepted / $agent->total_scans) * 100, 1)
                    : 0;

                return [
                    'id' => $agent->id,
                    'name' => $agent->name,
                    'email' => $agent->email,
                    'status' => $agent->status,
                    'total_scans' => $agent->total_scans,
                    'accepted' => $agent->accepted,
                    'rejected' => $agent->rejected,
                    'acceptance_rate' => $acceptanceRate,
                ];
            })
            ->sortByDesc('total_scans')
            ->values();

        // === TOP 5 NATIONALITÉS ===
        $topNationalities = $decisions->map(function($decision) {
            return $decision->ticket?->passengerForm?->nationality ?? 'Inconnu';
        })->countBy()->sortDesc()->take(5)->map(function($count, $nationality) {
            return ['nationality' => $nationality, 'count' => $count];
        })->values();

        // === ACTIVITÉ PAR HEURE (pour aujourd'hui) ===
        $hourlyActivity = [];
        if ($timeFilter === 'day') {
            $hourlyActivity = $decisions->groupBy(function($decision) {
                return $decision->created_at->format('H:00');
            })->map(function($group) {
                return [
                    'total' => $group->count(),
                    'accepted' => $group->where('decision', 'accepted')->count(),
                    'rejected' => $group->where('decision', 'rejected')->count(),
                ];
            })->toArray();
        }

        // === TEMPS DE TRAITEMENT MOYEN ===
        $avgProcessingTime = $decisions->filter(function($decision) {
            return $decision->ticket && $decision->ticket->created_at;
        })->map(function($decision) {
            $ticketCreated = Carbon::parse($decision->ticket->created_at);
            $decisionMade = Carbon::parse($decision->created_at);
            return $ticketCreated->diffInMinutes($decisionMade);
        })->avg();

        // === DERNIÈRES ACTIONS ===
        $lastActions = Decision::whereHas('user', fn($query) => $query->where('port_id', $portId))
            ->with(['ticket.passengerForm', 'user'])
            ->where('created_at', '>=', $startDate)
            ->orderByDesc('created_at')
            ->take(10)
            ->get()
            ->map(function($decision) {
                return [
                    'id' => $decision->id,
                    'ticket_no' => $decision->ticket?->ticket_no,
                    'traveler_name' => $decision->ticket?->passengerForm
                        ? "{$decision->ticket->passengerForm->first_name} {$decision->ticket->passengerForm->last_name}"
                        : 'Inconnu',
                    'nationality' => $decision->ticket?->passengerForm?->nationality,
                    'action_type' => $decision->action_type,
                    'decision' => $decision->decision,
                    'agent_name' => $decision->user?->name,
                    'datetime' => $decision->created_at->toIso8601String(),
                    'comment' => $decision->comment,
                ];
            });

        // === GRAPHIQUE D'ÉVOLUTION ===
        $chartData = $this->getChartData($decisions, $timeFilter);

        // === ALERTES ET INSIGHTS ===
        $alerts = $this->generateAlerts($agentStats, $decisions, $timeFilter);

        // === COMPARAISON AVEC LA PÉRIODE PRÉCÉDENTE ===
        $comparison = $this->getComparison($portId, $timeFilter, $startDate);

        return response()->json([
            'success' => true,
            'message' => 'Dashboard data fetched successfully',
            'data' => [
                // Statistiques principales
                'totalAgents' => $totalAgents,
                'scannedTickets' => $scannedTickets,
                'acceptedTickets' => $acceptedTickets,
                'rejectedTickets' => $rejectedTickets,
                'acceptanceRate' => $acceptanceRate,

                // Statistiques par type
                'arrivalStats' => $arrivalStats,
                'departureStats' => $departureStats,

                // Performance des agents
                'agentStats' => $agentStats,

                // Insights supplémentaires
                'topNationalities' => $topNationalities,
                'avgProcessingTime' => round($avgProcessingTime ?? 0, 1),
                'hourlyActivity' => $hourlyActivity,
                'chartData' => $chartData,
                'lastActions' => $lastActions,
                'alerts' => $alerts,
                'comparison' => $comparison,

                // Métadonnées
                'timeFilter' => $timeFilter,
                'periodStart' => $startDate->toIso8601String(),
                'periodEnd' => Carbon::now()->toIso8601String(),
                'port' => [
                    'id' => $user->port_id,
                    'name' => Port::find($user->port_id)?->name ?? 'Port inconnu',
                ]
            ]
        ], 200);
    }

    /**
     * Liste tous les agents du port du superviseur avec leurs statistiques.
     * Cette méthode est appelée depuis la page de gestion des agents.
    */
    public function agents(Request $request)
    {
        $user = Auth::user();
        $portId = $user->port_id;

        $timeFilter = $request->input('filter', 'month');
        $startDate = $this->getStartDate($timeFilter);

        Log::info("Récupération des agents pour le port {$portId} avec filtre {$timeFilter}");

        $agents = User::where('port_id', $portId)
            ->where('role', 'agent')
            ->with('port')
            ->withCount([
                'decisions as total_scans' => function($query) use ($startDate) {
                    $query->where('created_at', '>=', $startDate);
                },
                'decisions as accepted' => function($query) use ($startDate) {
                    $query->where('created_at', '>=', $startDate)
                        ->where('decision', 'accepted');
                },
                'decisions as rejected' => function($query) use ($startDate) {
                    $query->where('created_at', '>=', $startDate)
                        ->where('decision', 'rejected');
                },
                'decisions as arrivals' => function($query) use ($startDate) {
                    $query->where('created_at', '>=', $startDate)
                        ->where('action_type', 'arrival');
                },
                'decisions as departures' => function($query) use ($startDate) {
                    $query->where('created_at', '>=', $startDate)
                        ->where('action_type', 'departure');
                }
            ])
            ->get()
            ->map(function($agent) use ($startDate) {
                $acceptanceRate = $agent->total_scans > 0
                    ? round(($agent->accepted / $agent->total_scans) * 100, 1)
                    : 0;

                // Dernière activité
                $lastActivity = Decision::where('user_id', $agent->id)
                    ->latest()
                    ->first();

                // Temps moyen de traitement
                $avgProcessingTime = Decision::where('user_id', $agent->id)
                    ->where('created_at', '>=', $startDate)
                    ->whereHas('ticket')
                    ->with('ticket')
                    ->get()
                    ->filter(fn($d) => $d->ticket && $d->ticket->created_at)
                    ->map(function($decision) {
                        return Carbon::parse($decision->ticket->created_at)
                            ->diffInMinutes(Carbon::parse($decision->created_at));
                    })
                    ->avg();

                return [
                    'id' => $agent->id,
                    'name' => $agent->name,
                    'email' => $agent->email,
                    'status' => $agent->status,
                    'role' => $agent->role,
                    'created_at' => $agent->created_at->toIso8601String(),
                    'port' => [
                        'id' => $agent->port?->id,
                        'name' => $agent->port?->name,
                    ],
                    'statistics' => [
                        'total_scans' => $agent->total_scans,
                        'accepted' => $agent->accepted,
                        'rejected' => $agent->rejected,
                        'arrivals' => $agent->arrivals,
                        'departures' => $agent->departures,
                        'acceptance_rate' => $acceptanceRate,
                        'avg_processing_time' => round($avgProcessingTime ?? 0, 1),
                    ],
                    'last_activity' => $lastActivity ? [
                        'date' => $lastActivity->created_at->toIso8601String(),
                        'action' => $lastActivity->action_type,
                        'decision' => $lastActivity->decision,
                    ] : null,
                ];
            });

        Log::info("Trouvé {$agents->count()} agents pour le port {$portId}");

        return response()->json([
            'success' => true,
            'message' => 'Agents récupérés avec succès',
            'data' => $agents,
            'filter' => $timeFilter,
            'period_start' => $startDate->toIso8601String(),
            'period_end' => Carbon::now()->toIso8601String(),
        ], 200);
    }

    /**
     * Historique des décisions du port.
     */
    public function history(Request $request)
    {
        $user = Auth::user();
        $portId = $user->port_id;

        $searchQuery = $request->input('search', null);
        $actionFilter = $request->input('action', null);
        $dateFilter = $request->input('date', null);
        $typeFilter = $request->input('type', null);
        $agentFilter = $request->input('agent', null);
        $perPage = $request->input('per_page', 15);

        $query = Decision::with(['ticket.passengerForm', 'user'])
            ->whereHas('user', fn($q) => $q->where('port_id', $portId))
            ->where('created_at', '>=', Carbon::now()->subMonths(3));

        if ($searchQuery) {
            $query->where(function($q) use ($searchQuery) {
                $q->whereHas('ticket', function($q) use ($searchQuery) {
                    $q->where('ticket_no', 'like', "%{$searchQuery}%");
                })->orWhereHas('ticket.passengerForm', function($q) use ($searchQuery) {
                    $q->where('nationality', 'like', "%{$searchQuery}%")
                      ->orWhere('first_name', 'like', "%{$searchQuery}%")
                      ->orWhere('last_name', 'like', "%{$searchQuery}%")
                      ->orWhere('passport_number', 'like', "%{$searchQuery}%");
                });
            });
        }

        if ($actionFilter) {
            $query->where('decision', $actionFilter);
        }

        if ($typeFilter) {
            $query->where('action_type', $typeFilter);
        }

        if ($agentFilter) {
            $query->where('user_id', $agentFilter);
        }

        if ($dateFilter) {
            $query->whereDate('created_at', $dateFilter);
        }

        $history = $query->latest()->paginate($perPage);

        $historyData = $history->map(function($decision) {
            return [
                'id' => $decision->id,
                'ticket_no' => $decision->ticket?->ticket_no,
                'traveler_name' => $decision->ticket?->passengerForm
                    ? "{$decision->ticket->passengerForm->first_name} {$decision->ticket->passengerForm->last_name}"
                    : 'Inconnu',
                'nationality' => $decision->ticket?->passengerForm?->nationality,
                'passport_number' => $decision->ticket?->passengerForm?->passport_number,
                'action_type' => $decision->action_type,
                'decision' => $decision->decision,
                'agent_name' => $decision->user?->name,
                'agent_id' => $decision->user?->id,
                'datetime' => $decision->created_at->toIso8601String(),
                'comment' => $decision->comment,
            ];
        });

        // Liste des agents pour le filtre
        $agents = User::where('port_id', $user->port_id)
            ->where('role', 'agent')
            ->select('id', 'name')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'History fetched successfully',
            'data' => $historyData,
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
                'agent' => $agentFilter,
            ],
            'agents' => $agents,
        ], 200);
    }

    /**
     * Liste de tous les tickets traités dans le port.
     */
    public function tickets(Request $request)
    {
        $user = Auth::user();
        $portId = $user->port_id;

        $perPage = $request->input('per_page', 15);
        $sortBy = $request->input('sort_by', 'decisions.created_at');
        $sortDirection = $request->input('sort_direction', 'desc');
        $searchQuery = $request->input('search', null);
        $statusFilter = $request->input('status', null);
        $decisionFilter = $request->input('decision', null);

        $query = DB::table('decisions')
            ->join('users', 'decisions.user_id', '=', 'users.id')
            ->join('tickets', 'decisions.ticket_id', '=', 'tickets.id')
            ->join('passenger_forms', 'tickets.id', '=', 'passenger_forms.ticket_id')
            ->select(
                'decisions.id',
                'tickets.ticket_no',
                DB::raw("CONCAT(passenger_forms.first_name, ' ', passenger_forms.last_name) as traveler_name"),
                'passenger_forms.passport_number as passport_no',
                'passenger_forms.nationality',
                'decisions.action_type as travel_type',
                'tickets.status',
                'decisions.decision',
                'users.name as agent_name',
                'decisions.created_at as action_date',
                'decisions.comment'
            )
            ->where('decisions.port_of_action', $portId);

        if ($searchQuery) {
            $query->where(function($q) use ($searchQuery) {
                $q->where('tickets.ticket_no', 'like', "%{$searchQuery}%")
                  ->orWhere('passenger_forms.first_name', 'like', "%{$searchQuery}%")
                  ->orWhere('passenger_forms.last_name', 'like', "%{$searchQuery}%")
                  ->orWhere('passenger_forms.passport_number', 'like', "%{$searchQuery}%")
                  ->orWhere('passenger_forms.nationality', 'like', "%{$searchQuery}%");
            });
        }

        if ($statusFilter) {
            $query->where('tickets.status', $statusFilter);
        }

        if ($decisionFilter) {
            $query->where('decisions.decision', $decisionFilter);
        }

        $allowedSorts = [
            'ticket_no', 'traveler_name', 'passport_no',
            'action_date', 'agent_name', 'decision',
            'travel_type', 'status', 'nationality'
        ];

        if (in_array($sortBy, $allowedSorts)) {
            $query->orderBy($sortBy, $sortDirection);
        } else {
            $query->orderByDesc('decisions.created_at');
        }

        $tickets = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Tickets fetched successfully',
            'data' => $tickets->items(),
            'pagination' => [
                'current_page' => $tickets->currentPage(),
                'last_page' => $tickets->lastPage(),
                'per_page' => $tickets->perPage(),
                'total' => $tickets->total(),
            ],
            'filters' => [
                'search' => $searchQuery,
                'status' => $statusFilter,
                'decision' => $decisionFilter,
            ]
        ], 200);
    }

    /**
     * Détails d'un ticket spécifique.
     */
    public function getTicketDetails(Request $request, $ticketId)
    {
        $user = Auth::user();
        $portId = $user->port_id;

        $ticket = Ticket::with(['passengerForm', 'decisions.user'])
            ->whereHas('decisions', fn($q) => $q->where('port_of_action', $portId))
            ->findOrFail($ticketId);

        return response()->json([
            'success' => true,
            'data' => [
                'ticket' => $ticket,
                'decisions' => $ticket->decisions->map(function($decision) {
                    return [
                        'id' => $decision->id,
                        'action_type' => $decision->action_type,
                        'decision' => $decision->decision,
                        'agent_name' => $decision->user?->name,
                        'comment' => $decision->comment,
                        'created_at' => $decision->created_at->toIso8601String(),
                    ];
                })
            ]
        ], 200);
    }

    // === MÉTHODES PRIVÉES ===

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
            default => Carbon::now()->startOfDay(),
        };
    }

    /**
     * Génère les données pour le graphique.
     */
    private function getChartData($decisions, $timeFilter)
    {
        $format = $timeFilter === 'day' || $timeFilter === '3-days' ? 'Y-m-d H:00' : 'Y-m-d';
        $dateKey = $timeFilter === 'day' || $timeFilter === '3-days' ? 'H:00' : 'd/m';

        $grouped = $decisions->groupBy(function($decision) use ($format) {
            return $decision->created_at->format($format);
        });

        return $grouped->map(function($group, $date) use ($dateKey, $format) {
            $displayDate = Carbon::createFromFormat($format, $date)->format($dateKey);
            return [
                'date' => $displayDate,
                'accepted' => $group->where('decision', 'accepted')->count(),
                'rejected' => $group->where('decision', 'rejected')->count(),
                'total' => $group->count()
            ];
        })->values();
    }

    /**
     * Génère des alertes et insights.
     */
    private function generateAlerts($agentStats, $decisions, $timeFilter)
    {
        $alerts = [];

        // Agent inactif
        $inactiveAgents = $agentStats->filter(fn($agent) => $agent['total_scans'] === 0);
        if ($inactiveAgents->count() > 0) {
            $alerts[] = [
                'type' => 'warning',
                'message' => "{$inactiveAgents->count()} agent(s) n'ont effectué aucun scan durant cette période.",
                'agents' => $inactiveAgents->pluck('name')
            ];
        }

        // Taux de rejet élevé
        $highRejectionAgents = $agentStats->filter(function($agent) {
            return $agent['total_scans'] > 10 && (100 - $agent['acceptance_rate']) > 30;
        });
        if ($highRejectionAgents->count() > 0) {
            $alerts[] = [
                'type' => 'danger',
                'message' => "{$highRejectionAgents->count()} agent(s) ont un taux de rejet supérieur à 30%.",
                'agents' => $highRejectionAgents->pluck('name')
            ];
        }

        // Performance exceptionnelle
        $topPerformers = $agentStats->filter(fn($agent) => $agent['total_scans'] > 50);
        if ($topPerformers->count() > 0) {
            $alerts[] = [
                'type' => 'success',
                'message' => "{$topPerformers->count()} agent(s) ont traité plus de 50 tickets.",
                'agents' => $topPerformers->pluck('name')
            ];
        }

        return $alerts;
    }

    /**
     * Compare avec la période précédente.
     */
    private function getComparison($portId, $timeFilter, $startDate)
    {
        $previousStart = match($timeFilter) {
            'day' => Carbon::now()->subDay()->startOfDay(),
            '3-days' => Carbon::now()->subDays(6)->startOfDay(),
            'week' => Carbon::now()->subWeek()->startOfWeek(),
            'month' => Carbon::now()->subMonth()->startOfMonth(),
            '3-months' => Carbon::now()->subMonths(6)->startOfDay(),
            'year' => Carbon::now()->subYear()->startOfYear(),
            default => Carbon::now()->subDay()->startOfDay(),
        };

        $previousEnd = $startDate;

        $previousDecisions = Decision::whereHas('user', fn($q) => $q->where('port_id', $portId))
            ->whereBetween('created_at', [$previousStart, $previousEnd])
            ->get();

        $currentTotal = Decision::whereHas('user', fn($q) => $q->where('port_id', $portId))
            ->where('created_at', '>=', $startDate)
            ->count();

        $previousTotal = $previousDecisions->count();
        $change = $previousTotal > 0
            ? round((($currentTotal - $previousTotal) / $previousTotal) * 100, 1)
            : 0;

        return [
            'previous_period_total' => $previousTotal,
            'current_period_total' => $currentTotal,
            'change_percentage' => $change,
            'trend' => $change > 0 ? 'up' : ($change < 0 ? 'down' : 'stable')
        ];
    }
}
