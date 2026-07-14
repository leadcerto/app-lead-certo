@extends('layouts.app')

@section('title', 'Auditoria de Contatos — Lead Certo')

@section('content')
<div class="p-6 max-w-7xl mx-auto">

    {{-- Cabeçalho --}}
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-gray-800">Auditoria de Contatos</h1>
            <p class="text-sm text-gray-500 mt-0.5">Telefones inválidos e nomes que precisam de correção manual</p>
        </div>
    </div>

    {{-- Contadores por status --}}
    <div class="grid grid-cols-3 gap-4 mb-6">
        <a href="?filtro=pendente" class="bg-white rounded-xl border p-4 flex items-center gap-3 {{ $filtro === 'pendente' ? 'border-red-400 ring-1 ring-red-300' : 'border-gray-200 hover:border-gray-300' }}">
            <div class="w-10 h-10 rounded-full bg-red-100 flex items-center justify-center">
                <svg class="w-5 h-5 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                </svg>
            </div>
            <div>
                <div class="text-2xl font-bold text-gray-800" id="count-pendente">{{ $contagens['pendente'] ?? 0 }}</div>
                <div class="text-xs text-gray-500">Pendentes</div>
            </div>
        </a>
        <a href="?filtro=resolvido" class="bg-white rounded-xl border p-4 flex items-center gap-3 {{ $filtro === 'resolvido' ? 'border-green-400 ring-1 ring-green-300' : 'border-gray-200 hover:border-gray-300' }}">
            <div class="w-10 h-10 rounded-full bg-green-100 flex items-center justify-center">
                <svg class="w-5 h-5 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
            </div>
            <div>
                <div class="text-2xl font-bold text-gray-800">{{ $contagens['resolvido'] ?? 0 }}</div>
                <div class="text-xs text-gray-500">Resolvidos</div>
            </div>
        </a>
        <a href="?filtro=ignorado" class="bg-white rounded-xl border p-4 flex items-center gap-3 {{ $filtro === 'ignorado' ? 'border-gray-400 ring-1 ring-gray-300' : 'border-gray-200 hover:border-gray-300' }}">
            <div class="w-10 h-10 rounded-full bg-gray-100 flex items-center justify-center">
                <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>
                </svg>
            </div>
            <div>
                <div class="text-2xl font-bold text-gray-800">{{ $contagens['ignorado'] ?? 0 }}</div>
                <div class="text-xs text-gray-500">Ignorados</div>
            </div>
        </a>
    </div>

    {{-- Breakdown por tipo de erro (apenas quando filtrando pendentes) --}}
    @if($filtro === 'pendente' && $breakdown->isNotEmpty())
    <div class="mb-6">
        <h2 class="text-sm font-semibold text-gray-600 uppercase tracking-wide mb-3">Erros por categoria</h2>
        <div class="space-y-2">
            @foreach($breakdown as $grupo)
            <div class="bg-white rounded-xl border border-gray-200 px-4 py-3 flex items-center justify-between gap-4"
                 id="breakdown-row-{{ $loop->index }}">
                <div class="flex items-center gap-3 min-w-0">
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium
                        {{ $grupo->tipo === 'telefone_invalido' ? 'bg-orange-100 text-orange-700' : 'bg-yellow-100 text-yellow-700' }} shrink-0">
                        {{ $grupo->tipo === 'telefone_invalido' ? '📞 Telefone' : '✏️ Nome' }}
                    </span>
                    <div class="min-w-0">
                        <span class="text-sm text-gray-700 truncate block">{{ $grupo->observacao ?: '—' }}</span>
                        @if($grupo->total === 1 && isset($grupo->contato))
                            <span class="text-xs text-gray-400">
                                {{ $grupo->contato->nome ?? '—' }}
                                @if($grupo->contato->telefone) · <span class="font-mono">{{ $grupo->contato->telefone }}</span>@endif
                            </span>
                        @endif
                    </div>
                </div>
                <div class="flex items-center gap-2 shrink-0">
                    <span class="text-sm font-semibold text-gray-500 mr-1">{{ number_format($grupo->total) }}</span>

                    @if($grupo->total === 1 && isset($grupo->contato))
                        {{-- Grupo com 1 contato: botão Editar direto --}}
                        <button onclick="abrirEdicaoContato(
                                {{ $grupo->auditoria_id }},
                                {{ $grupo->primeiro_contato_id }},
                                {{ json_encode($grupo->contato?->nome ?? '') }},
                                {{ json_encode($grupo->contato?->telefone ?? '') }},
                                {{ json_encode($grupo->contato?->email ?? '') }},
                                {{ json_encode($grupo->contato?->profissao ?? '') }},
                                {{ json_encode($grupo->campo ?? '') }},
                                {{ json_encode($grupo->valor_sugerido ?? '') }}
                            ); _breakdownRowIdx = {{ $loop->index }};"
                                class="text-xs bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded-lg font-medium transition-colors">
                            Editar
                        </button>
                        <button onclick="ignorarGrupo('{{ $grupo->tipo }}', '{{ addslashes($grupo->observacao ?? '') }}', 1, {{ $loop->index }})"
                                class="text-xs text-gray-400 hover:text-red-600 px-2 py-1.5 rounded-lg transition-colors">
                            Ignorar
                        </button>
                    @else
                        {{-- Grupo com múltiplos contatos --}}
                        <a href="?filtro=pendente&tipo={{ urlencode($grupo->tipo) }}"
                           class="text-xs text-blue-600 hover:text-blue-800 px-3 py-1.5 rounded-lg hover:bg-blue-50 transition-colors font-medium">
                            Revisar um por um →
                        </a>
                        <button onclick="ignorarGrupo('{{ $grupo->tipo }}', '{{ addslashes($grupo->observacao ?? '') }}', {{ $grupo->total }}, {{ $loop->index }})"
                                class="text-xs bg-gray-100 hover:bg-red-50 text-gray-600 hover:text-red-700 px-3 py-1.5 rounded-lg transition-colors font-medium">
                            Ignorar todos ({{ number_format($grupo->total) }})
                        </button>
                    @endif
                </div>
            </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- Filtro ativo por tipo --}}
    @if($tipoFiltro)
    <div class="mb-4 flex items-center gap-2">
        <span class="text-sm text-gray-500">Filtrando por tipo:</span>
        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-700">
            {{ $tipoFiltro }}
            <a href="?filtro={{ $filtro }}" class="ml-1 hover:text-blue-900">&times;</a>
        </span>
        <span class="text-xs text-gray-400">— clique em <strong>Editar</strong> na linha para corrigir diretamente</span>
    </div>
    @endif

    {{-- Barra de ações em massa (aparece quando algum item está selecionado) --}}
    <div id="barra-bulk" class="hidden mb-4 bg-blue-600 text-white rounded-xl px-4 py-3 flex items-center justify-between gap-4">
        <span class="text-sm font-medium"><span id="bulk-count">0</span> selecionado(s)</span>
        <div class="flex items-center gap-2">
            <button onclick="bulkIgnorar()" class="text-xs bg-white text-blue-700 hover:bg-blue-50 px-3 py-1.5 rounded-lg font-medium transition-colors">
                Ignorar selecionados
            </button>
            <button onclick="desmarcarTodos()" class="text-xs text-blue-200 hover:text-white px-2 py-1.5 rounded-lg transition-colors">
                Cancelar
            </button>
        </div>
    </div>

    {{-- Tabela --}}
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        @if($registros->isEmpty())
            <div class="py-16 text-center text-gray-400">
                <svg class="w-12 h-12 mx-auto mb-3 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <p class="text-sm font-medium">Nenhum item {{ $filtro === 'pendente' ? 'pendente' : ($filtro === 'resolvido' ? 'resolvido' : 'ignorado') }}</p>
                @if($filtro === 'pendente')
                    <p class="text-xs mt-1">Rode <code class="bg-gray-100 px-1 rounded">php artisan contatos:limpar-nomes</code> para detectar problemas</p>
                @endif
            </div>
        @else
        <table class="w-full text-sm">
            <thead>
                <tr class="bg-gray-50 border-b border-gray-200">
                    @if($filtro === 'pendente')
                    <th class="px-4 py-3 w-8">
                        <input type="checkbox" id="check-all" onchange="toggleTodos(this.checked)"
                               class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 cursor-pointer">
                    </th>
                    @endif
                    <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">Contato</th>
                    <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">Problema</th>
                    <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">Valor Original</th>
                    <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">Sugestão</th>
                    <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">Status</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach($registros as $reg)
                <tr x-data="auditRow()"
                    data-audit-id="{{ $reg->id }}"
                    data-contato-id="{{ $reg->contato_id }}"
                    data-nome="{{ $reg->contato?->nome ?? '' }}"
                    class="hover:bg-gray-50 transition-colors" id="row-{{ $reg->id }}" data-tipo="{{ $reg->tipo }}">
                    @if($filtro === 'pendente')
                    <td class="px-4 py-3">
                        <input type="checkbox" value="{{ $reg->id }}" onchange="atualizarSelecao()"
                               class="check-item rounded border-gray-300 text-blue-600 focus:ring-blue-500 cursor-pointer">
                    </td>
                    @endif
                    <td class="px-4 py-3">
                        <div x-show="!editando">
                            <div class="font-medium text-gray-800">{{ $reg->contato?->nome ?? '—' }}</div>
                            <div class="text-xs text-gray-400">
                                ID #{{ $reg->contato_id }}
                                @if($reg->contato?->telefone)
                                    · <span class="font-mono">{{ $reg->contato->telefone }}</span>
                                @endif
                            </div>
                        </div>
                        <div x-show="editando" style="display:none;">
                            <input x-model="nome" type="text" x-ref="nomeInput"
                                   @keydown.enter="salvar()"
                                   @keydown.escape="editando=false"
                                   style="border:2px solid #f97316;border-radius:8px;padding:5px 10px;font-size:13px;width:100%;outline:none;background:#fff7ed;box-sizing:border-box;"
                                   placeholder="Nome correto">
                            <div class="text-xs text-gray-400 mt-0.5">
                                @if($reg->contato?->telefone)
                                    <span class="font-mono">{{ $reg->contato->telefone }}</span>
                                @endif
                            </div>
                        </div>
                    </td>
                    <td class="px-4 py-3">
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium
                            {{ $reg->tipo === 'telefone_invalido' ? 'bg-orange-100 text-orange-700' : 'bg-yellow-100 text-yellow-700' }}">
                            {{ $reg->tipo === 'telefone_invalido' ? '📞 Telefone' : '✏️ Nome' }}
                        </span>
                        @if($reg->observacao)
                            <div class="text-xs text-gray-400 mt-0.5">{{ $reg->observacao }}</div>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        <code class="bg-red-50 text-red-700 px-2 py-0.5 rounded text-xs">{{ $reg->valor_original }}</code>
                    </td>
                    <td class="px-4 py-3">
                        @if($reg->valor_sugerido)
                            <code class="bg-green-50 text-green-700 px-2 py-0.5 rounded text-xs">{{ $reg->valor_sugerido }}</code>
                        @else
                            <span class="text-gray-300 text-xs">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        @if($reg->status === 'pendente')
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-700">Pendente</span>
                        @elseif($reg->status === 'resolvido')
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700">Resolvido</span>
                        @else
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-500">Ignorado</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        @if($reg->status === 'pendente')
                        <div x-show="!editando" class="flex items-center gap-2">
                            <button @click="editando=true; $nextTick(() => $refs.nomeInput.focus())"
                                    class="text-xs bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded-lg font-medium transition-colors">
                                Editar
                            </button>
                            <button onclick="ignorar({{ $reg->id }})"
                                    class="text-xs text-gray-400 hover:text-gray-600 px-2 py-1.5 rounded-lg transition-colors">
                                Ignorar
                            </button>
                            <button onclick="excluirContato({{ $reg->contato_id }}, {{ $reg->id }}, true)"
                                    class="text-xs bg-red-50 hover:bg-red-100 text-red-600 px-2 py-1.5 rounded-lg transition-colors">
                                Excluir
                            </button>
                        </div>
                        <div x-show="editando" style="display:none;" class="flex items-center gap-2">
                            <button @click="salvar()" :disabled="salvando"
                                    :style="salvando
                                        ? 'background:#16a34a;color:#fff;border-radius:8px;padding:5px 12px;font-size:12px;font-weight:600;border:none;cursor:not-allowed;opacity:0.6;'
                                        : 'background:#16a34a;color:#fff;border-radius:8px;padding:5px 12px;font-size:12px;font-weight:600;border:none;cursor:pointer;'"
                                    style="background:#16a34a;color:#fff;border-radius:8px;padding:5px 12px;font-size:12px;font-weight:600;border:none;cursor:pointer;">
                                <span x-text="salvando ? 'Salvando...' : 'Salvar'">Salvar</span>
                            </button>
                            <button @click="editando=false"
                                    style="color:#6b7280;font-size:12px;padding:5px 8px;background:none;border:none;cursor:pointer;">
                                Cancelar
                            </button>
                        </div>
                        @elseif($reg->status === 'ignorado')
                        <div class="flex items-center gap-2">
                            <button onclick="abrirEdicaoContato(
                                    {{ $reg->id }},
                                    {{ $reg->contato_id }},
                                    {{ json_encode($reg->contato?->nome ?? '') }},
                                    {{ json_encode($reg->contato?->telefone ?? '') }},
                                    {{ json_encode($reg->contato?->email ?? '') }},
                                    {{ json_encode($reg->contato?->profissao ?? '') }},
                                    {{ json_encode($reg->campo) }},
                                    {{ json_encode($reg->valor_sugerido ?? '') }}
                                )"
                                    class="text-xs bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded-lg font-medium transition-colors">
                                Editar
                            </button>
                            <button onclick="excluirContato({{ $reg->contato_id }}, {{ $reg->id }})"
                                    class="text-xs bg-red-50 hover:bg-red-100 text-red-600 px-2 py-1.5 rounded-lg transition-colors">
                                Excluir
                            </button>
                        </div>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        <div class="px-4 py-3 border-t border-gray-100">
            {{ $registros->links() }}
        </div>
        @endif
    </div>
