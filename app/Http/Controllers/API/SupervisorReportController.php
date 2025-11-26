<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Models\User;
use App\Models\Decision;
use App\Models\Ticket;
use App\Models\Port;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use PDF; // si tu utilises dompdf ou barryvdh/laravel-dompdf

class SupervisorReportController extends Controller
{
    /**
     * Génère un rapport statistique global (PDF ou JSON) pour le port du superviseur.
     */
    public function generate(Request $request)
    {
        $user = Auth::user();
        $portId = $user->port_id;

        $timeFilter = $request->input('filter', 'month');
        $format = $request->input('format', 'json'); // "json" ou "pdf"

        $startDate = $this->getStartDate($timeFilter);
        $endDate = Carbon::now();

        Log::info("Génération du rapport pour le port #{$portId} entre {$startDate} et {$endDate}");

        // === Données principales ===
        $decisions = Decision::whereHas('user', fn($q) => $q->where('port_id', $portId))
            ->whereBetween('created_at', [$startDate, $endDate])
            ->with(['ticket.passengerForm', 'user'])
            ->get();

        $totalScans = $decisions->count();
        $accepted = $decisions->where('decision', 'accepted')->count();
        $rejected = $decisions->where('decision', 'rejected')->count();
        $acceptanceRate = $totalScans > 0 ? round(($accepted / $totalScans) * 100, 1) : 0;

        // === Répartition par type (arrival/departure)
        $arrival = $decisions->where('action_type', 'arrival')->count();
        $departure = $decisions->where('action_type', 'departure')->count();

        // === Top agents ===
        $agentStats = User::where('port_id', $portId)
            ->where('role', 'agent')
            ->withCount([
                'decisions as total_scans' => fn($q) => $q->where('created_at', '>=', $startDate),
                'decisions as accepted' => fn($q) => $q->where('decision', 'accepted')->where('created_at', '>=', $startDate),
                'decisions as rejected' => fn($q) => $q->where('decision', 'rejected')->where('created_at', '>=', $startDate),
            ])
            ->get()
            ->map(function($a) {
                $a->acceptance_rate = $a->total_scans > 0 ? round(($a->accepted / $a->total_scans) * 100, 1) : 0;
                return $a;
            })
            ->sortByDesc('total_scans')
            ->take(10);

        // === Top nationalités ===
        $topNationalities = $decisions->map(fn($d) => $d->ticket?->passengerForm?->nationality ?? 'Inconnu')
            ->countBy()
            ->sortDesc()
            ->take(10)
            ->map(fn($count, $nation) => ['nationality' => $nation, 'count' => $count])
            ->values();

        // === Activité journalière
        $dailyActivity = $decisions->groupBy(fn($d) => $d->created_at->format('Y-m-d'))
            ->map(fn($group, $date) => [
                'date' => $date,
                'total' => $group->count(),
                'accepted' => $group->where('decision', 'accepted')->count(),
                'rejected' => $group->where('decision', 'rejected')->count(),
            ])
            ->values();

        // === Temps de traitement moyen
        $avgProcessingTime = $decisions->filter(fn($d) => $d->ticket && $d->ticket->created_at)
            ->map(fn($d) => Carbon::parse($d->ticket->created_at)->diffInMinutes($d->created_at))
            ->avg();

        // === Résumé final
        $report = [
            'port' => [
                'id' => $portId,
                'name' => Port::find($portId)?->name ?? 'Port inconnu',
            ],
            'period' => [
                'start' => $startDate->toIso8601String(),
                'end' => $endDate->toIso8601String(),
                'filter' => $timeFilter,
            ],
            'overview' => [
                'total_scans' => $totalScans,
                'accepted' => $accepted,
                'rejected' => $rejected,
                'acceptance_rate' => $acceptanceRate,
                'arrivals' => $arrival,
                'departures' => $departure,
                'avg_processing_time' => round($avgProcessingTime ?? 0, 1),
            ],
            'top_agents' => $agentStats->map(fn($a) => [
                'id' => $a->id,
                'name' => $a->name,
                'total_scans' => $a->total_scans,
                'accepted' => $a->accepted,
                'rejected' => $a->rejected,
                'acceptance_rate' => $a->acceptance_rate,
            ])->values(),
            'top_nationalities' => $topNationalities,
            'daily_activity' => $dailyActivity,
        ];

        if ($format === 'pdf') {
            $pdf = PDF::loadView('reports.supervisor', ['report' => $report]);
            return $pdf->download("rapport_superviseur_{$portId}_{$timeFilter}.pdf");
        }

        return response()->json([
            'success' => true,
            'message' => 'Rapport généré avec succès',
            'data' => $report,
        ], 200);
    }

