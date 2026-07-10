{{--
    Editor de botões interativos (até 3), reaproveitado no fluxo de "Adicionar
    mensagem" e no fluxo de editar mensagem existente. $arrayExpr é a expressão
    Alpine (string) do array de botões dessa mensagem — ex: "novoBotoes[seq.id]"
    ou "editMsgBotoes".
--}}
<div class="mt-2 pt-2 border-t border-gray-100">
    <p class="text-xs font-semibold text-gray-500 mb-1.5">Botões interativos (opcional, máx. 3)</p>
    <template x-for="(botao, i) in ({{ $arrayExpr }} || [])" :key="i">
        <div class="flex items-center gap-2 mb-1.5">
            <input type="text" maxlength="20"
                   :value="botao.text"
                   @input="{{ $arrayExpr }}[i].text = $event.target.value"
                   placeholder="Texto do botão (máx. 20)"
                   class="flex-1 text-xs border border-gray-300 rounded px-2 py-1">
            <select :value="botao.action"
                    @change="{{ $arrayExpr }}[i].action = $event.target.value; {{ $arrayExpr }}[i].target = ''"
                    class="text-xs border border-gray-300 rounded px-1.5 py-1 bg-white">
                <option value="move_column">Mover para coluna</option>
                <option value="trigger_ia">Acionar IA</option>
                <option value="opt_out">Parar mensagens (opt-out)</option>
                <option value="open_url">Abrir link</option>
                <option value="call">Ligar para número</option>
            </select>
            <template x-if="botao.action === 'move_column'">
                <select :value="botao.target"
                        @change="{{ $arrayExpr }}[i].target = $event.target.value"
                        class="text-xs border border-gray-300 rounded px-1.5 py-1 bg-white">
                    <template x-for="c in colunas" :key="c.key">
                        <option :value="c.key" x-text="c.label"></option>
                    </template>
                </select>
            </template>
            <template x-if="botao.action === 'open_url'">
                <input type="url"
                       :value="botao.target"
                       @input="{{ $arrayExpr }}[i].target = $event.target.value"
                       placeholder="https://..."
                       class="text-xs border border-gray-300 rounded px-2 py-1 w-40">
            </template>
            <template x-if="botao.action === 'call'">
                <input type="tel"
                       :value="botao.target"
                       @input="{{ $arrayExpr }}[i].target = $event.target.value"
                       placeholder="+5511999999999"
                       class="text-xs border border-gray-300 rounded px-2 py-1 w-36">
            </template>
            <button type="button" @click="{{ $arrayExpr }}.splice(i, 1)" class="text-red-300 hover:text-red-500 text-xs">✕</button>
        </div>
    </template>
    <button type="button"
            @click="{{ $arrayExpr }} = [...({{ $arrayExpr }} || []), { text: '', action: 'move_column', target: '' }]"
            :disabled="({{ $arrayExpr }} || []).length >= 3"
            class="text-xs text-purple-600 hover:text-purple-700 disabled:opacity-40 disabled:cursor-not-allowed">
        + Adicionar botão
    </button>
</div>