</div>

{{-- Modal: editar contato completo (usado no breakdown single e em ignorados) --}}
<div id="modal-editar" class="hidden fixed inset-0 bg-black bg-opacity-40 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-xl w-full max-w-lg">
        <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
            <div>
                <h3 class="font-semibold text-gray-800">Editar Contato</h3>
                <p id="modal-aviso-campo" class="text-xs text-orange-600 mt-0.5"></p>
            </div>
            <button onclick="fecharModal()" class="text-gray-400 hover:text-gray-600 text-xl leading-none">&times;</button>
        </div>
        <div class="px-6 py-5 space-y-4">
            <input type="hidden" id="modal-auditoria-id">
            <input type="hidden" id="modal-contato-id">

            <div class="grid grid-cols-2 gap-4">
                <div class="col-span-2">
                    <label class="text-xs font-medium text-gray-500 uppercase tracking-wide block mb-1">Nome</label>
                    <input id="edit-nome" type="text" maxlength="150"
                           class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="text-xs font-medium text-gray-500 uppercase tracking-wide block mb-1">Telefone</label>
                    <input id="edit-telefone" type="text" maxlength="20"
                           class="w-full border rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <p class="text-xs text-gray-400 mt-0.5">Formato: 5521999999999</p>
                </div>
                <div>
                    <label class="text-xs font-medium text-gray-500 uppercase tracking-wide block mb-1">E-mail</label>
                    <input id="edit-email" type="email" maxlength="200"
                           class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <div class="col-span-2">
                    <label class="text-xs font-medium text-gray-500 uppercase tracking-wide block mb-1">Profissão / Cargo</label>
                    <input id="edit-profissao" type="text" maxlength="200"
                           class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
            </div>
            <p id="modal-erro" class="text-xs text-red-500 hidden"></p>
        </div>
        <div class="px-6 py-4 border-t border-gray-100 flex items-center justify-between">
            <button onclick="excluirContatoModal()" class="px-4 py-2 text-sm text-red-600 hover:text-red-800 rounded-lg font-medium">Excluir contato</button>
            <div class="flex gap-3">
                <button onclick="fecharModal()" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-800 rounded-lg">Cancelar</button>
                <button onclick="salvarEdicaoAuditoria()" class="px-4 py-2 text-sm bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium">Salvar e Resolver</button>
            </div>
        </div>
    </div>
