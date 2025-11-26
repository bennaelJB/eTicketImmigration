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
use Carbon\Carbon;

class AdminAgentController extends Controller
{
    /**
     * Liste tous les agents avec leurs statistiques détaillées.
     */
    public function agents(Request $request)
    {
        try {
            $timeFilter = $request->input('filter', 'month');
            $searchQuery = $request->input('search', null);
            $statusFilter = $request->input('status', null);
            $portFilter = $request->input('port_id', null);

            $startDate = $this->getStartDate($timeFilter);

            Log::info("Récupération agents - Filtre: {$timeFilter}, Recherche: {$searchQuery}");

            // Construction de la requête
            $query = User::where('role', 'agent')->with('port');

            // Filtre par recherche (nom ou email)
            if ($searchQuery) {
                $query->where(function($q) use ($searchQuery) {
                    $q->where('name', 'like', "%{$searchQuery}%")
                      ->orWhere('email', 'like', "%{$searchQuery}%");
                });
            }

            // Filtre par statut
            if ($statusFilter && $statusFilter !== 'all') {
                $query->where('status', $statusFilter);
            }

            // Filtre par port
            if ($portFilter) {
                $query->where('port_id', $portFilter);
            }

            $agents = $query->withCount([
                'decisions as total_scans' => function($q) use ($startDate) {
                    $q->where('created_at', '>=', $startDate);
                },
                'decisions as accepted' => function($q) use ($startDate) {
                    $q->where('created_at', '>=', $startDate)->where('decision', 'accepted');
                },
                'decisions as rejected' => function($q) use ($startDate) {
                    $q->where('created_at', '>=', $startDate)->where('decision', 'rejected');
                },
                'decisions as arrivals' => function($q) use ($startDate) {
                    $q->where('created_at', '>=', $startDate)->where('action_type', 'arrival');
                },
                'decisions as departures' => function($q) use ($startDate) {
                    $q->where('created_at', '>=', $startDate)->where('action_type', 'departure');
                }
            ])
            ->get()
            ->map(function($agent) use ($startDate) {
                $acceptanceRate = $agent->total_scans > 0
                    ? round(($agent->accepted / $agent->total_scans) * 100, 1)
                    : 0;

                $lastActivity = Decision::where('user_id', $agent->id)->latest()->first();

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
                    'created_at' => $agent->created_at->toIso8601String(),
                    'port' => $agent->port ? [
                        'id' => $agent->port->id,
                        'name' => $agent->port->name,
                        'status' => $agent->port->status,
                    ] : null,
                    'port_id' => $agent->port_id,
                    'port_name' => $agent->port ? $agent->port->name : 'Non Affecté',
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
                        'action_type' => $lastActivity->action_type,
                        'decision' => $lastActivity->decision,
                    ] : null,
                ];
            });

            // Liste des ports pour les filtres
            $ports = Port::where('status', 'active')
                ->select('id', 'name')
                ->get()
                ->map(function($port) {
                    return [
                        'value' => $port->id,
                        'title' => $port->name,
                    ];
                });

            // Statistiques globales
            $stats = [
                'total_agents' => User::where('role', 'agent')->count(),
                'active_agents' => User::where('role', 'agent')->where('status', 'active')->count(),
                'suspended_agents' => User::where('role', 'agent')->where('status', 'suspended')->count(),
                'total_scans' => Decision::where('created_at', '>=', $startDate)->count(),
            ];

            Log::info("Trouvé {$agents->count()} agents");

