@extends('layouts.app')

@section('title', 'Documentação — Botões Interativos | Lead Certo')

@section('content')
<div class="max-w-4xl mx-auto pb-16">

    <div class="mb-6">
        <div class="flex items-center gap-2 mb-1">
            <span class="text-xs font-semibold text-green-700 bg-green-100 px-2 py-0.5 rounded-full">Kanban</span>
            <span class="text-xs font-semibold text-green-700 bg-green-100 px-2 py-0.5 rounded-full">Implementado</span>
        </div>
        <h1 class="text-2xl font-bold text-gray-800">Botões Interativos do WhatsApp</h1>
        <p class="text-sm text-gray-500 mt-1 max-w-2xl">
            Estratégia de negócio, cenários de uso e manual prático de configuração dos botões de resposta rápida
            (até 3 por mensagem) dentro das colunas do Kanban.
        </p>
    </div>

    <div class="bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 mb-8 text-sm text-amber-800">
        <strong>Importante:</strong> os botões só são enviados junto com a última mensagem da sequência automática da coluna — se a coluna não tiver sequência ativa, os botões salvam mas nunca são enviados (ver "O que ainda não faz" no fim da página). Plano técnico completo:
        <code class="font-mono text-xs bg-white px-1.5 py-0.5 rounded border border-amber-200">docs/superpowers/plans/2026-07-09-botoes-interativos-whatsapp.md</code>
    </div>

    {{-- Navegação rápida --}}
    <nav class="flex flex-wrap gap-2 mb-10 text-xs">
        <a href="#estrategia" class="px-3 py-1.5 rounded-full bg-gray-100 text-gray-600 hover:bg-gray-200">Estratégia</a>
        <a href="#como-configurar" class="px-3 py-1.5 rounded-full bg-gray-100 text-gray-600 hover:bg-gray-200">Como configurar</a>
        <a href="#acoes" class="px-3 py-1.5 rounded-full bg-gray-100 text-gray-600 hover:bg-gray-200">As 5 ações</a>
        <a href="#cenarios" class="px-3 py-1.5 rounded-full bg-gray-100 text-gray-600 hover:bg-gray-200">Cenários de uso</a>
        <a href="#faq" class="px-3 py-1.5 rounded-full bg-gray-100 text-gray-600 hover:bg-gray-200">Perguntas frequentes</a>
        <a href="#limitacoes" class="px-3 py-1.5 rounded-full bg-gray-100 text-gray-600 hover:bg-gray-200">O que ainda não faz</a>
    </nav>

    {{-- Estratégia --}}
    <section id="estrategia" class="mb-12 scroll-mt-6">
        <h2 class="text-lg font-bold text-gray-800 mb-3">Estratégia de negócio</h2>

        <div class="bg-white rounded-xl border border-gray-200 p-5 mb-4">
            <p class="text-sm text-gray-600 mb-3">
                <strong class="text-gray-800">Fricção zero.</strong> Perguntas abertas ("qual sua dúvida?") têm taxa de
                resposta baixa porque exigem esforço de digitar. Um botão reduz a resposta a um único toque —
                times que trocam texto livre por botões em prospecção e pós-venda relatam algo na faixa de
                <strong class="text-gray-800">+60% de taxa de resposta</strong>.
            </p>
            <p class="text-sm text-gray-600">
                <strong class="text-gray-800">A "dança" Botão → Kanban → IA:</strong> o lead clica (esforço mínimo) →
                o card muda de coluna sozinho → a IA assume já sabendo por que o card chegou ali.
            </p>
        </div>

        <div class="bg-green-50 border border-green-200 rounded-xl p-5 mb-4">
            <p class="text-xs font-bold text-green-700 uppercase tracking-wide mb-2">A regra de ouro do ecossistema</p>
            <p class="text-sm text-gray-700 mb-2">
                <strong>O opt-out ("não quero mais receber mensagem") é por franqueado, não pela marca.</strong>
                Como o Lead Certo é um ecossistema com vários franqueados, o lead que bloqueia um negócio continua
                alcançável pelos outros — em outros números de WhatsApp. Isso protege o número de cada franqueado
                contra denúncia de spam e banimento da Meta, sem fechar a porta do ecossistema inteiro.
            </p>
            <p class="text-sm text-gray-700">
                Um lead que sai hoje não está perdido — é candidato a reabordagem em 1, 3 ou 6 meses, de preferência
                com uma oferta diferente (um brinde ou benefício gratuito reabre o interesse melhor do que repetir
                a mesma abordagem).
            </p>
        </div>

        <div class="grid grid-cols-3 gap-3">
            <div class="bg-white border border-gray-200 rounded-xl p-4">
                <p class="text-xs text-gray-400 mb-1">Taxa de interação</p>
                <p class="text-sm font-semibold text-gray-700">% dos leads que clicaram em algum botão</p>
            </div>
            <div class="bg-white border border-gray-200 rounded-xl p-4">
                <p class="text-xs text-gray-400 mb-1">Tempo economizado</p>
                <p class="text-sm font-semibold text-gray-700">Cards que mudaram de fase sem toque humano</p>
            </div>
            <div class="bg-white border border-gray-200 rounded-xl p-4">
                <p class="text-xs text-gray-400 mb-1">Deflection rate</p>
                <p class="text-sm font-semibold text-gray-700">% resolvido pela IA após o clique inicial</p>
            </div>
        </div>
    </section>

    {{-- Como configurar --}}
    <section id="como-configurar" class="mb-12 scroll-mt-6">
        <h2 class="text-lg font-bold text-gray-800 mb-3">Como configurar</h2>
        <ol class="space-y-3">
            <li class="flex gap-3 bg-white border border-gray-200 rounded-xl p-4">
                <span class="w-6 h-6 rounded-full bg-green-600 text-white text-xs font-bold flex items-center justify-center flex-shrink-0">1</span>
                <div>
                    <p class="text-sm font-semibold text-gray-800">Abra Kanban → Configurações</p>
                    <p class="text-xs text-gray-500">Escolha a coluna onde quer que os botões apareçam (ex: "Aguardando Lead", "Encerrado").</p>
                </div>
            </li>
            <li class="flex gap-3 bg-white border border-gray-200 rounded-xl p-4">
                <span class="w-6 h-6 rounded-full bg-green-600 text-white text-xs font-bold flex items-center justify-center flex-shrink-0">2</span>
                <div>
                    <p class="text-sm font-semibold text-gray-800">Role até "Botões Interativos"</p>
                    <p class="text-xs text-gray-500">Fica dentro do bloco do Agente de IA daquela coluna, junto com o temporizador de resposta.</p>
                </div>
            </li>
            <li class="flex gap-3 bg-white border border-gray-200 rounded-xl p-4">
                <span class="w-6 h-6 rounded-full bg-green-600 text-white text-xs font-bold flex items-center justify-center flex-shrink-0">3</span>
                <div>
                    <p class="text-sm font-semibold text-gray-800">Clique em "+ Adicionar botão"</p>
                    <p class="text-xs text-gray-500">Digite o texto (até 20 caracteres — limite do próprio WhatsApp) e escolha a ação.</p>
                </div>
            </li>
            <li class="flex gap-3 bg-white border border-gray-200 rounded-xl p-4">
                <span class="w-6 h-6 rounded-full bg-green-600 text-white text-xs font-bold flex items-center justify-center flex-shrink-0">4</span>
                <div>
                    <p class="text-sm font-semibold text-gray-800">Repita até 3 vezes</p>
                    <p class="text-xs text-gray-500">O botão de adicionar desativa sozinho no 3º — o WhatsApp não exibe um 4º botão de resposta de forma confiável.</p>
                </div>
            </li>
            <li class="flex gap-3 bg-white border border-gray-200 rounded-xl p-4">
                <span class="w-6 h-6 rounded-full bg-green-600 text-white text-xs font-bold flex items-center justify-center flex-shrink-0">5</span>
                <div>
                    <p class="text-sm font-semibold text-gray-800">Salve</p>
                    <p class="text-xs text-gray-500">Os botões passam a ser enviados automaticamente junto com a mensagem daquela coluna.</p>
                </div>
            </li>
        </ol>
    </section>

    {{-- As 5 ações --}}
    <section id="acoes" class="mb-12 scroll-mt-6">
        <h2 class="text-lg font-bold text-gray-800 mb-3">As 5 ações disponíveis</h2>
        <div class="space-y-3">
            <div class="bg-white border border-gray-200 rounded-xl p-4">
                <p class="text-sm font-bold text-gray-800">Mover para coluna <span class="ml-2 text-xs font-semibold bg-blue-100 text-blue-700 px-2 py-0.5 rounded-full">move_column</span></p>
                <p class="text-xs text-gray-500 mt-1">O ticket pula direto para a coluna escolhida. Use pra roteamento (ex: "Suporte Técnico" → coluna do time técnico) ou pra confirmar uma etapa (ex: "Aprovar Orçamento" → coluna de pagamento).</p>
            </div>
            <div class="bg-white border border-gray-200 rounded-xl p-4">
                <p class="text-sm font-bold text-gray-800">Acionar IA <span class="ml-2 text-xs font-semibold bg-purple-100 text-purple-700 px-2 py-0.5 rounded-full">trigger_ia</span></p>
                <p class="text-xs text-gray-500 mt-1">Devolve o atendimento pra Inteligência Artificial daquela mesma coluna. Use quando o lead quer continuar com o bot em vez de esperar um humano.</p>
            </div>
            <div class="bg-white border border-gray-200 rounded-xl p-4">
                <p class="text-sm font-bold text-gray-800">Parar mensagens <span class="ml-2 text-xs font-semibold bg-red-100 text-red-700 px-2 py-0.5 rounded-full">opt_out</span></p>
                <p class="text-xs text-gray-500 mt-1">Marca o contato como "não enviar mais" — só pra este negócio. Nenhuma automação de sequência envia mais nada pra esse número enquanto o bloqueio estiver ativo.</p>
            </div>
            <div class="bg-white border border-gray-200 rounded-xl p-4">
                <p class="text-sm font-bold text-gray-800">Abrir link <span class="ml-2 text-xs font-semibold bg-green-100 text-green-700 px-2 py-0.5 rounded-full">open_url</span></p>
                <p class="text-xs text-gray-500 mt-1">Abre um site direto no celular do lead (catálogo, vídeo, página de pagamento). Não avisa o sistema quando é clicado — não move coluna nem aciona nada.</p>
            </div>
            <div class="bg-white border border-gray-200 rounded-xl p-4">
                <p class="text-sm font-bold text-gray-800">Ligar para número <span class="ml-2 text-xs font-semibold bg-green-100 text-green-700 px-2 py-0.5 rounded-full">call</span></p>
                <p class="text-xs text-gray-500 mt-1">Abre o discador do celular do lead já com o número preenchido. Também não avisa o sistema quando é clicado.</p>
            </div>
        </div>
    </section>

    {{-- Cenários --}}
    <section id="cenarios" class="mb-12 scroll-mt-6">
        <h2 class="text-lg font-bold text-gray-800 mb-4">Cenários de uso</h2>

        <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-2">Qualificação &amp; triagem</p>
        <div class="space-y-2 mb-5">
            <div class="bg-white border border-gray-200 rounded-xl p-4">
                <div class="flex items-center justify-between mb-1">
                    <p class="text-sm font-semibold text-gray-800">Peneira de qualificação (topo de funil)</p>
                    <span class="text-xs text-gray-400 font-mono">lead_novo</span>
                </div>
                <p class="text-xs text-gray-500 italic mb-2">"Bem-vindo! Para eu te passar o melhor especialista, qual seu volume mensal de demanda?"</p>
                <p class="text-xs text-gray-600">Até 10k/mês → segue com IA · Acima de 50k/mês → move pra Atendimento VIP humano</p>
            </div>
            <div class="bg-white border border-gray-200 rounded-xl p-4">
                <div class="flex items-center justify-between mb-1">
                    <p class="text-sm font-semibold text-gray-800">Triagem de suporte (roteamento)</p>
                    <span class="text-xs text-gray-400 font-mono">em_atendimento</span>
                </div>
                <p class="text-xs text-gray-500 italic mb-2">"Bem-vindo ao suporte! Sobre o que deseja falar?"</p>
                <p class="text-xs text-gray-600">Dúvida Financeira → fila financeiro · Suporte Técnico → fila técnica · Falar no Telefone → liga direto</p>
            </div>
        </div>

        <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-2">Nutrição &amp; reengajamento</p>
        <div class="space-y-2 mb-5">
            <div class="bg-white border border-gray-200 rounded-xl p-4">
                <div class="flex items-center justify-between mb-1">
                    <p class="text-sm font-semibold text-gray-800">"Despertador" híbrido — lead foi silenciado</p>
                    <span class="text-xs text-gray-400 font-mono">qualquer coluna c/ IA</span>
                </div>
                <p class="text-xs text-gray-500 italic mb-2">"Vi que você não conseguiu me responder. Quer que eu retome ou prefere falar com um atendente?"</p>
                <p class="text-xs text-gray-600">Continuar com IA → acionar IA · Falar com Humano → move coluna · Não tenho interesse → opt-out</p>
            </div>
            <div class="bg-white border border-gray-200 rounded-xl p-4">
                <div class="flex items-center justify-between mb-1">
                    <p class="text-sm font-semibold text-gray-800">Retargeting de base fria parada há meses</p>
                    <span class="text-xs text-gray-400 font-mono">coluna: reativação</span>
                </div>
                <p class="text-xs text-gray-500 italic mb-2">"Faz tempo que não nos falamos. Você ainda busca otimizar sua operação de transportes?"</p>
                <p class="text-xs text-gray-600">Sim, vamos falar → agendamento · Só no ano que vem → snooze 6 meses · Já resolvi → Perdido/Resolvido</p>
            </div>
        </div>

        <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-2">Pós-venda &amp; satisfação</p>
        <div class="space-y-2">
            <div class="bg-white border border-gray-200 rounded-xl p-4">
                <div class="flex items-center justify-between mb-1">
                    <p class="text-sm font-semibold text-gray-800">NPS de micro-esforço</p>
                    <span class="text-xs text-gray-400 font-mono">encerrado</span>
                </div>
                <p class="text-xs text-gray-500 italic mb-2">"Foi um prazer atendê-lo! Como você avalia nosso serviço no geral?"</p>
                <p class="text-xs text-gray-600">Excelente 🟢 → pede avaliação Google · Foi Ok 🟡 → pergunta o que faltou · Tive problemas 🔴 → Retenção, alerta humano na hora</p>
            </div>
            <div class="bg-white border border-gray-200 rounded-xl p-4">
                <div class="flex items-center justify-between mb-1">
                    <p class="text-sm font-semibold text-gray-800">Upsell / cross-sell pós-venda</p>
                    <span class="text-xs text-gray-400 font-mono">nutrição de base</span>
                </div>
                <p class="text-xs text-gray-500 italic mb-2">"Muitos clientes que fazem esse trajeto também usam nosso Seguro de Carga. Gostaria de saber como funciona?"</p>
                <p class="text-xs text-gray-600">Como funciona? → acionar IA · Quero falar de outro serviço → move coluna · Agora não → encerra fluxo</p>
            </div>
        </div>
    </section>

    {{-- FAQ --}}
    <section id="faq" class="mb-12 scroll-mt-6">
        <h2 class="text-lg font-bold text-gray-800 mb-3">Perguntas frequentes</h2>
        <div class="bg-white border border-gray-200 rounded-xl divide-y divide-gray-100">
            <div class="p-4">
                <p class="text-sm font-semibold text-gray-800 mb-1">Posso usar botão de link ou de ligação também?</p>
                <p class="text-xs text-gray-500">Sim, mas eles não têm "ação de sistema" configurável — um botão de link só abre o site, um botão de ligação só disca. Não avisam o sistema quando são clicados.</p>
            </div>
            <div class="p-4">
                <p class="text-sm font-semibold text-gray-800 mb-1">O que acontece se o lead responder digitando em vez de clicar?</p>
                <p class="text-xs text-gray-500">O sistema trata como mensagem de texto normal — a IA da coluna responde como sempre. Nada quebra, só não aciona a ação do botão.</p>
            </div>
            <div class="p-4">
                <p class="text-sm font-semibold text-gray-800 mb-1">Dá pra reverter um "Parar mensagens" clicado sem querer?</p>
                <p class="text-xs text-gray-500">Ainda não existe tela pra desfazer isso manualmente — é uma melhoria pendente. Por enquanto, precisa ser ajustado direto com o time técnico.</p>
            </div>
        </div>
    </section>

    {{-- Limitações --}}
    <section id="limitacoes" class="scroll-mt-6">
        <h2 class="text-lg font-bold text-gray-800 mb-3">O que ainda não faz (nesta primeira versão)</h2>
        <ul class="space-y-2 text-sm text-gray-600">
            <li class="flex gap-2"><span class="text-red-500 font-bold">!</span><span><strong>Os botões só são enviados junto com a última mensagem da sequência automática daquela coluna.</strong> Se a coluna não tiver nenhuma sequência ativa configurada, os botões salvam normalmente na tela mas nunca chegam pro lead — sem aviso nenhum hoje. Configure uma sequência (mesmo que só com 1 mensagem) na mesma coluna onde configurar botões.</span></li>
            <li class="flex gap-2"><span class="text-amber-500 font-bold">!</span><span><strong>Misturar "Mover coluna/Acionar IA/Parar mensagens" com "Abrir link/Ligar" na mesma coluna</strong> ainda não foi validado contra o WhatsApp de verdade — a Meta historicamente não deixa misturar botão de resposta com botão de link/ligação na mesma mensagem. Até confirmarmos, evite combinar as duas famílias nos 3 slots de uma mesma coluna.</span></li>
            <li class="flex gap-2"><span class="text-amber-500 font-bold">!</span><span><strong>Adicionar etiqueta/tag</strong> pelo clique do botão ainda não existe — só as 5 ações acima.</span></li>
            <li class="flex gap-2"><span class="text-amber-500 font-bold">!</span><span><strong>O bloqueio de "Parar mensagens"</strong> só impede as sequências automáticas por enquanto. Um vendedor humano ainda consegue mandar mensagem manual pro contato bloqueado.</span></li>
            <li class="flex gap-2"><span class="text-amber-500 font-bold">!</span><span><strong>Clique duplo rápido</strong> no mesmo botão pode disparar a ação duas vezes — sem trava de idempotência ainda.</span></li>
            <li class="flex gap-2"><span class="text-amber-500 font-bold">!</span><span><strong>Reabordagem automática</strong> de quem deu opt-out (esperar 6 meses e tentar de novo com uma oferta) ainda não existe — precisa ser feito manualmente.</span></li>
        </ul>
    </section>

</div>
@endsection
