<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Ping pour tester la disponibilité du service
Route::get('/ping', function () {
    return response()->json([
        'service' => 'immigration',
        'status' => 'ok',
    ]);
});

// Exemple d'autres routes API Customs
Route::middleware('api')->group(function () {
    Route::get('/tickets', function () {
        return response()->json([
            'tickets' => [
                ['id' => 101, 'ref' => 'CUST-001'],
                ['id' => 102, 'ref' => 'CUST-002'],
            ]
        ]);
    });
    
    Route::get('/ping', function () {
    return response()->json([
        'status' => 'success',
        'message' => 'Immigration API is working',
        'timestamp' => now()->toDateTimeString()
    ]);
});
});