</div>

<script>
const CSRF = document.querySelector('meta[name=csrf-token]').content;

// ── Componente Alpine para edição inline por linha ────────────────────────────
function auditRow() {
    return {
        editando: false,
        nome: '',
        salvando: false,
        init() {
            this.nome = this.$el.dataset.nome || '';
        },
        async salvar() {
            const n = this.nome.trim();
            if (!n) { alert('Digite o nome correto.'); return; }
            this.salvando = true;
            const audId = this.$el.dataset.auditId;
            const r = await fetch(`/contatos/auditoria/${audId}/resolver`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
                body: JSON.stringify({ valor_novo: n }),
            });
            this.salvando = false;
            if (r.ok) {
                this.$el.remove();
                atualizarContadorPendente(-1);
            } else {
                const err = await r.json().catch(() => ({}));
                alert(err.erro || 'Erro ao resolver. Tente novamente.');
            }
        },
    };
}

// ── Seleção e ação em massa ───────────────────────────────────────────────────

function atualizarSelecao() {
    const checks = document.querySelectorAll('.check-item:checked');
    const total  = document.querySelectorAll('.check-item').length;
    document.getElementById('bulk-count').textContent = checks.length;
    document.getElementById('barra-bulk').classList.toggle('hidden', checks.length === 0);
    document.getElementById('check-all').indeterminate = checks.length > 0 && checks.length < total;
    document.getElementById('check-all').checked = checks.length === total;
}

