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

class AdminSupervisorController extends Controller
{
    /**
     * Liste tous les superviseurs avec leurs statistiques détaillées.
     */
    public function supervisors(Request $request)
    {
        try {
            $timeFilter = $request->input('filter', 'month');
            $searchQuery = $request->input('search', null);
            $statusFilter = $request->input('status', null);
            $portFilter = $request->input('port_id', null);

            $startDate = $this->getStartDate($timeFilter);

            Log::info("Récupération superviseurs - Filtre: {$timeFilter}, Recherche: {$searchQuery}");

            // Construction de la requête
            $query = User::where('role', 'supervisor')->with('port');

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

            $supervisors = $query->get()->map(function($supervisor) use ($startDate) {
                // Statistiques du port supervisé
                $portStats = null;
                $managedAgentsCount = 0;

                if ($supervisor->port_id) {
                    // Décisions du port
                    $portDecisions = Decision::where('port_of_action', $supervisor->port_id)
                        ->where('created_at', '>=', $startDate)
                        ->get();

                    // Agents actifs du port
                    $managedAgentsCount = User::where('port_id', $supervisor->port_id)
                        ->where('role', 'agent')
                        ->where('status', 'active')
                        ->count();

                    $acceptanceRate = $portDecisions->count() > 0
                        ? round(($portDecisions->where('decision', 'accepted')->count() / $portDecisions->count()) * 100, 1)
                        : 0;

                    $portStats = [
                        'total_scans' => $portDecisions->count(),
                        'accepted' => $portDecisions->where('decision', 'accepted')->count(),
                        'rejected' => $portDecisions->where('decision', 'rejected')->count(),
                        'acceptance_rate' => $acceptanceRate,
                        'active_agents' => $managedAgentsCount,
                        'arrivals' => $portDecisions->where('action_type', 'arrival')->count(),
                        'departures' => $portDecisions->where('action_type', 'departure')->count(),
                    ];
                }

                // Dernière activité
                $lastActivity = Decision::whereHas('user', function($q) use ($supervisor) {
                    $q->where('port_id', $supervisor->port_id);
                })
                ->latest()
                ->first();

                // Temps moyen de traitement
                $avgProcessingTime = Decision::whereHas('user', function($q) use ($supervisor) {
                    $q->where('port_id', $supervisor->port_id);
                })
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
                    'id' => $supervisor->id,
                    'name' => $supervisor->name,
                    'email' => $supervisor->email,
                    'status' => $supervisor->status,
                    'created_at' => $supervisor->created_at->toIso8601String(),
                    'port' => $supervisor->port ? [
                        'id' => $supervisor->port->id,
                        'name' => $supervisor->port->name,
                        'status' => $supervisor->port->status,
                    ] : null,
                    'port_id' => $supervisor->port_id,
                    'port_name' => $supervisor->port ? $supervisor->port->name : 'Non Affecté',
                    'managed_agents_count' => $managedAgentsCount,
                    'port_stats' => $portStats,
                    'avg_processing_time' => round($avgProcessingTime ?? 0, 1),
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
                'total_supervisors' => User::where('role', 'supervisor')->count(),
                'active_supervisors' => User::where('role', 'supervisor')->where('status', 'active')->count(),
                'suspended_supervisors' => User::where('role', 'supervisor')->where('status', 'suspended')->count(),
                'ports_covered' => User::where('role', 'supervisor')
                    ->whereNotNull('port_id')
                    ->distinct('port_id')
                    ->count('port_id'),
            ];

            Log::info("Trouvé {$supervisors->count()} superviseurs");

            return response()->json([
                'success' => true,
                'message' => 'Superviseurs récupérés avec succès',
                'data' => $supervisors->values(),
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
            Log::error("Erreur récupération superviseurs: " . $e->getMessage());
            Log::error($e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des superviseurs.',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Crée un nouveau superviseur.
     */
    public function storeSupervisor(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|email|unique:users,email',
                'password' => 'required|string|min:8',
                'port_id' => 'required|exists:ports,id',
            ]);

            $supervisor = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'role' => 'supervisor',
                'port_id' => $validated['port_id'],
                'status' => 'active',
            ]);

            Log::info("Superviseur créé: {$supervisor->email} par admin " . Auth::id());

            return response()->json([
                'success' => true,
                'message' => 'Superviseur créé avec succès',
                'data' => $supervisor->load('port')
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error("Erreur création superviseur: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création du superviseur.',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Met à jour un superviseur.
     */
    public function updateSupervisor(Request $request, User $supervisor)
    {
        try {
            if ($supervisor->role !== 'supervisor') {
                return response()->json([
                    'success' => false,
                    'message' => 'Cet utilisateur n\'est pas un superviseur.'
                ], 403);
            }

            $validated = $request->validate([
                'name' => 'sometimes|string|max:255',
                'email' => 'sometimes|email|unique:users,email,' . $supervisor->id,
                'port_id' => 'sometimes|exists:ports,id',
                'status' => 'sometimes|in:active,inactive,suspended',
            ]);

            // Si on change de port, vérifier qu'il n'y a pas déjà un superviseur
            if (isset($validated['port_id']) && $validated['port_id'] != $supervisor->port_id) {
                $existingSupervisor = User::where('role', 'supervisor')
                    ->where('port_id', $validated['port_id'])
                    ->where('id', '!=', $supervisor->id)
                    ->first();

                if ($existingSupervisor) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Un superviseur est déjà affecté à ce port.',
                    ], 409);
                }
            }

            $supervisor->update($validated);

            Log::info("Superviseur {$supervisor->id} mis à jour par admin " . Auth::id());

            return response()->json([
                'success' => true,
                'message' => 'Superviseur mis à jour avec succès',
                'data' => $supervisor->fresh(['port'])
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error("Erreur mise à jour superviseur: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour.',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Supprime un superviseur.
     */
    public function destroySupervisor(User $supervisor)
    {
        try {
            if ($supervisor->role !== 'supervisor') {
                return response()->json([
                    'success' => false,
                    'message' => 'Cet utilisateur n\'est pas un superviseur.'
                ], 403);
            }

            // Vérifier s'il y a des agents associés
            $hasAgents = User::where('port_id', $supervisor->port_id)
                ->where('role', 'agent')
                ->exists();

            if ($hasAgents) {
                return response()->json([
                    'success' => false,
                    'message' => 'Impossible de supprimer ce superviseur car des agents sont encore rattachés à son port. Veuillez d\'abord réaffecter ou supprimer les agents.'
                ], 409);
            }

            $supervisorId = $supervisor->id;
            $supervisorName = $supervisor->name;
            $supervisor->delete();

            Log::info("Superviseur {$supervisorId} ({$supervisorName}) supprimé par admin " . Auth::id());

            return response()->json([
                'success' => true,
                'message' => 'Superviseur supprimé avec succès'
            ], 200);

        } catch (\Exception $e) {
            Log::error("Erreur suppression superviseur: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression.',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Bascule le statut d'un superviseur.
     */
    public function toggleSupervisorStatus(Request $request, User $supervisor)
    {
        try {
            if ($supervisor->role !== 'supervisor') {
                return response()->json([
                    'success' => false,
                    'message' => 'Cet utilisateur n\'est pas un superviseur.'
                ], 403);
            }

            $validated = $request->validate([
                'status' => ['required', Rule::in(['active', 'suspended'])],
            ]);

            $newStatus = $validated['status'];
            $supervisor->update(['status' => $newStatus]);

            Log::info("Statut superviseur {$supervisor->id} changé en {$newStatus} par admin " . Auth::id());

            $message = ($newStatus === 'suspended')
                ? 'Le compte superviseur a été suspendu.'
                : 'Le compte superviseur a été activé.';

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => [
                    'id' => $supervisor->id,
                    'status' => $newStatus
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error("Erreur toggle status superviseur: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du changement de statut.',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Réinitialise le mot de passe d'un superviseur à son email.
     */
    public function resetSupervisorPassword(User $supervisor)
    {
        try {
            if ($supervisor->role !== 'supervisor') {
                return response()->json([
                    'success' => false,
                    'message' => 'Cet utilisateur n\'est pas un superviseur.'
                ], 403);
            }

            // Le nouveau mot de passe est l'email du superviseur
            $newPassword = $supervisor->email;

            $supervisor->update([
                'password' => Hash::make($newPassword)
            ]);

            Log::info("Mot de passe superviseur {$supervisor->id} réinitialisé à son email par admin " . Auth::id());

            return response()->json([
                'success' => true,
                'message' => "Le mot de passe de {$supervisor->name} a été réinitialisé à son adresse email ({$supervisor->email}). Veuillez lui communiquer ce mot de passe temporaire et lui demander de le changer immédiatement.",
                'data' => [
                    'supervisor_name' => $supervisor->name,
                    'temporary_password' => $supervisor->email
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error("Erreur reset password superviseur: " . $e->getMessage());
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
