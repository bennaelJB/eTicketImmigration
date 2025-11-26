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

class AdminReportController extends Controller
{
    // Durée de séjour légale pour les étrangers (en jours)
    const STAY_DURATION_DAYS = 90;

    /**
     * Rapports sur les voyageurs (démographie, dépassements de séjour, analyses avancées).
     */
    public function reports(Request $request)
    {
        try {
            $timeFilter = $request->input('filter', 'month');
            $portFilter = $request->input('port_id', null);
            $nationalityFilter = $request->input('nationality', null);
            $startDate = $request->input('start_date', null);
            $endDate = $request->input('end_date', null);

            $dateStart = $startDate ? Carbon::parse($startDate)->startOfDay() : $this->getStartDate($timeFilter);
            $dateEnd = $endDate ? Carbon::parse($endDate)->endOfDay() : Carbon::now()->endOfDay();

            Log::info("Génération rapports voyageurs - Filtre: {$timeFilter}");

            // ===== 1️⃣ STATISTIQUES DÉMOGRAPHIQUES =====
            $decisions = Decision::whereBetween('decisions.created_at', [$dateStart, $dateEnd])
                ->with('ticket.passengerForm')
                ->get();

            // Top nationalités
            $topNationalities = $decisions
                ->filter(fn($d) => $d->ticket && $d->ticket->passengerForm)
                ->map(fn($d) => $d->ticket->passengerForm->nationality ?? 'Inconnu')
                ->countBy()
                ->sortDesc()
                ->take(15)
                ->map(function($count, $nationality) {
                    return [
                        'nationality' => $nationality,
                        'count' => $count
                    ];
                })
                ->values();

            // Répartition par genre
            $genderDistribution = $decisions
                ->filter(fn($d) => $d->ticket && $d->ticket->passengerForm)
                ->map(fn($d) => $d->ticket->passengerForm->gender ?? 'Non spécifié')
                ->countBy()
                ->map(function($count, $gender) {
                    return [
                        'gender' => $gender,
                        'count' => $count
                    ];
                })
                ->values();

            // Répartition par type de voyageur
            $travelerTypes = Ticket::whereBetween('tickets.created_at', [$dateStart, $dateEnd])
                ->select('passenger_type', DB::raw('COUNT(*) as count'))
                ->groupBy('passenger_type')
                ->get()
                ->map(fn($item) => [
                    'type' => $item->passenger_type,
                    'count' => $item->count,
                    'label' => $item->passenger_type === 'haitian' ? 'Nationaux' : 'Étrangers'
                ]);

            // Répartition par tranche d'âge
            $ageDistribution = $decisions
                ->filter(fn($d) => $d->ticket && $d->ticket->passengerForm && $d->ticket->passengerForm->date_of_birth)
                ->map(function($decision) {
                    $age = Carbon::parse($decision->ticket->passengerForm->date_of_birth)->age;
                    if ($age < 18) return '0-17';
                    if ($age < 30) return '18-29';
                    if ($age < 45) return '30-44';
                    if ($age < 60) return '45-59';
                    return '60+';
                })
                ->countBy()
                ->map(function($count, $range) {
                    return [
                        'age_range' => $range,
                        'count' => $count
                    ];
                })
                ->sortBy('age_range')
                ->values();

            // ===== 2️⃣ DÉPASSEMENTS DE SÉJOUR =====
            $overstayDeadline = Carbon::now()->subDays(self::STAY_DURATION_DAYS);

            $foreignersOverstayQuery = Ticket::query()
                ->select('tickets.*', 'arrival_decisions.created_at as arrival_date')
                ->join('decisions as arrival_decisions', function($join) {
                    $join->on('arrival_decisions.ticket_id', '=', 'tickets.id')
                         ->where('arrival_decisions.action_type', 'arrival')
                         ->where('arrival_decisions.decision', 'accepted');
                })
                ->join('passenger_forms', 'passenger_forms.ticket_id', '=', 'tickets.id')
                ->where('tickets.passenger_type', 'foreigner')
                ->where('arrival_decisions.created_at', '<', $overstayDeadline)
                ->whereNotExists(function ($query) {
                    $query->select(DB::raw(1))
                          ->from('decisions as departure_decisions')
                          ->whereColumn('departure_decisions.ticket_id', 'tickets.id')
                          ->where('departure_decisions.action_type', 'departure')
                          ->where('departure_decisions.decision', 'accepted');
                });

            // Filtres optionnels pour overstay
            if ($portFilter) {
                $foreignersOverstayQuery->where('arrival_decisions.port_of_action', $portFilter);
            }

            if ($nationalityFilter) {
                $foreignersOverstayQuery->where('passenger_forms.nationality', $nationalityFilter);
            }

            $foreignersOverstay = $foreignersOverstayQuery
                ->with('passengerForm')
                ->get()
                ->map(function($ticket) {
                    $arrivalDecision = Decision::where('ticket_id', $ticket->id)
                        ->where('action_type', 'arrival')
                        ->where('decision', 'accepted')
                        ->orderBy('created_at', 'desc')
                        ->first();

                    $arrivalDate = $arrivalDecision ? Carbon::parse($arrivalDecision->created_at) : null;
                    $daysInCountry = $arrivalDate ? $arrivalDate->diffInDays(Carbon::now()) : 0;
                    $daysOverstay = max($daysInCountry - self::STAY_DURATION_DAYS, 0);

                    // Calcul du niveau de criticité
                    $criticalityLevel = 'low';
                    if ($daysOverstay > 180) $criticalityLevel = 'critical';
                    elseif ($daysOverstay > 90) $criticalityLevel = 'high';
                    elseif ($daysOverstay > 30) $criticalityLevel = 'medium';

                    return [
                        'ticket_id' => $ticket->id,
                        'ticket_no' => $ticket->ticket_no,
                        'full_name' => trim(($ticket->passengerForm->first_name ?? '') . ' ' . ($ticket->passengerForm->last_name ?? '')),
                        'passport_number' => $ticket->passengerForm->passport_number ?? 'N/A',
                        'nationality' => $ticket->passengerForm->nationality ?? 'N/A',
                        'arrival_date' => $arrivalDate ? $arrivalDate->toIso8601String() : null,
                        'days_in_country' => $daysInCountry,
                        'days_overstay' => $daysOverstay,
                        'criticality_level' => $criticalityLevel,
                        'residence_city' => $ticket->passengerForm->haiti_city ?? 'N/A',
                        'residence_address' => $ticket->passengerForm->haiti_address ?? 'N/A',
                        'phone' => $ticket->passengerForm->phone ?? 'N/A',
                        'port_of_entry' => $ticket->passengerForm->portOfEntryName(),
                    ];
                });

            // Statistiques de dépassement par niveau de criticité
            $overstayByCriticality = $foreignersOverstay->countBy('criticality_level')
                ->map(function($count, $level) {
                    return [
                        'level' => $level,
                        'count' => $count
                    ];
                })
                ->values();

            // Dépassements par nationalité
            $overstayByNationality = $foreignersOverstay->groupBy('nationality')
                ->map(function($group, $nationality) {
                    return [
                        'nationality' => $nationality,
                        'count' => $group->count(),
                        'avg_days_overstay' => round($group->avg('days_overstay'), 1)
                    ];
                })
                ->sortByDesc('count')
                ->take(10)
                ->values();

            // ===== 3️⃣ STATISTIQUES DE MOUVEMENTS =====
            $arrivalDecisions = $decisions->where('action_type', 'arrival');
            $departureDecisions = $decisions->where('action_type', 'departure');

            $movementStats = [
                'total_arrivals' => $arrivalDecisions->count(),
                'accepted_arrivals' => $arrivalDecisions->where('decision', 'accepted')->count(),
                'rejected_arrivals' => $arrivalDecisions->where('decision', 'rejected')->count(),
                'total_departures' => $departureDecisions->count(),
                'accepted_departures' => $departureDecisions->where('decision', 'accepted')->count(),
                'rejected_departures' => $departureDecisions->where('decision', 'rejected')->count(),
                'net_migration' => $arrivalDecisions->where('decision', 'accepted')->count() -
                                  $departureDecisions->where('decision', 'accepted')->count(),
                'arrival_acceptance_rate' => $arrivalDecisions->count() > 0
                    ? round(($arrivalDecisions->where('decision', 'accepted')->count() / $arrivalDecisions->count()) * 100, 1)
                    : 0,
            ];

            // ===== 4️⃣ FLUX PAR PORT =====
            $flowByPort = Port::select('ports.id', 'ports.name')
                ->leftJoin('decisions', 'ports.id', '=', 'decisions.port_of_action')
                ->whereBetween('decisions.created_at', [$dateStart, $dateEnd])
                ->groupBy('ports.id', 'ports.name')
                ->selectRaw('
                    COUNT(CASE WHEN decisions.action_type = "arrival" THEN 1 END) as arrivals,
                    COUNT(CASE WHEN decisions.action_type = "departure" THEN 1 END) as departures,
                    COUNT(CASE WHEN decisions.action_type = "arrival" AND decisions.decision = "accepted" THEN 1 END) as accepted_arrivals,
                    COUNT(CASE WHEN decisions.action_type = "arrival" AND decisions.decision = "rejected" THEN 1 END) as rejected_arrivals
                ')
                ->get()
                ->map(function($port) {
                    $acceptanceRate = $port->arrivals > 0
                        ? round(($port->accepted_arrivals / $port->arrivals) * 100, 1)
                        : 0;

                    return [
                        'port_id' => $port->id,
                        'port_name' => $port->name,
                        'arrivals' => $port->arrivals,
                        'departures' => $port->departures,
                        'accepted_arrivals' => $port->accepted_arrivals,
                        'rejected_arrivals' => $port->rejected_arrivals,
                        'acceptance_rate' => $acceptanceRate,
                        'net_flow' => $port->arrivals - $port->departures,
                    ];
                })
                ->sortByDesc('arrivals')
                ->values();

            // ===== 5️⃣ ÉVOLUTION TEMPORELLE =====
            $dailyEvolution = $this->getDailyTravelersEvolution($dateStart, $dateEnd);

            // ===== 6️⃣ ÉTRANGERS ACTUELLEMENT SUR LE TERRITOIRE =====
            $foreignersInCountry = $this->getForeignersCurrentlyInCountry();

            // Statistiques des étrangers présents
            $foreignersStats = [
                'total_count' => $foreignersInCountry->count(),
                'approaching_limit' => $foreignersInCountry->filter(fn($f) =>
                    $f['days_remaining'] <= 30 && $f['days_remaining'] > 0
                )->count(),
                'already_overstay' => $foreignersInCountry->where('is_overstay', true)->count(),
                'avg_days_stayed' => round($foreignersInCountry->avg('days_passed'), 1),
            ];

            // Répartition par durée de séjour
            $stayDurationDistribution = $foreignersInCountry->groupBy(function($foreigner) {
                $days = $foreigner['days_passed'];
                if ($days <= 30) return '0-30 jours';
                if ($days <= 60) return '31-60 jours';
                if ($days <= 90) return '61-90 jours';
                if ($days <= 120) return '91-120 jours';
                return '120+ jours';
            })->map(function($group, $range) {
                return [
                    'range' => $range,
                    'count' => $group->count()
                ];
            })->values();

            // ===== 7️⃣ COMPARAISON AVEC PÉRIODE PRÉCÉDENTE =====
            $comparison = $this->getPeriodComparison($timeFilter, $dateStart, $dateEnd);

            // ===== 8️⃣ INSIGHTS ET ALERTES =====
            $alerts = $this->generateReportAlerts(
                $foreignersOverstay,
                $movementStats,
                $foreignersStats,
                $flowByPort
            );

            // ===== 9️⃣ OPTIONS DE FILTRES =====
            $ports = Port::where('status', 'active')->get(['id', 'name']);

            // FIX: Spécifier explicitement la table pour created_at
            $nationalities = Ticket::select('passenger_forms.nationality')
                ->join('passenger_forms', 'passenger_forms.ticket_id', '=', 'tickets.id')
                ->whereBetween('tickets.created_at', [$dateStart, $dateEnd]) // Préciser tickets.created_at
                ->whereNotNull('passenger_forms.nationality')
                ->distinct()
                ->pluck('passenger_forms.nationality')
                ->sort()
                ->values();

            Log::info("Rapports voyageurs générés avec succès");

            return response()->json([
                'success' => true,
                'message' => 'Rapports voyageurs récupérés avec succès',
                'data' => [
                    // Démographie
                    'demographics' => [
                        'top_nationalities' => $topNationalities,
                        'traveler_types' => $travelerTypes,
                        'gender_distribution' => $genderDistribution,
                        'age_distribution' => $ageDistribution,
                    ],

                    // Dépassements de séjour
                    'overstay' => [
                        'total_count' => $foreignersOverstay->count(),
                        'travelers' => $foreignersOverstay,
                        'by_criticality' => $overstayByCriticality,
                        'by_nationality' => $overstayByNationality,
                    ],

                    // Mouvements
                    'movement_stats' => $movementStats,
                    'flow_by_port' => $flowByPort,
                    'daily_evolution' => $dailyEvolution,

                    // Étrangers actuellement présents
                    'foreigners_in_country' => [
                        'statistics' => $foreignersStats,
                        'stay_duration_distribution' => $stayDurationDistribution,
                        'list' => $foreignersInCountry->take(100), // Limiter pour la performance
                    ],

                    // Comparaison
                    'comparison' => $comparison,

                    // Insights
                    'alerts' => $alerts,
                ],

                // Options de filtres
                'filter_options' => [
                    'ports' => $ports,
                    'nationalities' => $nationalities,
                ],

                // Métadonnées
                'filters' => [
                    'time_filter' => $timeFilter,
                    'port_id' => $portFilter,
                    'nationality' => $nationalityFilter,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                ],
                'period_start' => $dateStart->toIso8601String(),
                'period_end' => $dateEnd->toIso8601String(),
            ], 200);

        } catch (\Exception $e) {
            Log::error("Erreur rapports voyageurs: " . $e->getMessage());
            Log::error($e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la génération des rapports.',
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
     * Récupère l'évolution journalière des voyageurs.
     */
    private function getDailyTravelersEvolution($startDate, $endDate)
    {
        $dailyData = Decision::select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('COUNT(CASE WHEN action_type = "arrival" THEN 1 END) as arrivals'),
                DB::raw('COUNT(CASE WHEN action_type = "departure" THEN 1 END) as departures')
            )
            ->whereBetween('created_at', [$startDate, $endDate])
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return $dailyData->map(function($day) {
            return [
                'date' => $day->date,
                'arrivals' => (int) $day->arrivals,
                'departures' => (int) $day->departures,
                'net_flow' => (int) ($day->arrivals - $day->departures),
            ];
        });
    }

    /**
     * Récupère la liste des étrangers actuellement sur le territoire.
     */
    private function getForeignersCurrentlyInCountry()
    {
        $foreignersQuery = Ticket::query()
            ->where('passenger_type', 'foreigner')
            ->with(['passengerForm', 'decisions' => function($q) {
                $q->orderBy('created_at', 'desc');
            }]);

        $foreigners = $foreignersQuery->get()->filter(function ($ticket) {
            $latestArrival = $ticket->decisions->where('action_type', 'arrival')
                ->where('decision', 'accepted')
                ->first();

            $hasDeparted = $ticket->decisions->contains(function($d) {
                return $d->action_type === 'departure' && $d->decision === 'accepted';
            });

            return $latestArrival && !$hasDeparted;
        })->map(function ($ticket) {
            $passenger = $ticket->passengerForm;
            $latestArrival = $ticket->decisions->where('action_type', 'arrival')
                ->where('decision', 'accepted')
                ->first();

            $arrivalDate = $latestArrival ? Carbon::parse($latestArrival->created_at) : null;
            $daysPassed = $arrivalDate ? Carbon::now()->diffInDays($arrivalDate) : 0;
            $daysRemaining = max(self::STAY_DURATION_DAYS - $daysPassed, 0);
            $isOverstay = $daysPassed > self::STAY_DURATION_DAYS;

            return [
                'id' => $ticket->id,
                'full_name' => trim($passenger->first_name . ' ' . $passenger->last_name),
                'passport_number' => $passenger->passport_number,
                'nationality' => $passenger->nationality,
                'arrival_date' => $arrivalDate ? $arrivalDate->toDateString() : null,
                'days_passed' => $daysPassed,
                'days_remaining' => $daysRemaining,
                'is_overstay' => $isOverstay,
                'port_of_entry' => $passenger->portOfEntryName(),
                'ticket_number' => $ticket->ticket_no,
                'residence_city' => $passenger->haiti_city ?? 'N/A',
            ];
        });

        return $foreigners;
    }

    /**
     * Compare les statistiques avec la période précédente.
     */
    private function getPeriodComparison($timeFilter, $currentStart, $currentEnd)
    {
        $periodDuration = $currentStart->diffInDays($currentEnd);
        $previousEnd = $currentStart->copy()->subDay();
        $previousStart = $previousEnd->copy()->subDays($periodDuration);

        // Période actuelle
        $currentArrivals = Decision::whereBetween('created_at', [$currentStart, $currentEnd])
            ->where('action_type', 'arrival')
            ->count();

        $currentDepartures = Decision::whereBetween('created_at', [$currentStart, $currentEnd])
            ->where('action_type', 'departure')
            ->count();

        // Période précédente
        $previousArrivals = Decision::whereBetween('created_at', [$previousStart, $previousEnd])
            ->where('action_type', 'arrival')
            ->count();

        $previousDepartures = Decision::whereBetween('created_at', [$previousStart, $previousEnd])
            ->where('action_type', 'departure')
            ->count();

        // Calcul des variations
        $arrivalChange = $previousArrivals > 0
            ? round((($currentArrivals - $previousArrivals) / $previousArrivals) * 100, 1)
            : 0;

        $departureChange = $previousDepartures > 0
            ? round((($currentDepartures - $previousDepartures) / $previousDepartures) * 100, 1)
            : 0;

        return [
            'arrivals' => [
                'current_period' => $currentArrivals,
                'previous_period' => $previousArrivals,
                'change_percentage' => $arrivalChange,
                'trend' => $arrivalChange > 0 ? 'up' : ($arrivalChange < 0 ? 'down' : 'stable')
            ],
            'departures' => [
                'current_period' => $currentDepartures,
                'previous_period' => $previousDepartures,
                'change_percentage' => $departureChange,
                'trend' => $departureChange > 0 ? 'up' : ($departureChange < 0 ? 'down' : 'stable')
            ],
            'net_migration' => [
                'current_period' => $currentArrivals - $currentDepartures,
                'previous_period' => $previousArrivals - $previousDepartures,
            ],
            'period_info' => [
                'previous_start' => $previousStart->toIso8601String(),
                'previous_end' => $previousEnd->toIso8601String(),
            ]
        ];
    }

    /**
     * Génère des alertes et insights sur les rapports voyageurs.
     */
    private function generateReportAlerts($foreignersOverstay, $movementStats, $foreignersStats, $flowByPort)
    {
        $alerts = [];

        // ===== ALERTES DÉPASSEMENTS DE SÉJOUR =====
        $criticalOverstays = $foreignersOverstay->where('criticality_level', 'critical')->count();
        if ($criticalOverstays > 0) {
            $alerts[] = [
                'type' => 'danger',
                'category' => 'overstay',
                'priority' => 'high',
                'message' => "{$criticalOverstays} étranger(s) en dépassement critique (>180 jours).",
                'action_required' => true,
            ];
        }

        $highOverstays = $foreignersOverstay->where('criticality_level', 'high')->count();
        if ($highOverstays > 0) {
            $alerts[] = [
                'type' => 'warning',
                'category' => 'overstay',
                'priority' => 'medium',
                'message' => "{$highOverstays} étranger(s) en dépassement sévère (91-180 jours).",
                'action_required' => true,
            ];
        }

        // Alerte étrangers approchant la limite
        if ($foreignersStats['approaching_limit'] > 0) {
            $alerts[] = [
                'type' => 'info',
                'category' => 'stay_limit',
                'priority' => 'low',
                'message' => "{$foreignersStats['approaching_limit']} étranger(s) approchent de la limite de séjour (≤30 jours restants).",
                'action_required' => false,
            ];
        }

        // ===== ALERTES FLUX MIGRATOIRES =====
        if ($movementStats['net_migration'] < -100) {
            $alerts[] = [
                'type' => 'info',
                'category' => 'migration',
                'priority' => 'low',
                'message' => "Solde migratoire négatif important ({$movementStats['net_migration']}). Plus de départs que d'arrivées.",
                'action_required' => false,
            ];
        } elseif ($movementStats['net_migration'] > 100) {
            $alerts[] = [
                'type' => 'info',
                'category' => 'migration',
                'priority' => 'low',
                'message' => "Solde migratoire positif important (+{$movementStats['net_migration']}). Plus d'arrivées que de départs.",
                'action_required' => false,
            ];
        }

        // Taux de rejet élevé aux arrivées
        if ($movementStats['arrival_acceptance_rate'] < 70 && $movementStats['total_arrivals'] > 50) {
            $rejectionRate = 100 - $movementStats['arrival_acceptance_rate'];
            $alerts[] = [
                'type' => 'warning',
                'category' => 'arrivals',
                'priority' => 'medium',
                'message' => "Taux de rejet aux arrivées élevé ({$rejectionRate}%). Vérifier les procédures.",
                'action_required' => true,
            ];
        }

        // ===== ALERTES PORTS =====
        $portsWithLowAcceptance = $flowByPort->filter(fn($p) =>
            $p['arrivals'] > 20 && $p['acceptance_rate'] < 60
        );

        if ($portsWithLowAcceptance->count() > 0) {
            $portNames = $portsWithLowAcceptance->pluck('port_name')->join(', ');
            $alerts[] = [
                'type' => 'warning',
                'category' => 'ports',
                'priority' => 'medium',
                'message' => "Port(s) avec taux d'acceptation faible (<60%): {$portNames}",
                'action_required' => true,
            ];
        }

        // Ports sans activité
        $inactivePorts = $flowByPort->filter(fn($p) => $p['arrivals'] === 0 && $p['departures'] === 0);
        if ($inactivePorts->count() > 0) {
            $alerts[] = [
                'type' => 'info',
                'category' => 'ports',
                'priority' => 'low',
                'message' => "{$inactivePorts->count()} port(s) sans activité durant cette période.",
                'action_required' => false,
            ];
        }

        // ===== INSIGHTS POSITIFS =====
        if ($movementStats['arrival_acceptance_rate'] >= 90 && $movementStats['total_arrivals'] > 20) {
            $alerts[] = [
                'type' => 'success',
                'category' => 'performance',
                'priority' => 'low',
                'message' => "Excellent taux d'acceptation aux arrivées ({$movementStats['arrival_acceptance_rate']}%).",
                'action_required' => false,
            ];
        }

        if ($foreignersStats['already_overstay'] === 0 && $foreignersStats['total_count'] > 0) {
            $alerts[] = [
                'type' => 'success',
                'category' => 'compliance',
                'priority' => 'low',
                'message' => "Aucun dépassement de séjour actuellement. Excellente conformité.",
                'action_required' => false,
            ];
        }

        return $alerts;
    }
}