function toggleTodos(checked) {
    document.querySelectorAll('.check-item').forEach(c => c.checked = checked);
    atualizarSelecao();
}

function desmarcarTodos() {
    document.querySelectorAll('.check-item').forEach(c => c.checked = false);
    atualizarSelecao();
}

async function bulkIgnorar() {
    const ids = [...document.querySelectorAll('.check-item:checked')].map(c => parseInt(c.value));
    if (!ids.length) return;
    if (!confirm(`Ignorar ${ids.length} item(s) selecionado(s)?`)) return;

    const res = await fetch('/contatos/auditoria/bulk-ignorar', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
        body: JSON.stringify({ ids }),
    });

    if (res.ok) {
        const { ignorados } = await res.json();
        ids.forEach(id => document.getElementById(`row-${id}`)?.remove());
        desmarcarTodos();
        atualizarContadorPendente(-ignorados);
    } else {
        alert('Erro ao ignorar itens.');
    }
}

let _breakdownRowIdx = null;

async function ignorarGrupo(tipo, observacao, total, rowIdx) {
    const msg = total === 1
        ? 'Ignorar este registro?'
        : `Ignorar todos os ${total} registros deste grupo?\n\nEles não serão mais exibidos como pendentes.`;
    if (!confirm(msg)) return;

    const res = await fetch('/contatos/auditoria/bulk-ignorar', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
        body: JSON.stringify({ tipo, observacao }),
    });

    if (res.ok) {
        const { ignorados } = await res.json();
        document.getElementById(`breakdown-row-${rowIdx}`)?.remove();
        atualizarContadorPendente(-ignorados);
        document.querySelectorAll(`tr[data-tipo="${tipo}"]`).forEach(r => r.remove());
    } else {
        alert('Erro ao ignorar grupo.');
    }
}

