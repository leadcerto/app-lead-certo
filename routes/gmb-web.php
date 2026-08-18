<?php

/**
 * Rotas do módulo Google Meu Negócio (GMB) — Avaliações.
 * Incluído a partir de routes/web.php via require base_path('routes/gmb-web.php').
 */

use App\Http\Controllers\AgendamentoAvaliacaoController;
use App\Http\Controllers\AvaliadorController;
use App\Http\Controllers\AvaliadorDashboardController;
use App\Http\Controllers\ContatoAvaliacaoController;
use App\Http\Controllers\PerfilGmbController;
use App\Http\Controllers\TemplateAvaliacaoController;
use Illuminate\Support\Facades\Route;

// ══════════════════════════════════════════════════════════════════════════════
// ROTAS DO ADMIN — Google Meu Negócio
// Acesso: perfis com permissão 'avaliacoes_gmb' (admin, dono, diretor)
// ══════════════════════════════════════════════════════════════════════════════

Route::middleware(['auth', 'tenant', 'role:admin,dono,diretor'])->prefix('admin/gmb')->name('admin.')->group(function () {

    // ── Perfis GMB (CRUD) ─────────────────────────────────────────────────
    Route::resource('perfis-gmb', PerfilGmbController::class)
        ->except(['show']);

    // ── Lista de telefones (clientes reais pra ligar) por Perfil GMB ──────
    Route::get('perfis-gmb/{perfil}/contatos', [ContatoAvaliacaoController::class, 'index'])
        ->name('perfis-gmb.contatos.index');
    Route::post('perfis-gmb/{perfil}/contatos', [ContatoAvaliacaoController::class, 'store'])
        ->name('perfis-gmb.contatos.store');
    Route::delete('perfis-gmb/contatos/{contato}', [ContatoAvaliacaoController::class, 'destroy'])
        ->name('perfis-gmb.contatos.destroy');

    // ── Templates de Avaliação (CRUD) ─────────────────────────────────────
    Route::resource('templates-avaliacao', TemplateAvaliacaoController::class)
        ->except(['show']);

    // ── Categorias de Template ────────────────────────────────────────────
    Route::get('categorias', [TemplateAvaliacaoController::class, 'categorias'])
        ->name('templates-avaliacao.categorias');
    Route::post('categorias', [TemplateAvaliacaoController::class, 'storeCategoria'])
        ->name('templates-avaliacao.categorias.store');
    Route::put('categorias/{categoria}', [TemplateAvaliacaoController::class, 'updateCategoria'])
        ->name('templates-avaliacao.categorias.update');
    Route::delete('categorias/{categoria}', [TemplateAvaliacaoController::class, 'destroyCategoria'])
        ->name('templates-avaliacao.categorias.destroy');

    // ── Geração de rascunhos de template por IA ───────────────────────────
    Route::post('templates-avaliacao/gerar-ia', [TemplateAvaliacaoController::class, 'gerarComIa'])
        ->name('templates-avaliacao.gerar-ia');

    // ── Agendamentos de Avaliação ─────────────────────────────────────────
    Route::get('agendamentos', [AgendamentoAvaliacaoController::class, 'index'])
        ->name('agendamentos-avaliacao.index');
    Route::get('agendamentos/create', [AgendamentoAvaliacaoController::class, 'create'])
        ->name('agendamentos-avaliacao.create');
    Route::post('agendamentos', [AgendamentoAvaliacaoController::class, 'store'])
        ->name('agendamentos-avaliacao.store');

    // ── Agendamento em Lote (Matriz) ──────────────────────────────────────
    Route::get('agendamentos/lote', [AgendamentoAvaliacaoController::class, 'lote'])
        ->name('agendamentos-avaliacao.lote');
    Route::post('agendamentos/lote', [AgendamentoAvaliacaoController::class, 'storeLote'])
        ->name('agendamentos-avaliacao.lote.store');

    // ── Ações em agendamento individual ───────────────────────────────────
    Route::patch('agendamentos/{agendamento}/status', [AgendamentoAvaliacaoController::class, 'alterarStatus'])
        ->name('agendamentos-avaliacao.status');
    Route::patch('agendamentos/{agendamento}/avaliador', [AgendamentoAvaliacaoController::class, 'trocarAvaliador'])
        ->name('agendamentos-avaliacao.trocar-avaliador');
    Route::patch('agendamentos/{agendamento}/template', [AgendamentoAvaliacaoController::class, 'refazerTemplate'])
        ->name('agendamentos-avaliacao.refazer-template');

    // ── Alertar Avaliadores (e-mail manual) ───────────────────────────────
    Route::post('agendamentos/alertar', [AgendamentoAvaliacaoController::class, 'alertarAvaliadores'])
        ->name('agendamentos-avaliacao.alertar');

    // ── Cadastro de Avaliadores ─────────────────────────────────────────────
    Route::get('avaliadores', [AvaliadorController::class, 'index'])->name('avaliadores.index');
    Route::get('avaliadores/novo', [AvaliadorController::class, 'create'])->name('avaliadores.create');
    Route::post('avaliadores', [AvaliadorController::class, 'store'])->name('avaliadores.store');
    Route::get('avaliadores/{avaliador}/editar', [AvaliadorController::class, 'edit'])->name('avaliadores.edit');
    Route::put('avaliadores/{avaliador}', [AvaliadorController::class, 'update'])->name('avaliadores.update');
    Route::delete('avaliadores/{avaliador}', [AvaliadorController::class, 'destroy'])->name('avaliadores.destroy');
});

// ══════════════════════════════════════════════════════════════════════════════
// ROTAS DO AVALIADOR — Dashboard
// Acesso: perfis com permissão 'avaliador_dash' (admin, avaliador)
// ══════════════════════════════════════════════════════════════════════════════

Route::middleware(['auth', 'tenant', 'role:admin,avaliador'])->prefix('avaliador')->name('avaliador.')->group(function () {

    Route::get('dashboard', [AvaliadorDashboardController::class, 'index'])
        ->name('dashboard');

    Route::post('agendamentos/{agendamento}/concluir', [AvaliadorDashboardController::class, 'concluir'])
        ->name('concluir');

    Route::post('contatos/{contato}/concluir', [AvaliadorDashboardController::class, 'concluirContato'])
        ->name('contatos.concluir');
});
