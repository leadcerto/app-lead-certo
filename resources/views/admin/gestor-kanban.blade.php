@extends('layouts.app')

@section('title', 'Gestor do Kanban — Configuração')

@section('content')
<div x-data="gestorKanbanConfig()" class="max-w-3xl">
    <h1 class="text-xl font-bold text-gray-800 mb-1">Gestor do Kanban — Prompt Global</h1>
    <p class="text-sm text-gray-500 mb-5">
        Este prompt é usado pelo Gestor toda semana, para todos os franqueados. Só o perfil admin edita.
    </p>

    <div class="bg-white rounded-xl border border-gray-200 p-5 space-y-5">
        <div>
            <label class="text-xs font-medium text-gray-500 uppercase tracking-wide block mb-1">
                Prompt de análise por coluna
            </label>
            <textarea x-model="promptColuna" rows="14"
                      class="w-full border rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-blue-500"></textarea>
        </div>

        <div>
            <label class="text-xs font-medium text-gray-500 uppercase tracking-wide block mb-1">
                Prompt de síntese geral da semana
            </label>
            <textarea x-model="promptSintese" rows="8"
                      class="w-full border rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-blue-500"></textarea>
        </div>

        <div class="flex items-center gap-3">
            <button @click="salvar()" :disabled="salvando"
                    class="px-4 py-2 text-sm bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium disabled:opacity-50">
                <span x-text="salvando ? 'Salvando...' : 'Salvar'"></span>
            </button>
            <span x-show="salvo" class="text-xs text-green-600">Salvo!</span>
        </div>
    </div>
</div>

<script>
function gestorKanbanConfig() {
    return {
        promptColuna: {{ Js::from($config->prompt_coluna) }},
        promptSintese: {{ Js::from($config->prompt_sintese) }},
        salvando: false,
        salvo: false,
        async salvar() {
            this.salvando = true;
            this.salvo = false;
            const res = await fetch('/api/admin/gestor-kanban/prompt', {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify({ prompt_coluna: this.promptColuna, prompt_sintese: this.promptSintese }),
            });
            this.salvando = false;
            if (res.ok) {
                this.salvo = true;
                setTimeout(() => this.salvo = false, 2000);
            } else {
                alert('Erro ao salvar.');
            }
        },
    };
}
</script>
@endsection
