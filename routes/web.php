<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Painel\DashboardController;
use App\Http\Controllers\Painel\KanbanController;
use App\Http\Controllers\Painel\WhatsAppController;
use App\Http\Controllers\Painel\ContatosController;
use App\Http\Controllers\Painel\AuditorController;
use App\Http\Controllers\Painel\IntegracoesController;
use App\Http\Controllers\Painel\PersonasController;
use App\Http\Controllers\Painel\CampanhasController;
use App\Http\Controllers\Painel\RespostaProntaController;
use App\Http\Controllers\Painel\NotaContatoController;
use App\Http\Controllers\Painel\AgendaImediataController;
use App\Http\Controllers\Painel\AgenteController;

// ── Convite público (sem auth) ─────────────────────────────────────────────
Route::get('/convite/{token}', [AgenteController::class, 'aceitarForm'])->name('convite.aceitar');
Route::post('/convite/{token}', [AgenteController::class, 'aceitarStore'])->name('convite.aceitar.store');

// ── Auth ──────────────────────────────────────────────────────────────────
Route::get('/login', fn () => view('auth.login'))->name('login')->middleware('guest');
Route::post('/login', [AuthController::class, 'loginWeb'])->middleware('guest');
Route::post('/logout', [AuthController::class, 'logoutWeb'])->name('logout')->middleware('auth');

// ── Painel base (todos os usuários autenticados com tenant) ───────────────
Route::middleware(['auth', 'tenant'])->group(function () {
    Route::get('/', fn () => redirect()->route('dashboard'));
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Kanban — vendedores, gerentes, diretor, dono, admin, pos_venda
    Route::get('/kanban', [KanbanController::class, 'view'])
        ->name('kanban')
        ->middleware('role:admin,dono,diretor,gerente,gestor,vendedor,pos_venda');

    // Contatos — todos menos auditor puro, revops e pos_venda
    Route::get('/contatos/importar', [ContatosController::class, 'view'])
        ->name('contatos.importar')
        ->middleware('role:admin,dono,diretor,gerente,gestor,vendedor,growth_manager');

    // Integrações — apenas dono, admin, growth_manager
    Route::get('/integracoes', [IntegracoesController::class, 'view'])
        ->name('integracoes')
        ->middleware('role:admin,dono,growth_manager');
    Route::get('/google/autorizar', [IntegracoesController::class, 'googleAutorizar'])
        ->name('google.autorizar')
        ->middleware('role:admin,dono,growth_manager');
    Route::get('/google/callback', [IntegracoesController::class, 'googleCallback'])
        ->name('google.callback')
        ->middleware('role:admin,dono,growth_manager');
    Route::post('/google/desconectar', [IntegracoesController::class, 'googleDesconectar'])
        ->name('google.desconectar')
        ->middleware('role:admin,dono,growth_manager');

    // Configurações — apenas dono e admin
    Route::get('/configuracoes', [WhatsAppController::class, 'view'])
        ->name('configuracoes')
        ->middleware('role:admin,dono');

    Route::get('/configuracoes/respostas-prontas', [RespostaProntaController::class, 'view'])
        ->name('configuracoes.respostas-prontas')
        ->middleware('role:admin,dono');

    Route::get('/configuracoes/agentes', [AgenteController::class, 'view'])
        ->name('configuracoes.agentes')
        ->middleware('role:admin,dono');

    // Auditor — auditor, diretor, dono, admin
    Route::get('/auditor', [AuditorController::class, 'view'])
        ->name('auditor')
        ->middleware('role:admin,dono,diretor,auditor');

    // Personas SDR — growth_manager, diretor, dono, admin
    Route::get('/personas', [PersonasController::class, 'view'])
        ->name('personas')
        ->middleware('role:admin,dono,diretor,growth_manager');

    // Campanhas de Mineração — growth_manager, diretor, dono, admin
    Route::get('/campanhas', [CampanhasController::class, 'view'])
        ->name('campanhas')
        ->middleware('role:admin,dono,diretor,growth_manager');
});

