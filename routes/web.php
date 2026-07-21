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
use App\Http\Controllers\Api\SecretariaEletronicaController;
use App\Http\Controllers\Painel\FormulariosController;
use App\Http\Controllers\Painel\SequenciaController;
use App\Http\Controllers\Painel\ContextoIaController;
use App\Http\Controllers\Painel\KanbanColunaConfigController;
use App\Http\Controllers\Painel\SpintaxVariavelController;
use App\Http\Controllers\Admin\EspecificacoesController;
use App\Http\Controllers\Admin\GestorKanbanConfigController;
use App\Http\Controllers\Painel\GestorKanbanRelatorioController;
use App\Http\Controllers\Painel\MotivoDesfechoController;
use App\Http\Controllers\Painel\IaUsageController;

// ── Formulário público (iframe) — sem auth ────────────────────────────────
Route::get('/f/{uuid}', function (string $uuid) {
    $formulario = \App\Models\Formulario::with('campos')
        ->where('uuid', $uuid)
        ->where('ativo', true)
        ->firstOrFail();

    return view('formularios.render', compact('formulario'));
});

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

    // Auditoria de contatos (telefones/nomes inválidos)
    Route::get('/contatos/auditoria', [ContatosController::class, 'auditoriaContatos'])
        ->name('contatos.auditoria')
        ->middleware('role:admin,dono,gerente,gestor,growth_manager');
    Route::post('/contatos/auditoria/{id}/resolver', [ContatosController::class, 'resolverAuditoria'])
        ->name('contatos.auditoria.resolver')
        ->middleware('role:admin,dono,gerente,gestor,growth_manager');
    Route::post('/contatos/auditoria/{id}/ignorar', [ContatosController::class, 'ignorarAuditoria'])
        ->name('contatos.auditoria.ignorar')
        ->middleware('role:admin,dono,gerente,gestor,growth_manager');
    Route::post('/contatos/auditoria/bulk-ignorar', [ContatosController::class, 'bulkIgnorarAuditoria'])
        ->name('contatos.auditoria.bulk-ignorar')
        ->middleware('role:admin,dono,gerente,gestor,growth_manager');
    Route::post('/contatos/auditoria/bulk-resolver', [ContatosController::class, 'bulkResolverAuditoria'])
        ->name('contatos.auditoria.bulk-resolver')
        ->middleware('role:admin,dono,gerente,gestor,growth_manager');

    // Marcadores (grupos/labels do Google Contacts)
    Route::get('/contatos/marcadores', [ContatosController::class, 'marcadores'])
        ->name('contatos.marcadores')
        ->middleware('role:admin,dono,gerente,gestor,growth_manager');

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

    // Configurações do Kanban — dono e admin
    Route::get('/kanban/config', fn () => view('kanban.config'))
        ->name('kanban.config')
        ->middleware('role:admin,dono');

    // Variáveis de mensagem — dono e admin
    Route::get('/kanban/variaveis', fn () => view('kanban.variaveis'))
        ->name('kanban.variaveis')
        ->middleware('role:admin,dono');

    // Relatórios semanais do Gestor do Kanban — dono e admin
    Route::get('/kanban/relatorios', [GestorKanbanRelatorioController::class, 'view'])
        ->name('kanban.relatorios')
        ->middleware('role:admin,dono');

    // Motivos de encerramento — dono e admin
    Route::get('/kanban/motivos-desfecho', [MotivoDesfechoController::class, 'view'])
        ->name('kanban.motivos-desfecho')
        ->middleware('role:admin,dono');

    // Documentação/estratégia — dono e admin
    Route::get('/kanban/documentacao/botoes', fn () => view('kanban.documentacao-botoes'))
        ->name('kanban.documentacao-botoes')
        ->middleware('role:admin,dono');

    // Especificações técnicas (specs de design registradas com o Claude) — dono e admin
    Route::get('/admin/especificacoes', [EspecificacoesController::class, 'index'])
        ->name('admin.especificacoes')
        ->middleware('role:admin,dono');
    Route::get('/admin/especificacoes/{arquivo}', [EspecificacoesController::class, 'show'])
        ->name('admin.especificacoes.show')
        ->middleware('role:admin,dono');

    // Gestor do Kanban — configuração do prompt global — só admin (nunca dono)
    Route::get('/admin/gestor-kanban', [GestorKanbanConfigController::class, 'view'])
        ->name('admin.gestor-kanban')
        ->middleware('role:admin');


    // Secretária Eletrônica — dono e admin
    Route::get('/secretaria-eletronica', fn () => view('secretaria-eletronica.index'))
        ->name('secretaria-eletronica')
        ->middleware('role:admin,dono');

    // Formulários — dono e admin
    Route::get('/formularios', [FormulariosController::class, 'view'])
        ->name('formularios')
        ->middleware('role:admin,dono');

    // Monitor de uso de IA — dono e admin
    Route::get('/ia-monitor', [IaUsageController::class, 'view'])
        ->name('ia-monitor')
        ->middleware('role:admin,dono');
});