    /**
     * Génère un rapport détaillé pour un agent spécifique.
     */
    public function agentReport(Request $request, $agentId)
    {
        $user = Auth::user();
        $portId = $user->port_id;
        $timeFilter = $request->input('filter', 'month');
        $startDate = $this->getStartDate($timeFilter);

        $agent = User::where('port_id', $portId)
            ->where('role', 'agent')
            ->findOrFail($agentId);

        $decisions = Decision::where('user_id', $agent->id)
            ->where('created_at', '>=', $startDate)
            ->with('ticket.passengerForm')
            ->get();

        $total = $decisions->count();
        $accepted = $decisions->where('decision', 'accepted')->count();
        $rejected = $decisions->where('decision', 'rejected')->count();
        $acceptanceRate = $total > 0 ? round(($accepted / $total) * 100, 1) : 0;

        $avgTime = $decisions->filter(fn($d) => $d->ticket && $d->ticket->created_at)
            ->map(fn($d) => Carbon::parse($d->ticket->created_at)->diffInMinutes($d->created_at))
            ->avg();

        $history = $decisions->map(fn($d) => [
            'ticket_no' => $d->ticket?->ticket_no,
            'nationality' => $d->ticket?->passengerForm?->nationality,
            'decision' => $d->decision,
            'action_type' => $d->action_type,
            'date' => $d->created_at->toIso8601String(),
            'comment' => $d->comment,
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'agent' => [
                    'id' => $agent->id,
                    'name' => $agent->name,
                    'email' => $agent->email,
                ],
                'statistics' => [
                    'total' => $total,
                    'accepted' => $accepted,
                    'rejected' => $rejected,
                    'acceptance_rate' => $acceptanceRate,
                    'avg_processing_time' => round($avgTime ?? 0, 1),
                ],
                'history' => $history,
            ]
        ]);
    }

    /**
     * Génère un rapport CSV exportable (agents, décisions, ou tickets).
     */
    public function exportCSV(Request $request)
    {
        $type = $request->input('type', 'decisions');
        $user = Auth::user();
        $portId = $user->port_id;
        $startDate = $this->getStartDate($request->input('filter', 'month'));

        $filename = "rapport_{$type}_{$portId}_" . now()->format('Ymd_His') . ".csv";
        $handle = fopen('php://temp', 'w+');

        if ($type === 'agents') {
            $agents = User::where('port_id', $portId)
                ->where('role', 'agent')
                ->get(['id', 'name', 'email', 'status']);
            fputcsv($handle, ['ID', 'Nom', 'Email', 'Statut']);
            foreach ($agents as $a) {
                fputcsv($handle, [$a->id, $a->name, $a->email, $a->status]);
            }
        } elseif ($type === 'decisions') {
            $decisions = Decision::whereHas('user', fn($q) => $q->where('port_id', $portId))
                ->where('created_at', '>=', $startDate)
                ->with(['ticket.passengerForm', 'user'])
                ->get();

            fputcsv($handle, ['Ticket', 'Voyageur', 'Nationalité', 'Agent', 'Type', 'Décision', 'Date']);
            foreach ($decisions as $d) {
                fputcsv($handle, [
                    $d->ticket?->ticket_no,
                    $d->ticket?->passengerForm
                        ? "{$d->ticket->passengerForm->first_name} {$d->ticket->passengerForm->last_name}"
                        : 'Inconnu',
                    $d->ticket?->passengerForm?->nationality ?? '',
                    $d->user?->name,
                    ucfirst($d->action_type),
                    ucfirst($d->decision),
                    $d->created_at->format('Y-m-d H:i'),
                ]);
            }
        } else {
            return response()->json(['error' => 'Type de rapport invalide'], 400);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return response($csv)
            ->header('Content-Type', 'text/csv')
            ->header('Content-Disposition', "attachment; filename={$filename}");
    }

    // === MÉTHODES PRIVÉES ===

    private function getStartDate($filter)
    {
        return match($filter) {
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
