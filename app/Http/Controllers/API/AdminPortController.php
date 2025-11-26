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
use App\Models\Port;
use App\Models\Ticket;
use Carbon\Carbon;

class AdminPortController extends Controller
{
    /**
     * Liste tous les ports avec leurs statistiques détaillées.
     */
    public function ports(Request $request)
    {
        try {
            $timeFilter = $request->input('filter', 'month');
            $searchQuery = $request->input('search', null);
            $typeFilter = $request->input('type', null);
            $statusFilter = $request->input('status', null);

            $startDate = $this->getStartDate($timeFilter);

            Log::info("Récupération ports - Filtre: {$timeFilter}, Recherche: {$searchQuery}");

            // Construction de la requête de base
            $query = Port::query();

            // Filtre par recherche (nom ou localisation)
            if ($searchQuery) {
                $query->where(function($q) use ($searchQuery) {
                    $q->where('name', 'like', "%{$searchQuery}%")
                      ->orWhere('location', 'like', "%{$searchQuery}%");
                });
            }

            // Filtre par type
            if ($typeFilter && $typeFilter !== 'all') {
                $query->where('type', $typeFilter);
            }

            // Filtre par statut
            if ($statusFilter && $statusFilter !== 'all') {
                $query->where('status', $statusFilter);
            }

            // Chargement des relations et compteurs
            $query->withCount([
                'users as admins_count' => function($q) {
                    $q->where('role', 'admin');
                },
                'users as supervisors_count' => function($q) {
                    $q->where('role', 'supervisor');
                },
                'users as agents_count' => function($q) {
                    $q->where('role', 'agent')->where('status', 'active');
                },
            ]);

            $ports = $query->get()->map(function($port) use ($startDate, $timeFilter) {
                // Décisions du port pour la période
                $portDecisions = Decision::where('port_of_action', $port->id)
                    ->where('created_at', '>=', $startDate)
                    ->get();

                $totalDecisions = $portDecisions->count();
                $acceptedDecisions = $portDecisions->where('decision', 'accepted')->count();
                $rejectedDecisions = $portDecisions->where('decision', 'rejected')->count();
                $acceptanceRate = $totalDecisions > 0
                    ? round(($acceptedDecisions / $totalDecisions) * 100, 1)
                    : 0;

                // Statistiques par type d'action
                $arrivals = $portDecisions->where('action_type', 'arrival')->count();
                $departures = $portDecisions->where('action_type', 'departure')->count();
                $arrivalsAccepted = $portDecisions->where('action_type', 'arrival')
                    ->where('decision', 'accepted')->count();
                $departuresAccepted = $portDecisions->where('action_type', 'departure')
                    ->where('decision', 'accepted')->count();

                // Temps moyen de traitement
                $avgProcessingTime = $portDecisions
                    ->filter(fn($d) => $d->ticket && $d->ticket->created_at)
                    ->map(function($decision) {
                        return Carbon::parse($decision->ticket->created_at)
                            ->diffInMinutes(Carbon::parse($decision->created_at));
                    })
                    ->avg();

                // Dernière activité
                $lastActivity = Decision::where('port_of_action', $port->id)
                    ->latest()
                    ->first();

                // Top nationalités du port
                $topNationalities = $portDecisions
                    ->filter(fn($d) => $d->ticket && $d->ticket->passengerForm)
                    ->map(fn($d) => $d->ticket->passengerForm->nationality ?? 'Inconnu')
                    ->countBy()
                    ->sortDesc()
                    ->take(5)
                    ->map(function($count, $nationality) {
                        return ['nationality' => $nationality, 'count' => $count];
                    })
                    ->values();

                // Comparaison avec la période précédente
                $previousStartDate = $this->getPreviousStartDate($timeFilter);
                $previousEndDate = $startDate;

                $previousDecisions = Decision::where('port_of_action', $port->id)
                    ->whereBetween('created_at', [$previousStartDate, $previousEndDate])
                    ->count();

                $growthPercentage = $previousDecisions > 0
                    ? round((($totalDecisions - $previousDecisions) / $previousDecisions) * 100, 1)
                    : 0;

                return [
                    'id' => $port->id,
                    'name' => $port->name,
                    'type' => $port->type,
                    'status' => $port->status,
                    'location' => $port->location,
                    'created_at' => $port->created_at->toIso8601String(),

                    // Compteurs utilisateurs
                    'admins_count' => $port->admins_count,
                    'supervisors_count' => $port->supervisors_count,
                    'agents_count' => $port->agents_count,
                    'total_staff' => $port->admins_count + $port->supervisors_count + $port->agents_count,

                    // Statistiques de décisions
                    'decisions_stats' => [
                        'total_decisions' => $totalDecisions,
                        'accepted' => $acceptedDecisions,
                        'rejected' => $rejectedDecisions,
                        'acceptance_rate' => $acceptanceRate,
                        'arrivals' => $arrivals,
                        'departures' => $departures,
                        'arrivals_accepted' => $arrivalsAccepted,
                        'departures_accepted' => $departuresAccepted,
                    ],

                    // Performance
                    'performance' => [
                        'avg_processing_time' => round($avgProcessingTime ?? 0, 1),
                        'growth_percentage' => $growthPercentage,
                        'trend' => $growthPercentage > 0 ? 'up' : ($growthPercentage < 0 ? 'down' : 'stable'),
                    ],

                    // Dernière activité
                    'last_activity' => $lastActivity ? [
                        'date' => $lastActivity->created_at->toIso8601String(),
                        'action_type' => $lastActivity->action_type,
                        'decision' => $lastActivity->decision,
                    ] : null,

                    // Top nationalités
                    'top_nationalities' => $topNationalities,

                    // Détails pour l'affichage
                    'details' => [
                        'admins' => User::where('port_id', $port->id)
                            ->where('role', 'admin')
                            ->select('id', 'name', 'email', 'status')
                            ->get(),
                        'supervisors' => User::where('port_id', $port->id)
                            ->where('role', 'supervisor')
                            ->select('id', 'name', 'email', 'status')
                            ->get(),
                        'agents' => User::where('port_id', $port->id)
                            ->where('role', 'agent')
                            ->select('id', 'name', 'email', 'status')
                            ->get(),
                    ],
                ];
            });

            // Statistiques globales
            $stats = [
                'total_ports' => Port::count(),
                'active_ports' => Port::where('status', 'active')->count(),
                'inactive_ports' => Port::where('status', 'inactive')->count(),
                'ports_by_type' => [
                    'air' => Port::where('type', 'air')->count(),
                    'sea' => Port::where('type', 'sea')->count(),
                    'land' => Port::where('type', 'land')->count(),
                ],
                'total_staff' => User::whereIn('role', ['admin', 'supervisor', 'agent'])
                    ->whereNotNull('port_id')
                    ->count(),
                'ports_without_staff' => Port::doesntHave('users')->count(),
            ];

            // Alertes et insights
            $alerts = $this->generatePortAlerts($ports, $stats);

            // Top 5 ports par activité
            $topPortsByActivity = $ports->sortByDesc('decisions_stats.total_decisions')
                ->take(5)
                ->values();

            Log::info("Trouvé {$ports->count()} ports");

            return response()->json([
                'success' => true,
                'message' => 'Ports récupérés avec succès',
                'data' => $ports->values(),
                'stats' => $stats,
                'top_ports_by_activity' => $topPortsByActivity,
                'alerts' => $alerts,
                'filters' => [
                    'time_filter' => $timeFilter,
                    'search' => $searchQuery,
                    'type' => $typeFilter,
                    'status' => $statusFilter,
                ],
                'period_start' => $startDate->toIso8601String(),
                'period_end' => Carbon::now()->toIso8601String(),
            ], 200);

        } catch (\Exception $e) {
            Log::error("Erreur récupération ports: " . $e->getMessage());
            Log::error($e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des ports.',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Crée un nouveau port.
     */
    public function storePort(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255|unique:ports,name',
                'type' => ['required', Rule::in(['air', 'sea', 'land'])],
                'status' => ['required', Rule::in(['active', 'inactive'])],
                'location' => 'nullable|string|max:255',
            ], [
                'name.required' => 'Le nom du port est obligatoire.',
                'name.unique' => 'Ce nom de port existe déjà.',
                'type.required' => 'Le type de port est obligatoire.',
                'type.in' => 'Le type doit être : aérien, maritime ou terrestre.',
                'status.in' => 'Le statut doit être actif ou inactif.',
            ]);

            $port = Port::create($validated);

            Log::info("Port créé: {$port->name} par admin " . Auth::id());

            return response()->json([
                'success' => true,
                'message' => "Le port '{$port->name}' a été créé avec succès.",
                'data' => $port
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error("Erreur création port: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création du port.',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Met à jour un port.
     */
    public function updatePort(Request $request, Port $port)
    {
        try {
            $validated = $request->validate([
                'name' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('ports', 'name')->ignore($port->id)
                ],
                'type' => ['required', Rule::in(['air', 'sea', 'land'])],
                'status' => ['required', Rule::in(['active', 'inactive'])],
                'location' => 'nullable|string|max:255',
            ], [
                'name.required' => 'Le nom du port est obligatoire.',
                'name.unique' => 'Ce nom de port existe déjà.',
                'type.in' => 'Le type doit être : aérien, maritime ou terrestre.',
                'status.in' => 'Le statut doit être actif ou inactif.',
            ]);

            $port->update($validated);

            Log::info("Port {$port->id} mis à jour par admin " . Auth::id());

            return response()->json([
                'success' => true,
                'message' => "Le port '{$port->name}' a été mis à jour avec succès.",
                'data' => $port->fresh()
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error("Erreur mise à jour port: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour.',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Supprime un port.
     */
    public function destroyPort(Port $port)
    {
        try {
            // Vérification : empêcher la suppression si des utilisateurs sont affectés
            $usersCount = User::where('port_id', $port->id)->count();
            if ($usersCount > 0) {
                return response()->json([
                    'success' => false,
                    'message' => "Impossible de supprimer le port '{$port->name}'. {$usersCount} utilisateur(s) y sont affectés. Veuillez les réaffecter d'abord ou désactiver le port."
                ], 409);
            }

            // Vérification : empêcher la suppression si des décisions existent
            $decisionsCount = Decision::where('port_of_action', $port->id)->count();
            if ($decisionsCount > 0) {
                return response()->json([
                    'success' => false,
                    'message' => "Impossible de supprimer le port '{$port->name}'. {$decisionsCount} décision(s) y sont liées. Veuillez désactiver le port au lieu de le supprimer pour préserver l'historique."
                ], 409);
            }

            $portId = $port->id;
            $portName = $port->name;
            $port->delete();

            Log::info("Port {$portId} ({$portName}) supprimé par admin " . Auth::id());

            return response()->json([
                'success' => true,
                'message' => "Le port '{$portName}' a été supprimé définitivement."
            ], 200);

        } catch (\Exception $e) {
            Log::error("Erreur suppression port: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression.',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Active ou désactive un port (toggle status).
     */
    public function togglePortStatus(Request $request, Port $port)
    {
        try {
            $validated = $request->validate([
                'status' => ['required', Rule::in(['active', 'inactive'])],
            ]);

            $newStatus = $validated['status'];
            $port->update(['status' => $newStatus]);

            Log::info("Statut port {$port->id} changé en {$newStatus} par admin " . Auth::id());

            $message = ($newStatus === 'inactive')
                ? "Le port '{$port->name}' a été désactivé."
                : "Le port '{$port->name}' a été activé.";

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => [
                    'id' => $port->id,
                    'status' => $newStatus
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error("Erreur toggle status port: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du changement de statut.',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Retourne les statistiques détaillées d'un port spécifique.
     */
    public function getPortStatistics(Request $request, Port $port)
    {
        try {
            $timeFilter = $request->input('filter', 'month');
            $startDate = $this->getStartDate($timeFilter);

            // Statistiques des utilisateurs
            $usersStats = [
                'admins' => User::where('port_id', $port->id)->where('role', 'admin')->count(),
                'supervisors' => User::where('port_id', $port->id)->where('role', 'supervisor')->count(),
                'agents' => User::where('port_id', $port->id)->where('role', 'agent')->count(),
                'active_agents' => User::where('port_id', $port->id)
                    ->where('role', 'agent')
                    ->where('status', 'active')
                    ->count(),
            ];

            // Statistiques des décisions
            $decisions = Decision::where('port_of_action', $port->id)
                ->where('created_at', '>=', $startDate)
                ->get();

            $decisionsStats = [
                'total' => $decisions->count(),
                'accepted' => $decisions->where('decision', 'accepted')->count(),
                'rejected' => $decisions->where('decision', 'rejected')->count(),
                'arrivals' => $decisions->where('action_type', 'arrival')->count(),
                'departures' => $decisions->where('action_type', 'departure')->count(),
            ];

            // Évolution quotidienne (7 derniers jours)
            $dailyActivity = $this->getDailyActivityForPort($port->id, 7);

            return response()->json([
                'success' => true,
                'data' => [
                    'port' => [
                        'id' => $port->id,
                        'name' => $port->name,
                        'type' => $port->type,
                        'status' => $port->status,
                        'location' => $port->location,
                    ],
                    'users_stats' => $usersStats,
                    'decisions_stats' => $decisionsStats,
                    'daily_activity' => $dailyActivity,
                    'period_start' => $startDate->toIso8601String(),
                    'period_end' => Carbon::now()->toIso8601String(),
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error("Erreur statistiques port: " . $e->getMessage());
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
     * Retourne la date de début de la période précédente.
     */
    private function getPreviousStartDate($timeFilter)
    {
        return match($timeFilter) {
            'day' => Carbon::now()->subDay()->startOfDay(),
            '3-days' => Carbon::now()->subDays(6)->startOfDay(),
            'week' => Carbon::now()->subWeek()->startOfWeek(),
            'month' => Carbon::now()->subMonth()->startOfMonth(),
            '3-months' => Carbon::now()->subMonths(6)->startOfDay(),
            'year' => Carbon::now()->subYear()->startOfYear(),
            default => Carbon::now()->subMonth()->startOfMonth(),
        };
    }

    /**
     * Récupère l'activité quotidienne d'un port sur N jours.
     */
    private function getDailyActivityForPort($portId, $days = 7)
    {
        $endDate = Carbon::now()->endOfDay();
        $startDate = Carbon::now()->subDays($days - 1)->startOfDay();

        $dailyData = Decision::where('port_of_action', $portId)
            ->select(
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
     * Génère des alertes pour les ports.
     */
    private function generatePortAlerts($ports, $stats)
    {
        $alerts = [];

        // Ports inactifs
        $inactivePorts = $ports->where('status', 'inactive');
        if ($inactivePorts->count() > 0) {
            $alerts[] = [
                'type' => 'warning',
                'category' => 'status',
                'message' => "{$inactivePorts->count()} port(s) sont actuellement inactifs.",
                'ports' => $inactivePorts->pluck('name'),
            ];
        }

        // Ports sans personnel
        $portsWithoutStaff = $ports->filter(fn($p) => $p['total_staff'] === 0);
        if ($portsWithoutStaff->count() > 0) {
            $alerts[] = [
                'type' => 'danger',
                'category' => 'staff',
                'message' => "{$portsWithoutStaff->count()} port(s) n'ont aucun personnel affecté.",
                'ports' => $portsWithoutStaff->pluck('name'),
            ];
        }

        // Ports sans activité
        $portsWithoutActivity = $ports->filter(fn($p) => $p['decisions_stats']['total_decisions'] === 0);
        if ($portsWithoutActivity->count() > 0) {
            $alerts[] = [
                'type' => 'info',
                'category' => 'activity',
                'message' => "{$portsWithoutActivity->count()} port(s) n'ont enregistré aucune activité durant la période.",
                'ports' => $portsWithoutActivity->pluck('name'),
            ];
        }

        // Ports avec faible taux d'acceptation
        $portsLowAcceptance = $ports->filter(fn($p) =>
            $p['decisions_stats']['total_decisions'] > 20 &&
            $p['decisions_stats']['acceptance_rate'] < 70
        );
        if ($portsLowAcceptance->count() > 0) {
            $alerts[] = [
                'type' => 'warning',
                'category' => 'performance',
                'message' => "{$portsLowAcceptance->count()} port(s) ont un taux d'acceptation inférieur à 70%.",
                'ports' => $portsLowAcceptance->pluck('name'),
            ];
        }

        // Ports avec forte croissance
        $portsHighGrowth = $ports->filter(fn($p) =>
            $p['performance']['growth_percentage'] > 50
        );
        if ($portsHighGrowth->count() > 0) {
            $alerts[] = [
                'type' => 'success',
                'category' => 'growth',
                'message' => "{$portsHighGrowth->count()} port(s) connaissent une forte croissance d'activité (+50%).",
                'ports' => $portsHighGrowth->pluck('name'),
            ];
        }

        return $alerts;
    }
}