// ── Painel — API JSON (protegida por sessão) ──────────────────────────────
Route::prefix('api/painel')->middleware(['auth', 'tenant'])->group(function () {

    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'dados']);

    // WhatsApp / Config
    Route::get('/whatsapp/status', [WhatsAppController::class, 'status']);
    Route::get('/whatsapp/qrcode', [WhatsAppController::class, 'qrcode']);
    Route::put('/whatsapp/retencao', [WhatsAppController::class, 'salvarRetencao'])
        ->middleware('role:admin,dono');

    // Kanban
    Route::middleware('role:admin,dono,diretor,gerente,gestor,vendedor,pos_venda')->group(function () {
        Route::get('/kanban/tickets', [KanbanController::class, 'index']);
        Route::get('/kanban/ticket/{ticket}', [KanbanController::class, 'show']);
        Route::get('/kanban/motivos-desfecho', [MotivoDesfechoController::class, 'index']);
        Route::get('/kanban/ticket/{ticket}/mensagens', [KanbanController::class, 'mensagens']);
        Route::post('/kanban/ticket/{ticket}/assumir', [KanbanController::class, 'assumir']);
        Route::post('/kanban/ticket/{ticket}/mensagem', [KanbanController::class, 'enviarMensagem']);
        Route::post('/kanban/ticket/{ticket}/encerrar', [KanbanController::class, 'encerrar']);
        Route::post('/kanban/ticket/{ticket}/liberar',         [KanbanController::class, 'liberar']);
        Route::post('/kanban/ticket/{ticket}/liberar-ia',      [KanbanController::class, 'liberarEAcionarIA']);
        Route::post('/kanban/ticket/{ticket}/visualizar',      [KanbanController::class, 'visualizar']);
        Route::post('/kanban/ticket/{ticket}/pendente',        [KanbanController::class, 'marcarPendente']);
        Route::post('/kanban/ticket/{ticket}/outros',          [KanbanController::class, 'moverParaOutros']);
        Route::post('/kanban/ticket/{ticket}/mover',           [KanbanController::class, 'mover']);
        Route::post('/kanban/ticket/{ticket}/retorno',         [KanbanController::class, 'agendarRetorno']);
        Route::post('/kanban/ticket/{ticket}/midia',           [KanbanController::class, 'enviarMidia']);
    });

    // Relatórios semanais do Gestor do Kanban — dono e admin apenas
    Route::middleware('role:admin,dono')->group(function () {
        Route::get('/kanban/relatorios', [GestorKanbanRelatorioController::class, 'index']);
        Route::get('/kanban/relatorios/{id}', [GestorKanbanRelatorioController::class, 'show']);
    });

    // Gerenciar motivos de encerramento — dono e admin apenas (ver a lista, todo mundo do Kanban pode)
    Route::middleware('role:admin,dono')->group(function () {
        Route::post('/kanban/motivos-desfecho', [MotivoDesfechoController::class, 'store']);
        Route::put('/kanban/motivos-desfecho/{id}', [MotivoDesfechoController::class, 'update']);
        Route::delete('/kanban/motivos-desfecho/{id}', [MotivoDesfechoController::class, 'destroy']);
    });

    // Monitor de uso de IA — dono e admin apenas
    Route::middleware('role:admin,dono')->group(function () {
        Route::get('/ia-monitor', [IaUsageController::class, 'index']);
    });

    // Contatos
    Route::middleware('role:admin,dono,diretor,gerente,gestor,vendedor,growth_manager')->group(function () {
        Route::post('/contatos/importar', [ContatosController::class, 'importar']);
        Route::post('/contatos/sincronizar-google', [ContatosController::class, 'sincronizarGoogle']);
        Route::post('/contatos/atualizar-google-sobrenome', [ContatosController::class, 'atualizarGoogleSobrenome']);
        Route::get('/contatos/stats', [ContatosController::class, 'stats']);
        // Marcadores: criar novo grupo no Google
        Route::post('/contatos/marcadores', [ContatosController::class, 'criarMarcador']);
    });

    // Contato: ficha completa
    Route::get('/contato/{contato}', [ContatosController::class, 'showContato'])
        ->middleware('role:admin,dono,diretor,gerente,gestor,vendedor,growth_manager');

    // Contato: histórico de atendimentos
    Route::get('/contato/{contato}/historico', [ContatosController::class, 'historicoContato'])
        ->middleware('role:admin,dono,diretor,gerente,gestor,vendedor,growth_manager');

    // Contato: cadastro manual
    Route::post('/contatos/criar', [ContatosController::class, 'criarContato'])
        ->middleware('role:admin,dono,diretor,gerente,gestor,vendedor');

    // Contato: editar (todos os campos)
    Route::patch('/contato/{contato}', [ContatosController::class, 'atualizarContato'])
        ->middleware('role:admin,dono,diretor,gerente,gestor,vendedor');

    // Contato: desativar vínculo com o tenant (nunca exclui o contato global)
    Route::post('/contato/{contato}/desativar', [ContatosController::class, 'desativarContato'])
        ->name('contato.desativar')
        ->middleware('role:admin,dono,gerente,gestor');

    // Contato: exclusão definitiva — apenas para lixo sem dados (auditoria confirmou)
    Route::delete('/contato/{contato}/excluir-definitivo', [ContatosController::class, 'excluirContatoDefinitivo'])
        ->name('contato.excluir-definitivo')
        ->middleware('role:admin,dono,gerente,gestor');

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

    // Secretária Eletrônica — dono e admin
    Route::middleware('role:admin,dono')->group(function () {
        Route::get('/secretaria-eletronica/dados',       [SecretariaEletronicaController::class, 'dadosPainel']);
        Route::post('/secretaria-eletronica/token',      [SecretariaEletronicaController::class, 'rotacionarToken']);
        Route::post('/secretaria-eletronica/mensagem',   [SecretariaEletronicaController::class, 'salvarMensagem']);
        Route::post('/secretaria-eletronica/toggle',     [SecretariaEletronicaController::class, 'toggleEnvio']);
        Route::put('/ia/sdr-ativo',                      [\App\Http\Controllers\Painel\WhatsAppController::class, 'toggleSdrAtivo']);
    });

    // Sequências — dono e admin
    Route::middleware('role:admin,dono')->group(function () {
        // Sequências (pai)
        Route::get('/sequencias',              [SequenciaController::class, 'index']);
        Route::post('/sequencias',             [SequenciaController::class, 'store']);
        Route::put('/sequencias/{id}',         [SequenciaController::class, 'update']);
        Route::delete('/sequencias/{id}',      [SequenciaController::class, 'destroy']);
        // Mensagens dentro de uma sequência
        Route::get('/sequencias/{seq}/mensagens',              [SequenciaController::class, 'mensagens']);
        Route::post('/sequencias/{seq}/mensagens',             [SequenciaController::class, 'storeMensagem']);
        Route::put('/sequencias/{seq}/mensagens/{id}',         [SequenciaController::class, 'updateMensagem']);
        Route::post('/sequencias/{seq}/mensagens/{id}',        [SequenciaController::class, 'updateMensagem']); // spoofing
        Route::delete('/sequencias/{seq}/mensagens/{id}',      [SequenciaController::class, 'destroyMensagem']);
        Route::post('/sequencias/{id}/sugerir-variaveis',     [SequenciaController::class, 'sugerirVariaveis']);
    });

    // Contexto da IA — dono e admin (mantido para retrocompatibilidade)
    Route::middleware('role:admin,dono')->group(function () {
        Route::get('/contexto-ia/dados',             [ContextoIaController::class, 'show']);
        Route::put('/contexto-ia/dados',             [ContextoIaController::class, 'update']);
        Route::post('/contexto-ia/gerar',            [ContextoIaController::class, 'gerar']);
        Route::post('/contexto-ia/tabela-precos',    [ContextoIaController::class, 'uploadTabela']);
        Route::delete('/contexto-ia/tabela-precos',  [ContextoIaController::class, 'removerTabela']);
    });

    // Configuração por coluna do Kanban (IA contexto) — dono e admin
    Route::middleware('role:admin,dono')->group(function () {
        Route::get('/kanban/coluna-config/{coluna}', [KanbanColunaConfigController::class, 'show']);
        Route::put('/kanban/coluna-config/{coluna}', [KanbanColunaConfigController::class, 'update']);
        // Variáveis de sorteio (spintax)
        Route::get('/kanban/variaveis',           [SpintaxVariavelController::class, 'index']);
        Route::get('/kanban/variaveis/listar',    [SpintaxVariavelController::class, 'listar']);
        Route::post('/kanban/variaveis',          [SpintaxVariavelController::class, 'store']);
        Route::put('/kanban/variaveis/{nome}',    [SpintaxVariavelController::class, 'update']);
        Route::delete('/kanban/variaveis/{nome}', [SpintaxVariavelController::class, 'destroy']);
    });

    // Variáveis: listagem rápida para card (roles amplos, só leitura)
    Route::get('/kanban/variaveis/listar', [SpintaxVariavelController::class, 'listar'])
        ->middleware('role:admin,dono,diretor,gerente,gestor,vendedor,pos_venda');

    // Formulários — dono e admin
    Route::middleware('role:admin,dono')->group(function () {
        Route::get('/formularios',           [FormulariosController::class, 'index']);
        Route::post('/formularios',          [FormulariosController::class, 'store']);
        Route::put('/formularios/{formulario}',    [FormulariosController::class, 'update']);
        Route::delete('/formularios/{formulario}', [FormulariosController::class, 'destroy']);
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

// ── API — Admin (Lead Certo, nunca franqueado) ────────────────────────────
Route::prefix('api/admin')->middleware(['auth', 'tenant', 'role:admin'])->group(function () {
    Route::get('/gestor-kanban/prompt', [GestorKanbanConfigController::class, 'show']);
    Route::put('/gestor-kanban/prompt', [GestorKanbanConfigController::class, 'update']);
});
