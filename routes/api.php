<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\PortController;
use App\Http\Controllers\API\AgentController;
use App\Http\Controllers\API\SupervisorController;
use App\Http\Controllers\API\SupervisorAgentController;
use App\Http\Controllers\API\SupervisorTicketController;
use App\Http\Controllers\API\SupervisorReportController;
use App\Http\Controllers\API\AdminController;
use App\Http\Controllers\API\AdminSupervisorController;
use App\Http\Controllers\API\AdminAgentController;
use App\Http\Controllers\API\AdminPortController;
use App\Http\Controllers\API\AdminTicketController;
use App\Http\Controllers\API\AdminReportController;

/*
|--------------------------------------------------------------------------
| API Routes - Immigration & Customs Services
|--------------------------------------------------------------------------
*/

// =============================
// ROUTES PUBLIQUES
// =============================

Route::get('/ping', function () {
    return response()->json([
        'status' => 'success',
        'service' => env('APP_NAME', 'eticket-service'),
        'message' => env('APP_NAME') . ' API is working',
        'timestamp' => now()->toDateTimeString()
    ]);
});

Route::get('/healthcheck', function () {
    return response()->json([
        'status' => 'ok',
        'service' => env('APP_NAME', 'eticket-service'),
        'timestamp' => now()->toDateTimeString(),
    ], 200);
});

// =============================
// AUTHENTIFICATION
// =============================
Route::group([
    'prefix' => 'auth',
    'middleware' => 'api'
], function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:api');
    Route::post('/refresh', [AuthController::class, 'refresh'])->middleware('auth:api');
    Route::get('/profile', [AuthController::class, 'profile'])->middleware('auth:api');
    Route::put('/profile', [AuthController::class, 'updateProfile'])->middleware('auth:api');
    Route::get('/dashboard-redirect', [AuthController::class, 'dashboardRedirect'])->middleware('auth:api');
});

// =============================
// TICKETS (Accès public pour création)
// =============================
Route::middleware('api')->group(function () {
    Route::get('/ports', [PortController::class, 'index']);
    Route::get('/tickets/last-mixte', [TicketController::class, 'getLastMixteTicket']);

    Route::post('/tickets', [TicketController::class, 'store'])->name('tickets.store');
    Route::get('/tickets/show', [TicketController::class, 'showTicket'])->name('tickets.show');
    //Route::get('/tickets/{ticket_no}', [TicketController::class, 'getTicket']);
    Route::get('/tickets/{ticket_no}/download', [TicketController::class, 'download']);
    Route::get('/tickets/{ticket_no}/download-pdf', [TicketController::class, 'downloadPdf']);
    Route::post('/tickets/{ticket}/send-email', [TicketController::class, 'sendEmail']);
});

// =============================
// ROUTES AGENT
// =============================
Route::group([
    'prefix' => 'agent',
    'middleware' => ['api', 'auth:api', 'role:agent,supervisor,admin']
], function () {
    Route::get('/dashboard', [AgentController::class, 'dashboard']);
    Route::get('/history', [AgentController::class, 'history']);
    Route::post('/scan/{ticketNo}', [AgentController::class, 'scan']);
    Route::put('/ticket/update', [AgentController::class, 'update']);
    Route::post('/ticket/decide', [AgentController::class, 'decide']);
});

// =============================
// ROUTES SUPERVISOR
// =============================
Route::group([
    'prefix' => 'supervisor',
    'middleware' => ['api', 'auth:api', 'role:supervisor,admin']
], function () {
    // Dashboard & statistiques
    Route::get('/dashboard', [SupervisorController::class, 'dashboard']);
    Route::get('/history', [SupervisorController::class, 'history']);
    Route::get('/agents', [SupervisorController::class, 'agents']);

    // ✅ ROUTE : Gestion des tickets
    Route::get('/tickets', [SupervisorTicketController::class, 'index']);
    Route::get('/tickets/{ticket}', [SupervisorTicketController::class, 'show']);
    Route::put('/tickets/{ticket}', [SupervisorTicketController::class, 'update']);
    Route::get('/tickets/export', [SupervisorTicketController::class, 'export']);

    // Gestion des agents
    Route::post('/agents', [SupervisorAgentController::class, 'storeAgent']);
    Route::put('/agents/{agent}', [SupervisorAgentController::class, 'updateAgent']);
    Route::delete('/agents/{agent}', [SupervisorAgentController::class, 'destroyAgent']);
    Route::post('/agents/{agent}/reset-password', [SupervisorAgentController::class, 'resetAgentPassword']);
    Route::post('/agents/{agent}/toggle-status', [SupervisorAgentController::class, 'toggleAgentStatus']);
    Route::get('/agents/{agent}/stats', [SupervisorAgentController::class, 'getAgentStats']);

    // Gestion des Rapports
    Route::get('/reports', [SupervisorReportController::class, 'generate']);
    Route::get('/reports/agent/{id}', [SupervisorReportController::class, 'agentReport']);
    Route::get('/reports/export', [SupervisorReportController::class, 'exportCSV']);
});