function atualizarContadorPendente(delta) {
    const el = document.getElementById('count-pendente');
    if (el) el.textContent = Math.max(0, parseInt(el.textContent) + delta);
}

// ── Edição via modal (breakdown single-contact + ignorados) ──────────────────

let _auditoriaId   = null;
let _contatoId     = null;
let _campoProblema = null;

function abrirEdicaoContato(auditoriaId, contatoId, nome, telefone, email, profissao, campo, sugerido) {
    _auditoriaId   = auditoriaId;
    _contatoId     = contatoId;
    _campoProblema = campo;

    document.getElementById('modal-auditoria-id').value = auditoriaId;
    document.getElementById('modal-contato-id').value   = contatoId;
    document.getElementById('edit-nome').value      = nome;
    document.getElementById('edit-email').value     = email;
    document.getElementById('edit-profissao').value = profissao;

    const telInput  = document.getElementById('edit-telefone');
    const nomeInput = document.getElementById('edit-nome');
    nomeInput.classList.remove('border-orange-400', 'bg-orange-50');
    telInput.classList.remove('border-orange-400', 'bg-orange-50');

    if (campo === 'telefone') {
        telInput.value = sugerido || telefone;
        telInput.classList.add('border-orange-400', 'bg-orange-50');
        document.getElementById('modal-aviso-campo').textContent = '⚠ O telefone foi sinalizado — corrija e salve.';
    } else {
        telInput.value = telefone;
        nomeInput.classList.add('border-orange-400', 'bg-orange-50');
        document.getElementById('modal-aviso-campo').textContent = '⚠ O nome foi sinalizado — corrija e salve.';
    }

    document.getElementById('modal-erro').classList.add('hidden');
    document.getElementById('modal-editar').classList.remove('hidden');
    setTimeout(() => document.getElementById(campo === 'telefone' ? 'edit-telefone' : 'edit-nome').focus(), 50);
}

