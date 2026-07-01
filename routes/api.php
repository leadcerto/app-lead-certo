<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Internal\ContatoController;
use App\Http\Controllers\Internal\MineradorController;
use App\Http\Controllers\Internal\TicketController;
use App\Http\Controllers\Internal\MensagemController;
use App\Http\Controllers\Internal\MidiaController;
use App\Http\Controllers\Painel\KanbanController;
use App\Http\Controllers\Painel\DashboardController;
use App\Http\Controllers\Painel\WhatsAppController;

// Auth
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');

// Interno — chamado pelo n8n (protegido por SERVICE_KEY)
Route::prefix('internal')->middleware('service.key')->group(function () {
    Route::post('/contato', [ContatoController::class, 'upsert']);
    Route::post('/ticket', [TicketController::class, 'abrirOuRecuperar']);
    Route::patch('/ticket/{ticket}', [TicketController::class, 'atualizar']);
    Route::post('/ticket/{ticket}/responder-ia', [TicketController::class, 'responderIa']);
    Route::get('/tickets/followup', [TicketController::class, 'paraFollowup']);
    Route::post('/mensagem', [MensagemController::class, 'salvar']);
    Route::post('/midia', [MidiaController::class, 'salvar']);
});

// Mineradores — autenticação M2M via X-Minerador-Key
Route::prefix('minerador')->middleware('minerador')->group(function () {
    Route::post('/contato',   [MineradorController::class, 'gravarContato']);
    Route::get('/campanha',   [MineradorController::class, 'consultarCampanha']);
});

// Painel — rotas movidas para routes/web.php (auth via sessão)