// =============================
// ROUTES ADMIN
// =============================
Route::group([
    'prefix' => 'admin',
    'middleware' => ['api', 'auth:api', 'role:admin']
], function () {
    Route::get('/dashboard', [AdminController::class, 'dashboard']);
    Route::get('/reports', [AdminReportController::class, 'reports']);

    // Supervisors
    Route::get('/supervisors', [AdminSupervisorController::class, 'supervisors']);
    Route::post('/supervisors', [AdminSupervisorController::class, 'storeSupervisor']);
    Route::put('/supervisors/{supervisor}', [AdminSupervisorController::class, 'updateSupervisor']);
    Route::delete('/supervisors/{supervisor}', [AdminSupervisorController::class, 'destroySupervisor']);
    Route::post('/supervisors/{supervisor}/toggle-status', [AdminSupervisorController::class, 'toggleSupervisorStatus']);
    Route::post('/supervisors/{supervisor}/reset-password', [AdminSupervisorController::class, 'resetSupervisorPassword']);

    // Agents
    Route::get('/agents', [AdminAgentController::class, 'agents']);
    Route::post('/agents', [AdminAgentController::class, 'storeAgent']);
    Route::put('/agents/{agent}', [AdminAgentController::class, 'updateAgent']);
    Route::delete('/agents/{agent}', [AdminAgentController::class, 'destroyAgent']);
    Route::post('/agents/{agent}/toggle-status', [AdminAgentController::class, 'toggleAgentStatus']);
    Route::post('/agents/{agent}/reset-password', [AdminAgentController::class, 'resetAgentPassword']);

    // Ports
    Route::get('/ports', [AdminPortController::class, 'ports']);
    Route::post('/ports', [AdminPortController::class, 'storePort']);
    Route::put('/ports/{port}', [AdminPortController::class, 'updatePort']);
    Route::delete('/ports/{port}', [AdminPortController::class, 'destroyPort']);
    Route::post('/ports/{port}/toggle-status', [AdminPortController::class, 'togglePortStatus']);

    // Rapports
    Route::get('/travelers-reports', [AdminController::class, 'travelersReports']);
    Route::get('/statistics', [AdminController::class, 'statistics']);

    // Tickets
    Route::get('/tickets', [AdminTicketController::class, 'tickets']);
    Route::get('/tickets/{ticket}', [AdminTicketController::class, 'getTicketDetails']);
    Route::put('/tickets/{ticket}', [AdminTicketController::class, 'updateTicket']);
    Route::delete('/tickets/{ticket}', [AdminTicketController::class, 'deleteTicket']);
});

// =============================
// ROUTES DE REPORTING
// =============================
Route::group([
    'prefix' => 'reports',
    'middleware' => ['api', 'auth:api', 'role:supervisor,admin']
], function () {
    Route::get('/daily', [ReportController::class, 'daily']);
    Route::get('/weekly', [ReportController::class, 'weekly']);
    Route::get('/monthly', [ReportController::class, 'monthly']);
    Route::get('/custom', [ReportController::class, 'custom']);
    Route::get('/export', [ReportController::class, 'export']);
    Route::get('/by-agent', [ReportController::class, 'byAgent']);
    Route::get('/by-port', [ReportController::class, 'byPort']);
});

// =============================
// ROUTES DE RECHERCHE
// =============================
Route::group([
    'prefix' => 'search',
    'middleware' => ['api', 'auth:api', 'role:agent,supervisor,admin']
], function () {
    Route::get('/tickets', [TicketController::class, 'search']);
    Route::get('/travelers', [TicketController::class, 'searchTravelers']);
});