            return response()->json([
                'success' => true,
                'message' => 'Agents récupérés avec succès',
                'data' => $agents->values(),
                'ports' => $ports,
                'stats' => $stats,
                'filters' => [
                    'time_filter' => $timeFilter,
                    'search' => $searchQuery,
                    'status' => $statusFilter,
                    'port_id' => $portFilter,
                ],
                'period_start' => $startDate->toIso8601String(),
                'period_end' => Carbon::now()->toIso8601String(),
            ], 200);

        } catch (\Exception $e) {
            Log::error("Erreur récupération agents: " . $e->getMessage());
            Log::error($e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des agents.',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Crée un nouvel agent.
     */
    public function storeAgent(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|email|unique:users,email',
                'password' => 'required|string|min:8',
                'port_id' => 'required|exists:ports,id',
            ]);

            $agent = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'role' => 'agent',
                'port_id' => $validated['port_id'],
                'status' => 'active',
            ]);

            Log::info("Agent créé: {$agent->email} par admin " . Auth::id());

            return response()->json([
                'success' => true,
                'message' => 'Agent créé avec succès',
                'data' => $agent->load('port')
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error("Erreur création agent: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création de l\'agent.',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Met à jour un agent.
     */
    public function updateAgent(Request $request, User $agent)
    {
        try {
            if ($agent->role !== 'agent') {
                return response()->json([
                    'success' => false,
                    'message' => 'Cet utilisateur n\'est pas un agent.'
                ], 403);
            }

            $validated = $request->validate([
                'name' => 'sometimes|string|max:255',
                'email' => 'sometimes|email|unique:users,email,' . $agent->id,
                'port_id' => 'sometimes|exists:ports,id',
                'status' => 'sometimes|in:active,inactive,suspended',
            ]);

            $agent->update($validated);

            Log::info("Agent {$agent->id} mis à jour par admin " . Auth::id());

            return response()->json([
                'success' => true,
                'message' => 'Agent mis à jour avec succès',
                'data' => $agent->fresh(['port'])
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error("Erreur mise à jour agent: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour.',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Supprime un agent.
     */
    public function destroyAgent(User $agent)
    {
        try {
            if ($agent->role !== 'agent') {
                return response()->json([
                    'success' => false,
                    'message' => 'Cet utilisateur n\'est pas un agent.'
                ], 403);
            }

            $agentId = $agent->id;
            $agentName = $agent->name;
            $agent->delete();

            Log::info("Agent {$agentId} ({$agentName}) supprimé par admin " . Auth::id());

            return response()->json([
                'success' => true,
                'message' => 'Agent supprimé avec succès'
            ], 200);

        } catch (\Exception $e) {
            Log::error("Erreur suppression agent: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression.',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Bascule le statut d'un agent.
     */
    public function toggleAgentStatus(Request $request, User $agent)
    {
        try {
            if ($agent->role !== 'agent') {
                return response()->json([
                    'success' => false,
                    'message' => 'Cet utilisateur n\'est pas un agent.'
                ], 403);
            }

            $validated = $request->validate([
                'status' => ['required', Rule::in(['active', 'suspended'])],
            ]);

            $newStatus = $validated['status'];
            $agent->update(['status' => $newStatus]);

            Log::info("Statut agent {$agent->id} changé en {$newStatus} par admin " . Auth::id());

            $message = ($newStatus === 'suspended')
                ? 'Le compte agent a été suspendu.'
                : 'Le compte agent a été activé.';

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => [
                    'id' => $agent->id,
                    'status' => $newStatus
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error("Erreur toggle status agent: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du changement de statut.',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Réinitialise le mot de passe d'un agent à son email.
     */
    public function resetAgentPassword(User $agent)
    {
        try {
            if ($agent->role !== 'agent') {
                return response()->json([
                    'success' => false,
                    'message' => 'Cet utilisateur n\'est pas un agent.'
                ], 403);
            }

            // Le nouveau mot de passe est l'email de l'agent
            $newPassword = $agent->email;

            $agent->update([
                'password' => Hash::make($newPassword)
            ]);

            Log::info("Mot de passe agent {$agent->id} réinitialisé à son email par admin " . Auth::id());

            return response()->json([
                'success' => true,
                'message' => "Le mot de passe de {$agent->name} a été réinitialisé à son adresse email ({$agent->email}). Veuillez lui communiquer ce mot de passe temporaire et lui demander de le changer immédiatement.",
                'data' => [
                    'agent_name' => $agent->name,
                    'temporary_password' => $agent->email
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error("Erreur reset password agent: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la réinitialisation.',
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
}