function fecharModal() {
    document.getElementById('modal-editar').classList.add('hidden');
    document.getElementById('edit-nome').classList.remove('border-orange-400', 'bg-orange-50');
    document.getElementById('edit-telefone').classList.remove('border-orange-400', 'bg-orange-50');
    _auditoriaId = _contatoId = _campoProblema = null;
}

async function salvarEdicaoAuditoria() {
    const nome      = document.getElementById('edit-nome').value.trim();
    const telefone  = document.getElementById('edit-telefone').value.trim();
    const erroEl    = document.getElementById('modal-erro');

    if (!nome) { erroEl.textContent = 'O nome não pode ficar vazio.'; erroEl.classList.remove('hidden'); return; }

    const valorCorrigido = _campoProblema === 'telefone' ? telefone : nome;
    const resAuditoria = await fetch(`/contatos/auditoria/${_auditoriaId}/resolver`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
        body: JSON.stringify({ valor_novo: valorCorrigido }),
    });

    if (resAuditoria.ok) {
        document.getElementById(`row-${_auditoriaId}`)?.remove();
        if (_breakdownRowIdx !== null) {
            document.getElementById(`breakdown-row-${_breakdownRowIdx}`)?.remove();
            _breakdownRowIdx = null;
        }
        atualizarContadorPendente(-1);
        fecharModal();
    } else {
        const err = await resAuditoria.json().catch(() => ({}));
        erroEl.textContent = err.erro || 'Erro ao resolver auditoria.';
        erroEl.classList.remove('hidden');
    }
}

async function ignorar(id) {
    if (!confirm('Ignorar este item?')) return;
    const res = await fetch(`/contatos/auditoria/${id}/ignorar`, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': CSRF },
    });
    if (res.ok) {
        document.getElementById(`row-${id}`)?.remove();
        atualizarContadorPendente(-1);
    }
}

async function excluirContato(contatoId, auditoriaId, eraPendente = false) {
    if (!confirm('Excluir definitivamente este contato? Esta ação não pode ser desfeita.')) return false;
    const res = await fetch(`/contato/${contatoId}/excluir-definitivo`, {
        method: 'DELETE',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
    });
    if (res.ok) {
        document.getElementById(`row-${auditoriaId}`)?.remove();
        if (eraPendente) atualizarContadorPendente(-1);
        return true;
    }
    alert('Erro ao excluir contato.');
    return false;
}

async function excluirContatoModal() {
    if (_contatoId === null) return;
    const breakdownIdx = _breakdownRowIdx;
    const ok = await excluirContato(_contatoId, _auditoriaId, breakdownIdx !== null);
    if (!ok) return;
    if (breakdownIdx !== null) {
        document.getElementById(`breakdown-row-${breakdownIdx}`)?.remove();
        _breakdownRowIdx = null;
    }
    fecharModal();
}

document.getElementById('modal-editar').addEventListener('click', function(e) {
    if (e.target === this) fecharModal();
});
</script>
@endsection
