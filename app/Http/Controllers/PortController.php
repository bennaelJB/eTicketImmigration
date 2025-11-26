<?php

namespace App\Http\Controllers;

use App\Models\Port;
use Illuminate\Http\JsonResponse;

class PortController extends Controller
{
    /**
     * GET /api/ports
     * Retourner tous les ports actifs
     */
    public function index(): JsonResponse
    {
        $ports = Port::orderBy('code')
            ->where('status', 'active')
            ->get(['id', 'code', 'name', 'type']);

        return response()->json([
            'data' => $ports
        ]);
    }
}
