<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use App\Models\User;
use App\Models\Decision;
use App\Models\Ticket;
use App\Models\Port;
use Carbon\Carbon;

class AdminController extends Controller
{
    // Durée de séjour légale pour les étrangers (en jours)
    const STAY_DURATION_DAYS = 90;

    /**
     * Tableau de bord global de l'administrateur avec KPI détaillés.
     */
    public function dashboard(Request $request)
    {
        try {
            $timeFilter = $request->input('filter', 'month');
            $startDate = $this->getStartDate($timeFilter);

            Log::info("Dashboard Admin - Filter: {$timeFilter}, StartDate: {$startDate}");

            // ===== STATUT DES UTILISATEURS =====
            $userStatusCounts = [
                'supervisors' => [
                    'active' => User::where('role', 'supervisor')->where('status', 'active')->count(),
                    'inactive' => User::where('role', 'supervisor')->where('status', 'inactive')->count(),
                    'suspended' => User::where('role', 'supervisor')->where('status', 'suspended')->count(),
                    'total' => User::where('role', 'supervisor')->count(),
                ],
                'agents' => [
                    'active' => User::where('role', 'agent')->where('status', 'active')->count(),
                    'inactive' => User::where('role', 'agent')->where('status', 'inactive')->count(),
                    'suspended' => User::where('role', 'agent')->where('status', 'suspended')->count(),
                    'total' => User::where('role', 'agent')->count(),
                ],
            ];

            // ===== STATISTIQUES GLOBALES =====
            $totalTicketsCreated = Ticket::where('created_at', '>=', $startDate)->count();
            $decisions = Decision::where('created_at', '>=', $startDate)->get();
            $totalTicketsProcessed = $decisions->count();

            // Calcul des statistiques par type d'action
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
                'recorded' => $departureDecisions->where('decision', 'accepted')->count(),
                'rejected' => $departureDecisions->where('decision', 'rejected')->count(),
                'recording_rate' => $departureDecisions->count() > 0
                    ? round(($departureDecisions->where('decision', 'accepted')->count() / $departureDecisions->count()) * 100, 1)
                    : 0
            ];

            // ===== TOP 5 PORTS PAR VOLUME =====
            $topPortsByVolume = Port::select('ports.id', 'ports.name')
                ->join('decisions', 'ports.id', '=', 'decisions.port_of_action')
                ->where('decisions.created_at', '>=', $startDate)
                ->groupBy('ports.id', 'ports.name')
                ->selectRaw('COUNT(decisions.id) as scan_volume')
                ->orderByDesc('scan_volume')
                ->limit(5)
                ->get();

            // ===== TOP 5 PORTS PAR NOMBRE D'AGENTS =====
            $portsByAgents = Port::select('ports.id', 'ports.name')
                ->leftJoin('users', function ($join) {
                    $join->on('ports.id', '=', 'users.port_id')
                        ->where('users.role', 'agent')
                        ->where('users.status', 'active');
                })
                ->groupBy('ports.id', 'ports.name')
                ->selectRaw('COUNT(users.id) as agents_count')
                ->orderByDesc('agents_count')
                ->limit(5)
                ->get();

            // ===== TOP 10 NATIONALITÉS =====
            $topNationalities = $decisions
                ->filter(function ($decision) {
                    return $decision->ticket && $decision->ticket->passengerForm;
                })
                ->map(function ($decision) {
                    return $decision->ticket->passengerForm->nationality ?? 'Inconnu';
                })
                ->countBy()
                ->sortDesc()
                ->take(10)
                ->map(function ($count, $nationality) {
                    return [
                        'nationality' => $nationality,
                        'count' => $count
                    ];
                })
                ->values();

            // ===== GRAPHIQUE D'ÉVOLUTION (7 DERNIERS JOURS) =====
            $chartData = $this->getDailyDecisionsVolume();

            // ===== TEMPS DE TRAITEMENT MOYEN =====
            $avgProcessingTime = $decisions
                ->filter(function ($decision) {
                    return $decision->ticket && $decision->ticket->created_at;
                })
                ->map(function ($decision) {
                    $ticketCreated = Carbon::parse($decision->ticket->created_at);
                    $decisionMade = Carbon::parse($decision->created_at);
                    return $ticketCreated->diffInMinutes($decisionMade);
                })
                ->avg();

            // ===== PERFORMANCE PAR PORT =====
            $portPerformance = Port::withCount([
                'decisions as total_decisions' => function($query) use ($startDate) {
                    $query->where('created_at', '>=', $startDate);
                },
                'decisions as accepted_decisions' => function($query) use ($startDate) {
                    $query->where('created_at', '>=', $startDate)
                          ->where('decision', 'accepted');
                },
                'decisions as rejected_decisions' => function($query) use ($startDate) {
                    $query->where('created_at', '>=', $startDate)
                          ->where('decision', 'rejected');
                }
            ])
            ->with(['users' => function($query) {
                $query->where('role', 'agent')->where('status', 'active');
            }])
            ->get()
            ->map(function($port) {
                $acceptanceRate = $port->total_decisions > 0
                    ? round(($port->accepted_decisions / $port->total_decisions) * 100, 1)
                    : 0;

                return [
                    'id' => $port->id,
                    'name' => $port->name,
                    'status' => $port->status,
                    'total_decisions' => $port->total_decisions,
                    'accepted' => $port->accepted_decisions,
                    'rejected' => $port->rejected_decisions,
                    'acceptance_rate' => $acceptanceRate,
                    'active_agents' => $port->users->count(),
                ];
            })
            ->sortByDesc('total_decisions')
            ->values();

            // ===== ALERTES ET INSIGHTS =====
            $alerts = $this->generateSystemAlerts($userStatusCounts, $portPerformance, $decisions);

            // ===== COMPARAISON AVEC LA PÉRIODE PRÉCÉDENTE =====
            $comparison = $this->getGlobalComparison($timeFilter, $startDate);

            Log::info("Dashboard Admin - Data prepared successfully");

            return response()->json([
                'success' => true,
                'message' => 'Dashboard data fetched successfully',
                'data' => [
                    // Statut des utilisateurs
                    'userStatusCounts' => $userStatusCounts,

                    // Statistiques globales
                    'globalStats' => [
                        'total_tickets_created' => $totalTicketsCreated,
                        'total_tickets_processed' => $totalTicketsProcessed,
                        'processing_rate' => $totalTicketsCreated > 0
                            ? round(($totalTicketsProcessed / $totalTicketsCreated) * 100, 1)
                            : 0,
                    ],

                    // Statistiques par type
                    'arrivalStats' => $arrivalStats,
                    'departureStats' => $departureStats,

                    // Top ports
                    'topPortsByVolume' => $topPortsByVolume,
                    'portsByAgents' => $portsByAgents,
                    'portPerformance' => $portPerformance,

                    // Insights supplémentaires
                    'topNationalities' => $topNationalities,
                    'avgProcessingTime' => round($avgProcessingTime ?? 0, 1),
                    'chartData' => $chartData,
                    'alerts' => $alerts,
                    'comparison' => $comparison,

                    // Métadonnées
                    'timeFilter' => $timeFilter,
                    'periodStart' => $startDate->toIso8601String(),
                    'periodEnd' => Carbon::now()->toIso8601String(),
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error("Erreur Dashboard Admin: " . $e->getMessage());
            Log::error($e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du chargement du dashboard.',
                'error' => config('app.debug') ? $e->getMessage() : 'Une erreur est survenue.'
            ], 500);
        }
    }

    /**
     * Statistiques générales du système.
     */
    public function statistics(Request $request)
    {
        try {
            $timeFilter = $request->input('filter', 'month');
            $startDate = $this->getStartDate($timeFilter);

            $stats = [
                'users' => [
                    'total_agents' => User::where('role', 'agent')->count(),
                    'active_agents' => User::where('role', 'agent')->where('status', 'active')->count(),
                    'total_supervisors' => User::where('role', 'supervisor')->count(),
                    'active_supervisors' => User::where('role', 'supervisor')->where('status', 'active')->count(),
                ],
                'ports' => [
                    'total' => Port::count(),
                    'active' => Port::where('status', 'active')->count(),
                ],
                'tickets' => [
                    'total_created' => Ticket::where('created_at', '>=', $startDate)->count(),
                    'total_processed' => Decision::where('created_at', '>=', $startDate)->count(),
                ],
                'decisions' => [
                    'total' => Decision::where('created_at', '>=', $startDate)->count(),
                    'arrivals' => Decision::where('created_at', '>=', $startDate)->where('action_type', 'arrival')->count(),
                    'departures' => Decision::where('created_at', '>=', $startDate)->where('action_type', 'departure')->count(),
                    'accepted' => Decision::where('created_at', '>=', $startDate)->where('decision', 'accepted')->count(),
                    'rejected' => Decision::where('created_at', '>=', $startDate)->where('decision', 'rejected')->count(),
                ],
            ];

            return response()->json([
                'success' => true,
                'data' => $stats,
                'filter' => $timeFilter,
                'period_start' => $startDate->toIso8601String(),
                'period_end' => Carbon::now()->toIso8601String(),
            ], 200);

        } catch (\Exception $e) {
            Log::error("Erreur statistiques: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des statistiques.',
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
     * Récupère le volume de décisions par jour (7 derniers jours).
     */
    private function getDailyDecisionsVolume()
    {
        $days = 7;
        $endDate = Carbon::now()->endOfDay();
        $startDate = Carbon::now()->subDays($days - 1)->startOfDay();

        $dailyData = Decision::select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('COUNT(*) as count')
            )
            ->whereBetween('created_at', [$startDate, $endDate])
            ->groupBy('date')
            ->get()
            ->keyBy('date');

        $fullData = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $currentDate = Carbon::now()->subDays($i);
            $dateString = $currentDate->toDateString();

            $count = $dailyData->has($dateString) ? (int) $dailyData[$dateString]['count'] : 0;

            $fullData[] = [
                'date' => $dateString,
                'count' => $count,
            ];
        }

        return $fullData;
    }

    /**
     * Génère des alertes système.
     */
    private function generateSystemAlerts($userStatusCounts, $portPerformance, $decisions)
    {
        $alerts = [];

        // Alertes utilisateurs inactifs
        $inactiveSupervisors = $userStatusCounts['supervisors']['inactive'] + $userStatusCounts['supervisors']['suspended'];
        if ($inactiveSupervisors > 0) {
            $alerts[] = [
                'type' => 'warning',
                'category' => 'users',
                'message' => "{$inactiveSupervisors} superviseur(s) inactif(s) ou suspendu(s).",
            ];
        }

        $inactiveAgents = $userStatusCounts['agents']['inactive'] + $userStatusCounts['agents']['suspended'];
        if ($inactiveAgents > 0) {
            $alerts[] = [
                'type' => 'warning',
                'category' => 'users',
                'message' => "{$inactiveAgents} agent(s) inactif(s) ou suspendu(s).",
            ];
        }

        // Ports sans activité
        $inactivePorts = $portPerformance->filter(fn($port) => $port['total_decisions'] === 0);
        if ($inactivePorts->count() > 0) {
            $alerts[] = [
                'type' => 'info',
                'category' => 'ports',
                'message' => "{$inactivePorts->count()} port(s) sans activité durant cette période.",
                'ports' => $inactivePorts->pluck('name'),
            ];
        }

        // Ports avec taux de rejet élevé
        $highRejectionPorts = $portPerformance->filter(fn($port) =>
            $port['total_decisions'] > 20 && (100 - $port['acceptance_rate']) > 30
        );
        if ($highRejectionPorts->count() > 0) {
            $alerts[] = [
                'type' => 'danger',
                'category' => 'performance',
                'message' => "{$highRejectionPorts->count()} port(s) ont un taux de rejet supérieur à 30%.",
                'ports' => $highRejectionPorts->pluck('name'),
            ];
        }

        // Forte activité (bon signe)
        $highActivityPorts = $portPerformance->filter(fn($port) => $port['total_decisions'] > 100);
        if ($highActivityPorts->count() > 0) {
            $alerts[] = [
                'type' => 'success',
                'category' => 'performance',
                'message' => "{$highActivityPorts->count()} port(s) avec forte activité (>100 décisions).",
                'ports' => $highActivityPorts->pluck('name'),
            ];
        }

        // Alertes sur les tickets non traités
        $totalTickets = Ticket::count();
        $ticketsWithoutDecisions = Ticket::doesntHave('decisions')->count();
        if ($ticketsWithoutDecisions > 50) {
            $alerts[] = [
                'type' => 'warning',
                'category' => 'tickets',
                'message' => "{$ticketsWithoutDecisions} tickets créés mais jamais traités.",
            ];
        }

        return $alerts;
    }

    /**
     * Compare les statistiques avec la période précédente.
     */
    private function getGlobalComparison($timeFilter, $startDate)
    {
        $previousStart = match($timeFilter) {
            'day' => Carbon::now()->subDay()->startOfDay(),
            '3-days' => Carbon::now()->subDays(6)->startOfDay(),
            'week' => Carbon::now()->subWeek()->startOfWeek(),
            'month' => Carbon::now()->subMonth()->startOfMonth(),
            '3-months' => Carbon::now()->subMonths(6)->startOfDay(),
            'year' => Carbon::now()->subYear()->startOfYear(),
            default => Carbon::now()->subMonth()->startOfMonth(),
        };

        $previousEnd = $startDate;

        // Décisions période actuelle
        $currentDecisions = Decision::where('created_at', '>=', $startDate)->count();

        // Décisions période précédente
        $previousDecisions = Decision::whereBetween('created_at', [$previousStart, $previousEnd])->count();

        // Calcul du changement
        $change = $previousDecisions > 0
            ? round((($currentDecisions - $previousDecisions) / $previousDecisions) * 100, 1)
            : 0;

        // Taux d'acceptation période actuelle
        $currentAccepted = Decision::where('created_at', '>=', $startDate)
            ->where('decision', 'accepted')
            ->count();
        $currentAcceptanceRate = $currentDecisions > 0
            ? round(($currentAccepted / $currentDecisions) * 100, 1)
            : 0;

        // Taux d'acceptation période précédente
        $previousAccepted = Decision::whereBetween('created_at', [$previousStart, $previousEnd])
            ->where('decision', 'accepted')
            ->count();
        $previousAcceptanceRate = $previousDecisions > 0
            ? round(($previousAccepted / $previousDecisions) * 100, 1)
            : 0;

        $acceptanceRateChange = $previousAcceptanceRate > 0
            ? round($currentAcceptanceRate - $previousAcceptanceRate, 1)
            : 0;

        return [
            'decisions' => [
                'current_period' => $currentDecisions,
                'previous_period' => $previousDecisions,
                'change_percentage' => $change,
                'trend' => $change > 0 ? 'up' : ($change < 0 ? 'down' : 'stable')
            ],
            'acceptance_rate' => [
                'current_period' => $currentAcceptanceRate,
                'previous_period' => $previousAcceptanceRate,
                'change_percentage' => $acceptanceRateChange,
                'trend' => $acceptanceRateChange > 0 ? 'up' : ($acceptanceRateChange < 0 ? 'down' : 'stable')
            ],
            'period_info' => [
                'previous_start' => $previousStart->toIso8601String(),
                'previous_end' => $previousEnd->toIso8601String(),
            ]
        ];
    }
}
