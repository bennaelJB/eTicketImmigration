<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\TicketController;

Route::middleware('api')->group(function () {

    Route::post('/tickets', [TicketController::class, 'store'])
    ->name('tickets.store');

    Route::get('/tickets/show', [TicketController::class, 'showTicket'])
    ->name('tickets-show');

    Route::get('/tickets/{ticket_no}/download', [TicketController::class, 'download']);

    Route::get('/tickets/{ticket_no}/download-pdf', [TicketController::class, 'downloadPdf']);

    Route::post('/tickets/{ticket}/send-email', [TicketController::class, 'sendEmail']);

    Route::get('/ping', function () {
        return response()->json([
            'status' => 'success',
            'message' => 'Immigration API is working',
            'timestamp' => now()->toDateTimeString()
        ]);
    });
});
