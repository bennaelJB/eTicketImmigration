<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Decision;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;
use Carbon\Carbon;

class SupervisorAgentController extends Controller
{
    /**
     * Vérifie si le superviseur est autorisé à agir sur l'agent.
     */
    protected function authorizeAgentAction(User $agent)
    {
        $user = Auth::user();

        if ($user->role === 'agent') {
            return false;
        }

        if ($user->role === 'admin') {
            return true;
        }

        if ($user->role === 'supervisor') {
            return $user->port_id === $agent->port_id;
        }

        return false;
    }

    /**
     * Crée un nouvel agent.
     */
    public function storeAgent(Request $request)
    {
        $user = Auth::user();

        if (!in_array($user->role, ['supervisor', 'admin'])) {
            return response()->json([
                'success' => false,
                'message' => 'Action non autorisée.'
            ], 403);
        }

        try {
            $validatedData = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'unique:users,email'],
                'password' => ['required', Password::min(8)->mixedCase()->numbers()->symbols()],
                'status' => ['required', Rule::in(['active', 'suspended', 'inactive'])],
            ]);

            $agentData = [
                'name' => $validatedData['name'],
                'email' => $validatedData['email'],
                'password' => Hash::make($validatedData['password']),
                'status' => $validatedData['status'],
                'role' => 'agent',
                'port_id' => $user->port_id,
            ];

            $agent = User::create($agentData);

            Log::info("Agent {$agent->id} créé par le superviseur {$user->id}");

            return response()->json([
                'success' => true,
                'message' => "Agent {$agent->name} créé avec succès.",
                'data' => [
                    'agent' => [
                        'id' => $agent->id,
                        'name' => $agent->name,
                        'email' => $agent->email,
                        'status' => $agent->status,
                        'role' => $agent->role,
                        'port_id' => $agent->port_id,
                    ],
                    'temporary_password' => $validatedData['password'],
                ]
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Données invalides.',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error("Erreur création agent: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création de l\'agent.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Met à jour un agent - CORRECTION PRINCIPALE ICI
     */
    public function updateAgent(Request $request, User $agent)
    {
        if (!$this->authorizeAgentAction($agent)) {
            return response()->json([
                'success' => false,
                'message' => 'Action non autorisée.'
            ], 403);
        }

        try {
            $rules = [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($agent->id)],
                'status' => ['required', 'string', Rule::in(['active', 'suspended', 'inactive'])],
                'password' => ['nullable', 'string', Password::min(8)->mixedCase()->numbers()->symbols()],
            ];

            $validatedData = $request->validate($rules);

            // ✅ CORRECTION : S'assurer que toutes les données sont des chaînes propres
            $updateData = [
                'name' => trim($validatedData['name']),
                'email' => trim($validatedData['email']),
                'status' => trim($validatedData['status']), // ✅ trim() pour éviter les espaces
            ];

            // Mise à jour du mot de passe uniquement s'il est fourni
            if (!empty($validatedData['password'])) {
                $updateData['password'] = Hash::make($validatedData['password']);
            }

            $agent->update($updateData);
            $agent->refresh(); // ✅ Recharger pour avoir les dernières données

            Log::info("Agent {$agent->id} mis à jour par " . Auth::id());

            return response()->json([
                'success' => true,
                'message' => "Agent {$agent->name} mis à jour avec succès.",
                'data' => [
                    'agent' => [
                        'id' => $agent->id,
                        'name' => $agent->name,
                        'email' => $agent->email,
                        'status' => $agent->status,
                        'role' => $agent->role,
                        'port_id' => $agent->port_id,
                    ]
                ]
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error("Erreur validation agent: " . json_encode($e->errors()));
            return response()->json([
                'success' => false,
                'message' => 'Données invalides.',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error("Erreur mise à jour agent: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Supprime un agent.
     */
    public function destroyAgent(User $agent)
    {
        if (!$this->authorizeAgentAction($agent)) {
            return response()->json([
                'success' => false,
                'message' => 'Action non autorisée.'
            ], 403);
        }

        try {
            $agentName = $agent->name;
            $agentId = $agent->id;

            $agent->delete();

            Log::info("Agent {$agentId} supprimé par " . Auth::id());

            return response()->json([
                'success' => true,
                'message' => "Agent {$agentName} supprimé avec succès."
            ], 200);

        } catch (\Exception $e) {
            Log::error("Erreur suppression agent: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Réinitialise le mot de passe d'un agent.
     */
    public function resetAgentPassword(User $agent)
    {
        if (!$this->authorizeAgentAction($agent)) {
            return response()->json([
                'success' => false,
                'message' => 'Action non autorisée.'
            ], 403);
        }

        try {
            $newPassword = Str::random(12) . rand(10, 99) . '!';

            $agent->update([
                'password' => Hash::make($newPassword),
            ]);

            Log::info("Mot de passe de l'agent {$agent->id} réinitialisé par " . Auth::id());

            return response()->json([
                'success' => true,
                'message' => "Mot de passe de {$agent->name} réinitialisé avec succès.",
                'data' => [
                    'temporary_password' => $newPassword,
                    'warning' => 'Ce mot de passe doit être communiqué de manière sécurisée à l\'agent.'
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error("Erreur réinitialisation mot de passe: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la réinitialisation.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Active/désactive le statut d'un agent.
     */
    public function toggleAgentStatus(User $agent)
    {
        if (!$this->authorizeAgentAction($agent)) {
            return response()->json([
                'success' => false,
                'message' => 'Action non autorisée.'
            ], 403);
        }

        try {
            $newStatus = $agent->status === 'active' ? 'inactive' : 'active';
            $agent->update(['status' => $newStatus]);
            $agent->refresh();

            Log::info("Statut de l'agent {$agent->id} changé en {$newStatus} par " . Auth::id());

            return response()->json([
                'success' => true,
                'message' => "Statut de {$agent->name} changé en {$newStatus}.",
                'data' => [
                    'agent_id' => $agent->id,
                    'new_status' => $newStatus
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error("Erreur changement statut: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du changement de statut.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * ✅ NOUVELLE ROUTE : Récupère les statistiques détaillées d'un agent
     */
    public function getAgentStats(Request $request, User $agent)
    {
        if (!$this->authorizeAgentAction($agent)) {
            return response()->json([
                'success' => false,
                'message' => 'Action non autorisée.'
            ], 403);
        }

        $timeFilter = $request->input('filter', 'month');
        $startDate = $this->getStartDate($timeFilter);

        $decisions = Decision::where('user_id', $agent->id)
            ->where('created_at', '>=', $startDate)
            ->with('ticket.passengerForm')
            ->get();

        $totalScans = $decisions->count();
        $accepted = $decisions->where('decision', 'accepted')->count();
        $rejected = $decisions->where('decision', 'rejected')->count();
        $arrivals = $decisions->where('action_type', 'arrival')->count();
        $departures = $decisions->where('action_type', 'departure')->count();

        // Temps moyen de traitement
        $avgProcessingTime = $decisions->filter(fn($d) => $d->ticket && $d->ticket->created_at)
            ->map(function($decision) {
                return Carbon::parse($decision->ticket->created_at)
                    ->diffInMinutes(Carbon::parse($decision->created_at));
            })
            ->avg();

        // Nationalités traitées
        $topNationalities = $decisions->map(fn($d) => $d->ticket?->passengerForm?->nationality ?? 'Inconnu')
            ->countBy()
            ->sortDesc()
            ->take(5)
            ->map(fn($count, $nat) => ['nationality' => $nat, 'count' => $count])
            ->values();

        // Dernières actions
        $recentActions = $decisions->sortByDesc('created_at')
            ->take(10)
            ->map(function($decision) {
                return [
                    'ticket_no' => $decision->ticket?->ticket_no,
                    'action_type' => $decision->action_type,
                    'decision' => $decision->decision,
                    'datetime' => $decision->created_at->toIso8601String(),
                    'comment' => $decision->comment,
                ];
            })
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'agent' => [
                    'id' => $agent->id,
                    'name' => $agent->name,
                    'email' => $agent->email,
                    'status' => $agent->status,
                ],
                'statistics' => [
                    'total_scans' => $totalScans,
                    'accepted' => $accepted,
                    'rejected' => $rejected,
                    'arrivals' => $arrivals,
                    'departures' => $departures,
                    'acceptance_rate' => $totalScans > 0 ? round(($accepted / $totalScans) * 100, 1) : 0,
                    'avg_processing_time' => round($avgProcessingTime ?? 0, 1),
                ],
                'top_nationalities' => $topNationalities,
                'recent_actions' => $recentActions,
                'period' => [
                    'filter' => $timeFilter,
                    'start' => $startDate->toIso8601String(),
                    'end' => Carbon::now()->toIso8601String(),
                ]
            ]
        ], 200);
    }

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
            default => Carbon::now()->startOfMonth(),
        };
    }
}