// ── Painel — API JSON (protegida por sessão) ──────────────────────────────
Route::prefix('api/painel')->middleware(['auth', 'tenant'])->group(function () {

    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'dados']);

    // WhatsApp / Config
    Route::get('/whatsapp/status', [WhatsAppController::class, 'status']);
    Route::get('/whatsapp/qrcode', [WhatsAppController::class, 'qrcode']);

    // Kanban
    Route::middleware('role:admin,dono,diretor,gerente,gestor,vendedor,pos_venda')->group(function () {
        Route::get('/kanban/tickets', [KanbanController::class, 'index']);
        Route::get('/kanban/ticket/{ticket}/mensagens', [KanbanController::class, 'mensagens']);
        Route::post('/kanban/ticket/{ticket}/assumir', [KanbanController::class, 'assumir']);
        Route::post('/kanban/ticket/{ticket}/mensagem', [KanbanController::class, 'enviarMensagem']);
        Route::post('/kanban/ticket/{ticket}/encerrar', [KanbanController::class, 'encerrar']);
        Route::post('/kanban/ticket/{ticket}/liberar',         [KanbanController::class, 'liberar']);
        Route::post('/kanban/ticket/{ticket}/pendente',        [KanbanController::class, 'marcarPendente']);
        Route::post('/kanban/ticket/{ticket}/resolver',        [KanbanController::class, 'resolver']);
    });

    // Contatos
    Route::middleware('role:admin,dono,diretor,gerente,gestor,vendedor,growth_manager')->group(function () {
        Route::post('/contatos/importar', [ContatosController::class, 'importar']);
        Route::post('/contatos/sincronizar-google', [ContatosController::class, 'sincronizarGoogle']);
        Route::post('/contatos/atualizar-google-sobrenome', [ContatosController::class, 'atualizarGoogleSobrenome']);
        Route::get('/contatos/stats', [ContatosController::class, 'stats']);
    });

    // Contato: editar nome (gerentes para cima)
    Route::patch('/contato/{contato}', [ContatosController::class, 'atualizarContato'])
        ->middleware('role:admin,dono,diretor,gerente,gestor,vendedor');

    // Auditor — apenas auditor, diretor, dono, admin
    Route::middleware('role:admin,dono,diretor,auditor')->group(function () {
        Route::get('/auditor/stats',                                 [AuditorController::class, 'stats']);
        Route::get('/auditor/pendentes',                             [AuditorController::class, 'pendentes']);
        Route::post('/auditor/pendente/{vinculo}/aprovar',           [AuditorController::class, 'aprovarNome']);
        Route::post('/auditor/pendente/{vinculo}/rejeitar',          [AuditorController::class, 'rejeitarNome']);
        Route::post('/auditor/contato/{contato}/sinalizar',          [AuditorController::class, 'sinalizar']);
        Route::post('/auditor/contato/{contato}/aprovar-cadastro',   [AuditorController::class, 'aprovarCadastro']);
        Route::post('/auditor/contato/{contato}/inativar',           [AuditorController::class, 'inativar']);
        Route::get('/auditor/contatos',                              [AuditorController::class, 'contatos']);
        Route::get('/auditor/logs',                                  [AuditorController::class, 'logs']);
        Route::get('/auditor/conflitos',                             [AuditorController::class, 'conflitos']);
        Route::post('/auditor/conflito/{pendente}/fundir',           [AuditorController::class, 'fundirConflito']);
        Route::post('/auditor/conflito/{pendente}/criar-novo',       [AuditorController::class, 'criarNovoConflito']);
        Route::post('/auditor/conflito/{pendente}/descartar',        [AuditorController::class, 'descartarConflito']);
    });

    // Campanhas de Mineração — growth_manager, diretor, dono, admin
    Route::middleware('role:admin,dono,diretor,growth_manager')->group(function () {
        Route::get('/campanhas',                                        [CampanhasController::class, 'index']);
        Route::post('/campanhas',                                       [CampanhasController::class, 'store']);
        Route::put('/campanhas/{campanha}',                             [CampanhasController::class, 'update']);
        Route::get('/campanhas/{campanha}/agentes',                     [CampanhasController::class, 'agentes']);
        Route::post('/campanhas/{campanha}/agentes',                    [CampanhasController::class, 'criarAgente']);
        Route::post('/campanhas/agentes/{agente}/ativar',               [CampanhasController::class, 'ativarAgente']);
        Route::post('/campanhas/agentes/{agente}/suspender',            [CampanhasController::class, 'suspenderAgente']);
        Route::post('/campanhas/agentes/{agente}/regenerar-chave',      [CampanhasController::class, 'regenerarChave']);
    });

    // Agenda imediata (sino)
    Route::get('/agenda-imediata', [AgendaImediataController::class, 'index'])
        ->middleware('role:admin,dono,diretor,gerente,gestor,vendedor,pos_venda');

    // Agentes — apenas dono e admin
    Route::middleware('role:admin,dono')->group(function () {
        Route::get('/agentes',                  [AgenteController::class, 'index']);
        Route::post('/agentes/convidar',        [AgenteController::class, 'invite']);
        Route::put('/agentes/{id}',             [AgenteController::class, 'update']);
        Route::delete('/agentes/{id}',          [AgenteController::class, 'destroy']);
        Route::delete('/agentes/convite/{id}',  [AgenteController::class, 'destroyConvite']);
    });

    // Notas por Contato — todos os perfis de atendimento
    Route::middleware('role:admin,dono,diretor,gerente,gestor,vendedor,pos_venda')->group(function () {
        Route::get('/contato/{contatoId}/notas',      [NotaContatoController::class, 'index']);
        Route::post('/contato/{contatoId}/notas',     [NotaContatoController::class, 'store']);
        Route::delete('/notas/{id}',                  [NotaContatoController::class, 'destroy']);
    });

    // Respostas Prontas — admin, dono, vendedor (buscar) · admin, dono (CRUD)
    Route::get('/respostas-prontas/buscar', [RespostaProntaController::class, 'buscar'])
        ->middleware('role:admin,dono,diretor,gerente,gestor,vendedor,pos_venda');
    Route::middleware('role:admin,dono')->group(function () {
        Route::get('/respostas-prontas',         [RespostaProntaController::class, 'index']);
        Route::post('/respostas-prontas',        [RespostaProntaController::class, 'store']);
        Route::put('/respostas-prontas/{id}',    [RespostaProntaController::class, 'update']);
        Route::delete('/respostas-prontas/{id}', [RespostaProntaController::class, 'destroy']);
    });

    // Personas SDR — growth_manager, diretor, dono, admin
    Route::middleware('role:admin,dono,diretor,growth_manager')->group(function () {
        Route::get('/personas',                         [PersonasController::class, 'index']);
        Route::post('/personas',                        [PersonasController::class, 'store']);
        Route::put('/personas/{persona}',               [PersonasController::class, 'update']);
        Route::get('/personas/qa/pendentes',            [PersonasController::class, 'qasPendentes']);
        Route::post('/personas/qa/{auditoria}/revisar', [PersonasController::class, 'qaRevisar']);
    });
});
