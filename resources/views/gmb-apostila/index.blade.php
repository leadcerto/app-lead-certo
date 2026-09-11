@extends('layouts.app')

@section('title', 'Apostila — GMB Manual Técnico e Boas Práticas')

@section('content')
<div class="max-w-5xl mx-auto space-y-6 pb-16">

    {{-- Header --}}
    <div class="flex items-center justify-between border-b border-gray-200 pb-4">
        <div>
            <div class="flex items-center gap-2 text-xs text-gray-500 mb-1">
                <a href="{{ route('admin.perfis-gmb.index') }}" class="hover:underline">Google Meu Negócio</a>
                <span>/</span>
                <span class="text-gray-700">Apostila</span>
            </div>
            <h1 class="text-2xl font-bold text-gray-900 font-heading">📘 GMB — Manual Técnico e Boas Práticas</h1>
            <p class="text-sm text-gray-500 mt-1">Os fatores reais que decidem quem aparece no topo do Google Maps — e como continuar lá quando a busca é feita por IA.</p>
        </div>
    </div>

    {{-- Navegação rápida --}}
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 flex flex-wrap gap-2">
        <a href="#beneficios" class="px-3 py-1.5 bg-gray-50 hover:bg-gray-100 text-gray-700 text-xs font-semibold rounded-lg transition">Por que importa</a>
        <a href="#pilares" class="px-3 py-1.5 bg-gray-50 hover:bg-gray-100 text-gray-700 text-xs font-semibold rounded-lg transition">3 pilares</a>
        <a href="#elegibilidade" class="px-3 py-1.5 bg-gray-50 hover:bg-gray-100 text-gray-700 text-xs font-semibold rounded-lg transition">Elegibilidade</a>
        <a href="#fatores" class="px-3 py-1.5 bg-gray-50 hover:bg-gray-100 text-gray-700 text-xs font-semibold rounded-lg transition">Os 11 fatores</a>
        <a href="#invisiveis" class="px-3 py-1.5 bg-gray-50 hover:bg-gray-100 text-gray-700 text-xs font-semibold rounded-lg transition">Regras invisíveis</a>
        <a href="#concorrencia" class="px-3 py-1.5 bg-gray-50 hover:bg-gray-100 text-gray-700 text-xs font-semibold rounded-lg transition">Vencer concorrente</a>
        <a href="#schema" class="px-3 py-1.5 bg-gray-50 hover:bg-gray-100 text-gray-700 text-xs font-semibold rounded-lg transition">Dados estruturados</a>
        <a href="#ferramentas" class="px-3 py-1.5 bg-gray-50 hover:bg-gray-100 text-gray-700 text-xs font-semibold rounded-lg transition">Ferramentas</a>
        <a href="#roteiro" class="px-3 py-1.5 bg-gray-50 hover:bg-gray-100 text-gray-700 text-xs font-semibold rounded-lg transition">Roteiro 7 passos</a>
    </div>

    {{-- Por que importa --}}
    <section id="beneficios" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 space-y-4 scroll-mt-20">
        <h2 class="text-lg font-bold text-gray-900 font-heading">🏆 Por que isso importa</h2>
        <p class="text-sm text-gray-600">Estar entre as 3 fichas em destaque no mapa — antes de qualquer resultado orgânico — é um dos ativos de marketing mais valiosos que existem. É a diferença entre um perfil cadastrado e um perfil que gera cliente.</p>

        <ul class="space-y-2 text-sm text-gray-700">
            <li>→ <strong>Fundo de funil:</strong> quem busca termo local já tem um problema pra resolver agora — mais de 75% visita ou contata a empresa em até 24h.</li>
            <li>→ <strong>Concentração de clique:</strong> as 3 fichas em destaque capturam 40–60% de todos os cliques da página. Da 4ª posição em diante, a visibilidade cai drasticamente.</li>
            <li>→ <strong>CAC menor:</strong> tráfego orgânico e gratuito — sem pagar por clique/ligação/rota como no Google Ads.</li>
            <li>→ <strong>Conversão com zero fricção:</strong> botões de 1 toque (Ligar, Rotas, WhatsApp, Website).</li>
            <li>→ <strong>Autoridade e prova social:</strong> nota alta (4.8+) com várias avaliações vira selo implícito de liderança.</li>
            <li>→ <strong>Prioridade nas respostas de IA:</strong> buscas conversacionais priorizam o topo do mapa pra compor a resposta.</li>
        </ul>

        <div class="overflow-x-auto rounded-xl border border-gray-200 mt-2">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-600">
                    <tr><th class="px-4 py-2 text-left font-bold">Métrica</th><th class="px-4 py-2 text-left font-bold">Top 3 (mapa)</th><th class="px-4 py-2 text-left font-bold">4ª posição em diante</th></tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <tr><td class="px-4 py-2 font-medium text-gray-700">Visibilidade mobile</td><td class="px-4 py-2 text-green-700 font-semibold">Imediata — 1ª dobra</td><td class="px-4 py-2 text-red-600">Oculta, exige clique extra</td></tr>
                    <tr><td class="px-4 py-2 font-medium text-gray-700">Ligações / mensagens</td><td class="px-4 py-2 text-green-700 font-semibold">Elevado e diário</td><td class="px-4 py-2 text-red-600">Residual, esporádico</td></tr>
                    <tr><td class="px-4 py-2 font-medium text-gray-700">Percepção de marca</td><td class="px-4 py-2 text-green-700 font-semibold">Líder / referência local</td><td class="px-4 py-2 text-red-600">Mais uma opção secundária</td></tr>
                    <tr><td class="px-4 py-2 font-medium text-gray-700">Dependência de anúncio pago</td><td class="px-4 py-2 text-green-700 font-semibold">Baixa a moderada</td><td class="px-4 py-2 text-red-600">Alta</td></tr>
                    <tr><td class="px-4 py-2 font-medium text-gray-700">Citação por IA</td><td class="px-4 py-2 text-green-700 font-semibold">Altíssima probabilidade</td><td class="px-4 py-2 text-red-600">Praticamente ignorada</td></tr>
                </tbody>
            </table>
        </div>
    </section>

    {{-- 3 pilares --}}
    <section id="pilares" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 space-y-4 scroll-mt-20">
        <h2 class="text-lg font-bold text-gray-900 font-heading">🧭 Os 3 pilares do ranqueamento local</h2>
        <p class="text-sm text-gray-600"><strong>Configuração</strong> coloca a empresa no Google. <strong>Autoridade</strong> é o que faz o Google considerar essa empresa uma boa resposta.</p>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="p-4 bg-gray-50 rounded-xl border border-gray-200">
                <span class="text-[11px] font-bold text-green-700 uppercase tracking-wide">Pilar 1</span>
                <h3 class="font-bold text-gray-900 mt-1 mb-1">Relevância</h3>
                <p class="text-xs text-gray-600">O quão bem o perfil corresponde ao que a pessoa busca — categoria, nome, descrição e catálogo.</p>
            </div>
            <div class="p-4 bg-gray-50 rounded-xl border border-gray-200">
                <span class="text-[11px] font-bold text-green-700 uppercase tracking-wide">Pilar 2</span>
                <h3 class="font-bold text-gray-900 mt-1 mb-1">Distância</h3>
                <p class="text-xs text-gray-600">Proximidade física entre quem busca e o negócio — limitador que nenhum conteúdo derruba sozinho.</p>
            </div>
            <div class="p-4 bg-gray-50 rounded-xl border border-gray-200">
                <span class="text-[11px] font-bold text-green-700 uppercase tracking-wide">Pilar 3</span>
                <h3 class="font-bold text-gray-900 mt-1 mb-1">Destaque / Autoridade</h3>
                <p class="text-xs text-gray-600">Reputação — avaliações, fotos, comentários, links e presença consistente na web.</p>
            </div>
        </div>
    </section>

    {{-- Elegibilidade --}}
    <section id="elegibilidade" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 space-y-4 scroll-mt-20">
        <h2 class="text-lg font-bold text-gray-900 font-heading">✅ Quem pode ter Perfil da Empresa</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="p-4 bg-green-50 border border-green-200 rounded-xl">
                <div class="text-[11px] font-bold text-green-700 uppercase tracking-wide mb-2">Pode</div>
                <ul class="text-sm text-gray-700 space-y-1.5 list-disc list-inside">
                    <li>Qualquer negócio que atenda cliente presencialmente em uma localização.</li>
                    <li>Prestador que vai até o cliente (eletricista, encanador, diarista, guincho, personal trainer, fotógrafo, consultor, advogado, contador, veterinário móvel...).</li>
                </ul>
            </div>
            <div class="p-4 bg-red-50 border border-red-200 rounded-xl">
                <div class="text-[11px] font-bold text-red-700 uppercase tracking-wide mb-2">Não pode</div>
                <ul class="text-sm text-gray-700 space-y-1.5 list-disc list-inside">
                    <li>Negócio 100% online, afiliado, infoprodutor sem atendimento local.</li>
                    <li>E-commerce puro, empresa virtual — sem atendimento presencial em nenhum lugar.</li>
                </ul>
            </div>
        </div>
        <div class="p-3 bg-amber-50 border border-amber-200 rounded-lg text-xs text-amber-900">
            <strong>Regra de ouro:</strong> endereço falso ou informação incorreta pode suspender o perfil a qualquer momento.
            <strong>Mito comum:</strong> não é preciso alugar sala comercial — atender na casa do cliente já qualifica, seguindo as regras de área de atendimento (fator 8).
        </div>
    </section>

    {{-- Os 11 fatores --}}
    <section id="fatores" class="space-y-4 scroll-mt-20">
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
            <h2 class="text-lg font-bold text-gray-900 font-heading">📋 Os 11 fatores analisados pelo Google</h2>
            <p class="text-sm text-gray-600 mt-1">Pra cada fator: o que é analisado, como preencher, boas práticas, quando não usar, ações externas e o impacto nas buscas por IA.</p>
        </div>

        @php
        $fatores = [
            ['n' => '01', 't' => 'Categoria Primária e Secundárias',
             'analisado' => 'O ramo exato de atuação. Isoladamente, o fator técnico de maior peso no ranqueamento local.',
             'preencher' => 'Primária = exatamente o que a empresa é. Secundárias = o que ela também faz/vende.',
             'boas' => 'Seja ultraespecífico ("Restaurante Italiano", não só "Restaurante"). Limite secundárias a 3–5, estritamente verdadeiras.',
             'nao' => 'Nunca marque categoria que você não entrega no local (oficina mecânica não marca "Loja de Conveniência" só por vender óleo).',
             'ext' => 'Páginas dedicadas por categoria/serviço no site; mesma taxonomia em diretórios locais (TripAdvisor, Telelistas, Apontador).',
             'ia' => 'Categoria genérica faz a IA ignorar o negócio em buscas contextuais complexas ("onde almoçar massa artesanal sem glúten perto de mim").'],
            ['n' => '02', 't' => 'Nome da Empresa',
             'analisado' => 'Correspondência entre a busca do usuário e o nome oficial da ficha.',
             'preencher' => 'Nome Fantasia real — igual à fachada, notas fiscais e registros.',
             'boas' => 'Se o nome já contém o nicho ("Clínica Odonto Sorriso"), aproveitar.',
             'nao' => 'Nunca fazer keyword stuffing ("Padaria Bella Vista - Pão Quente, Café e Lanches Baratos") — gera suspensão manual ou algorítmica.',
             'ext' => 'Nome idêntico em todas as plataformas: redes sociais, CNPJ, site, agregadores.',
             'ia' => 'Nome com palavra extra que não existe na internet pública reduz o trust score.'],
            ['n' => '03', 't' => 'Avaliações — volume, nota, frequência e palavras',
             'analisado' => 'Quantidade de notas, média, constância de novos comentários, termos usados no texto do review.',
             'preencher' => 'Coleta ativa com cliente real, via link direto de avaliação.',
             'boas' => 'Responder 100% das avaliações em até 24–48h, usando termos locais e nomes de serviço nas respostas. Estimular o cliente a citar o serviço e postar foto.',
             'nao' => 'Nunca comprar avaliação falsa; nunca trocar review por desconto explícito; evitar disparar dezenas de pedidos no mesmo dia (ativa filtro de spam).',
             'ext' => 'Link de avaliação no pós-venda (WhatsApp, QR Code, e-mail transacional); coletar também no Reclame Aqui e Facebook.',
             'ia' => 'Critério de ouro — a IA lê o texto dos comentários pra responder dúvida subjetiva. Se vários clientes escrevem "freio rápido e honesto", a IA cita a empresa como resposta ideal.'],
            ['n' => '04', 't' => 'Catálogo de Produtos e Serviços',
             'analisado' => 'Itens/serviços comercializados, com descrição, foto e preço.',
             'preencher' => 'Cadastrar cada item com título claro, valor (ou faixa) e descrição detalhada.',
             'boas' => 'Usar as palavras-chave secundárias que o cliente digitaria; CTA direto pra página do produto no site.',
             'nao' => 'Não cadastrar produto sem estoque constante ou serviço descontinuado.',
             'ext' => 'URL dedicada por serviço no site, linkada direto da ficha.',
             'ia' => 'A IA extrai dado granular — "quanto custa limpeza de pele perto do metrô?" busca preço e detalhe direto no catálogo.'],
            ['n' => '05', 't' => 'Atributos da Empresa',
             'analisado' => 'Recursos e políticas: acessibilidade, Wi-Fi grátis, espaço kids, pet friendly, empresa de proprietária mulher, etc.',
             'preencher' => 'Marcar Sim/Não conforme a realidade do espaço.',
             'boas' => 'Revisar trimestralmente (o Google adiciona atributo novo com frequência); ser 100% honesto.',
             'nao' => 'Não marcar atributo irrelevante ou falso só pra tentar ganhar clique.',
             'ext' => 'Citar os mesmos atributos na página "Sobre" do site.',
             'ia' => 'Busca conversacional cheia de filtro em linguagem natural consulta a aba de atributos pro filtro exato.'],
            ['n' => '06', 't' => 'Descrição da Empresa',
             'analisado' => 'Texto institucional de até 750 caracteres — história, atuação, diferenciais.',
             'preencher' => 'Tom humano, diferencial nos primeiros 250 caracteres (antes do "ver mais").',
             'boas' => 'Citar bairros de atendimento e especialidades.',
             'nao' => 'Sem link (não é clicável), sem telefone (Google bloqueia), sem claim falso ("o melhor do Brasil" sem comprovação).',
             'ext' => 'Mesma base de texto nas redes sociais e na página "Quem Somos" do site.',
             'ia' => 'A IA usa a descrição pra entender a proposta de valor e gerar resumos descritivos.'],
            ['n' => '07', 't' => 'Fotos e Vídeos',
             'analisado' => 'Volume, qualidade, recência, e se vem do dono ou do cliente (UGC).',
             'preencher' => 'Fotos reais em alta resolução: fachada dia/noite, equipe trabalhando, produto, bastidores.',
             'boas' => 'Publicar semanalmente; incentivar cliente a postar foto junto da avaliação; sem filtro pesado.',
             'nao' => 'Nunca usar banco de imagem genérico — o Google Cloud Vision reconhece foto de stock e reduz a relevância por falta de autenticidade.',
             'ext' => 'Criar ambiente "instagramável" que instigue o cliente a fotografar e postar no Maps.',
             'ia' => 'Ferramentas multimodais (Gemini) "enxergam" as fotos — pode responder "mostre restaurante com área externa arborizada" lendo o conteúdo visual.'],
            ['n' => '08', 't' => 'Endereço, Geolocalização e Área de Cobertura',
             'analisado' => 'Coordenada exata do pino e distância física até quem busca.',
             'preencher' => 'Loja física: endereço completo, pino sobre a entrada. Atendimento em domicílio: ocultar endereço, cadastrar Área de Cobertura (raio ou cidades).',
             'boas' => 'Evitar área de cobertura exagerada (não marcar o estado inteiro sem capacidade real de atender com agilidade).',
             'nao' => 'Nunca usar caixa postal ou coworking virtual sem atendimento presencial — o Google já baniu fichas em massa por isso.',
             'ext' => 'Consistência NAP (Nome, Endereço, Telefone) idêntica no rodapé do site, guias locais e redes.',
             'ia' => 'A IA calcula raio de deslocamento/tempo de trânsito em tempo real ("onde trocar óleo que dê pra ir a pé daqui?").'],
            ['n' => '09', 't' => 'Google Posts (Atualizações)',
             'analisado' => 'Frequência de novidade/oferta/evento — sinal de empresa ativa.',
             'preencher' => 'Texto objetivo, imagem atraente, botão de ação (WhatsApp ou página de destino).',
             'boas' => 'Ao menos 1 post/semana; destacar promoção com data de início/fim definida.',
             'nao' => 'Não repetir a mesma imagem/texto toda semana — o Google trata como spam de baixa relevância.',
             'ext' => 'Reaproveitar os melhores conteúdos do blog/Instagram no formato de post do Google.',
             'ia' => 'Post recente vira fonte em tempo real ("qual restaurante tem música ao vivo hoje?" — se houver post sobre a atração, a IA traz a ficha no topo).'],
            ['n' => '10', 't' => 'Perguntas e Respostas (Q&A)',
             'analisado' => 'Dúvidas postadas por usuário e respondidas publicamente.',
             'preencher' => 'O próprio dono faz e responde as 5–10 perguntas mais comuns, com a conta oficial.',
             'boas' => 'Monitorar dúvida real que chega no WhatsApp e transformar em Q&A oficial.',
             'nao' => 'Não deixar pergunta de cliente sem resposta do dono — concorrente pode responder errado no lugar.',
             'ext' => 'Usar o mesmo FAQ como base de conteúdo no site.',
             'ia' => 'A IA adora o formato pergunta-resposta pra gerar resumo instantâneo em consulta conversacional longa.'],
            ['n' => '11', 't' => 'Site Vinculado (SEO On-Page e Local)',
             'analisado' => 'O site oficial linkado no botão "Website" da ficha.',
             'preencher' => 'Home pra negócio de unidade única; página da filial específica pra rede com várias unidades.',
             'boas' => 'Dados estruturados Schema.org (LocalBusiness); alta velocidade no mobile; conteúdo citando cidade, bairro e serviços.',
             'nao' => 'Não apontar pra página quebrada, lenta, ou rede social (só usar rede se não tiver site de jeito nenhum).',
             'ext' => 'Backlinks de sites da mesma cidade — jornal local, câmara de comércio, blog de turismo/gastronomia.',
             'ia' => 'A IA varre a ficha e o site juntos — dado estruturado claro dá o dobro de segurança pra recomendar a empresa no AI Overview.'],
        ];
        @endphp

        @foreach($fatores as $f)
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
            <div class="flex items-baseline gap-3 mb-3">
                <span class="text-xl font-bold text-green-700 font-heading">{{ $f['n'] }}</span>
                <h3 class="text-base font-bold text-gray-900">{{ $f['t'] }}</h3>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-3">
                <div>
                    <div class="text-[10px] font-bold text-gray-400 uppercase tracking-wide mb-1">O que é analisado</div>
                    <p class="text-sm text-gray-700">{{ $f['analisado'] }}</p>
                </div>
                <div>
                    <div class="text-[10px] font-bold text-gray-400 uppercase tracking-wide mb-1">Como preencher</div>
                    <p class="text-sm text-gray-700">{{ $f['preencher'] }}</p>
                </div>
            </div>
            <div class="space-y-2">
                <div class="pl-3 border-l-2 border-green-400 text-sm text-gray-700"><span class="block text-[10px] font-bold text-green-700 uppercase tracking-wide">Boas práticas</span>{{ $f['boas'] }}</div>
                <div class="pl-3 border-l-2 border-red-400 text-sm text-gray-700"><span class="block text-[10px] font-bold text-red-600 uppercase tracking-wide">Quando não usar</span>{{ $f['nao'] }}</div>
                <div class="pl-3 border-l-2 border-amber-400 text-sm text-gray-700"><span class="block text-[10px] font-bold text-amber-700 uppercase tracking-wide">Ações externas</span>{{ $f['ext'] }}</div>
            </div>
            <div class="mt-3 p-3 bg-purple-50 border border-purple-100 rounded-lg">
                <span class="block text-[10px] font-bold text-purple-700 uppercase tracking-wide mb-1">Impacto em IA</span>
                <p class="text-sm text-gray-700">{{ $f['ia'] }}</p>
            </div>
        </div>
        @endforeach

        <div class="bg-blue-50 border border-blue-100 rounded-2xl p-5">
            <div class="text-[11px] font-bold text-blue-700 uppercase tracking-wide mb-2">Critérios complementares</div>
            <p class="text-sm text-gray-700">Site com <strong>página própria por serviço</strong> (não só por bairro); consistência de nome (NAP) também em Instagram e Facebook; separar conteúdo por <strong>nível de consciência</strong> da busca — educativo pra quem ainda pesquisa, CTA direto pra quem já está pronto pra comprar (nosso foco). Um perfil forte também reduz o custo por clique em Google Ads — consequência, não critério de score.</p>
        </div>
    </section>

    {{-- Regras invisíveis --}}
    <section id="invisiveis" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 space-y-4 scroll-mt-20">
        <h2 class="text-lg font-bold text-gray-900 font-heading">🕵️ Regras invisíveis do algoritmo</h2>
        <p class="text-sm text-gray-600">Comportamentos que não aparecem em nenhum formulário de preenchimento — e costumam ser a diferença entre ficar na 1ª posição ou sumir do mapa.</p>

        <div class="divide-y divide-gray-100">
            <div class="py-4 flex gap-4">
                <span class="w-8 h-8 rounded-full bg-red-100 text-red-700 font-bold text-sm flex items-center justify-center flex-shrink-0">1</span>
                <div><h4 class="font-bold text-gray-900 text-sm mb-1">Efeito "horário de funcionamento"</h4><p class="text-sm text-gray-600">O Google rebaixa a ficha nas buscas quando o status é "Fechado" no momento da pesquisa — mesmo com mais avaliação que o concorrente aberto. Nunca deixar horário de feriado sem confirmação: o aviso amarelo sem resposta reduz a confiança do algoritmo na ficha.</p></div>
            </div>
            <div class="py-4 flex gap-4">
                <span class="w-8 h-8 rounded-full bg-red-100 text-red-700 font-bold text-sm flex items-center justify-center flex-shrink-0">2</span>
                <div><h4 class="font-bold text-gray-900 text-sm mb-1">Proximidade é um limitador físico</h4><p class="text-sm text-gray-600">Um concorrente razoável a 500m do usuário ganha de uma ficha ótima a 10km. Pra negócio físico, a briga real de topo é no próprio bairro; pra cliente mais distante, o caminho é SEO local no site ou Google Ads Local.</p></div>
            </div>
            <div class="py-4 flex gap-4">
                <span class="w-8 h-8 rounded-full bg-red-100 text-red-700 font-bold text-sm flex items-center justify-center flex-shrink-0">3</span>
                <div><h4 class="font-bold text-gray-900 text-sm mb-1">Edições sugeridas por terceiros</h4><p class="text-sm text-gray-600">Qualquer pessoa — inclusive concorrente mal-intencionado — pode sugerir alteração de telefone, horário, categoria, ou marcar como "fechado permanentemente". Se aceita, aparece aviso laranja no painel; se não for rejeitada a tempo, vira definitiva. Checar o painel pelo menos 1x/semana.</p></div>
            </div>
            <div class="py-4 flex gap-4">
                <span class="w-8 h-8 rounded-full bg-red-100 text-red-700 font-bold text-sm flex items-center justify-center flex-shrink-0">4</span>
                <div>
                    <h4 class="font-bold text-gray-900 text-sm mb-1">Suspensão repentina</h4>
                    <ul class="text-sm text-gray-600 list-disc list-inside space-y-1">
                        <li>Mudar nome, endereço e categoria de uma vez só suspeita de invasão — mudar 1 dado por vez, com dias de intervalo.</li>
                        <li>Coworking sem sala privativa/placa/recepção reprova na verificação por vídeo.</li>
                        <li>Duas fichas no mesmo endereço com atividade parecida gera banimento por duplicidade.</li>
                    </ul>
                </div>
            </div>
            <div class="py-4 flex gap-4">
                <span class="w-8 h-8 rounded-full bg-red-100 text-red-700 font-bold text-sm flex items-center justify-center flex-shrink-0">5</span>
                <div><h4 class="font-bold text-gray-900 text-sm mb-1">Sinais comportamentais (CTR)</h4><p class="text-sm text-gray-600">O Google mede em tempo real quem clica em Ligar, Rotas, visita o site ou navega pelas fotos. Ficha com foto boa e CTA claro tem CTR maior — mais interação consolida a posição no topo.</p></div>
            </div>
            <div class="py-4 flex gap-4">
                <span class="w-8 h-8 rounded-full bg-red-100 text-red-700 font-bold text-sm flex items-center justify-center flex-shrink-0">6</span>
                <div><h4 class="font-bold text-gray-900 text-sm mb-1">GEO — Generative Engine Optimization</h4><p class="text-sm text-gray-600">Pra IA recomendar com frequência: consistência de marca (a IA cruza a ficha com blog local, jornal de bairro, Instagram, LinkedIn, Reclame Aqui) e <strong>menções sem link</strong> — basta citar o nome da empresa recomendando o serviço, não precisa ser link clicável.</p></div>
            </div>
        </div>
    </section>

    {{-- Concorrência --}}
    <section id="concorrencia" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 space-y-4 scroll-mt-20">
        <h2 class="text-lg font-bold text-gray-900 font-heading">⚔️ Quando um concorrente aparece na frente</h2>
        <p class="text-sm text-gray-600">Significa que, pra aquele termo/local, o algoritmo avaliou que ele tem mais Relevância, Proximidade ou Destaque. Plano de ação em 4 etapas.</p>

        <div class="space-y-4">
            <div class="p-4 bg-gray-50 rounded-xl border border-gray-200">
                <div class="text-[11px] font-bold text-gray-500 uppercase tracking-wide mb-1">Etapa 1</div>
                <h4 class="font-bold text-gray-900 text-sm mb-2">Auditoria do concorrente (aba anônima, ponto a ponto)</h4>
                <ul class="text-sm text-gray-700 list-disc list-inside space-y-1">
                    <li>Categoria primária — a mesma que a sua, ou uma com mais correspondência à busca?</li>
                    <li>Nome — tem palavra-chave extra que não é o registro oficial?</li>
                    <li>Avaliações — quantas a mais, nota média, frequência, citam o serviço no texto?</li>
                    <li>Site vinculado — abre rápido? tem página dedicada ao serviço + bairro?</li>
                    <li>Fotos e posts — atualização semanal? muita foto de cliente?</li>
                </ul>
            </div>
            <div class="p-4 bg-gray-50 rounded-xl border border-gray-200">
                <div class="text-[11px] font-bold text-gray-500 uppercase tracking-wide mb-1">Etapa 2</div>
                <h4 class="font-bold text-gray-900 text-sm mb-2">Defesa — se ele estiver burlando as regras</h4>
                <p class="text-sm text-gray-700 mb-2"><strong>Keyword stuffing no nome:</strong> "Sugerir uma alteração" → "Alterar nome" e corrigir pro nome de fachada real. Se o Google não aceitar, existe o Formulário de Denúncia de Correção de Negócios, anexando foto da fachada ou CNPJ.</p>
                <p class="text-sm text-gray-700"><strong>Empresa fantasma/falsa:</strong> dá pra sugerir remoção por ser inexistente.</p>
            </div>
            <div class="p-4 bg-gray-50 rounded-xl border border-gray-200">
                <div class="text-[11px] font-bold text-gray-500 uppercase tracking-wide mb-1">Etapa 3</div>
                <h4 class="font-bold text-gray-900 text-sm mb-2">Ataque — se ele estiver dentro das regras e vencendo por mérito</h4>
                <ol class="text-sm text-gray-700 list-decimal list-inside space-y-1.5">
                    <li>Acelerar o ritmo de <strong>novas</strong> avaliações — o Google valoriza recência mais que volume total; 80 avaliações recebendo 5/semana passa na frente de 300 paradas no tempo.</li>
                    <li>Alinhar a categoria primária à do líder, se refletir melhor a busca — impacto em menos de 48h.</li>
                    <li>Completar catálogo e atributos que ele não preencheu.</li>
                    <li>Otimizar a página de destino: título com serviço + cidade, endereço idêntico no rodapé, mapa incorporado.</li>
                </ol>
            </div>
            <div class="p-4 bg-gray-50 rounded-xl border border-gray-200">
                <div class="text-[11px] font-bold text-gray-500 uppercase tracking-wide mb-1">Etapa 4</div>
                <h4 class="font-bold text-gray-900 text-sm mb-2">Avaliar o fator Distância</h4>
                <p class="text-sm text-gray-700">Testar a busca de pontos diferentes da cidade. Se o concorrente só ganha de dentro do bairro dele, é proximidade natural — não é erro seu. Nesses pontos cegos, a saída mais rápida é Google Ads Local (Smart Campaigns), que coloca a empresa como patrocinado em destaque mesmo no território dele.</p>
            </div>
        </div>

        <div class="p-3 bg-blue-50 border border-blue-100 rounded-lg text-xs text-blue-900">
            <strong>Nota pro roadmap:</strong> isso sugere uma dimensão extra pra "Análise de qualidade da ficha" — não só um score absoluto, mas uma comparação direta com o concorrente que está na frente pro mesmo termo/local.
        </div>
    </section>

    {{-- Schema.org --}}
    <section id="schema" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 space-y-3 scroll-mt-20">
        <h2 class="text-lg font-bold text-gray-900 font-heading">🧩 Exemplo de dados estruturados</h2>
        <p class="text-sm text-gray-600">Referente ao fator 11 — modelo de <code class="text-xs bg-gray-100 px-1.5 py-0.5 rounded">JSON-LD</code> pra inserir no <code class="text-xs bg-gray-100 px-1.5 py-0.5 rounded">&lt;head&gt;</code> da página do site vinculada à ficha.</p>
        <div class="overflow-x-auto rounded-xl bg-gray-900 p-4">
            <pre class="text-xs text-gray-200 font-mono leading-relaxed">&lt;script type="application/ld+json"&gt;
{
  "@context": "https://schema.org",
  "@type": "LocalBusiness",
  "name": "Nome Fantasia da Sua Empresa",
  "image": "https://seusite.com.br/foto-fachada.jpg",
  "@id": "https://seusite.com.br",
  "url": "https://seusite.com.br",
  "telephone": "+55-21-99999-9999",
  "address": {
    "@type": "PostalAddress",
    "streetAddress": "Rua Exemplo, 123, Sala 401",
    "addressLocality": "Rio de Janeiro",
    "addressRegion": "RJ",
    "postalCode": "20000-000",
    "addressCountry": "BR"
  },
  "geo": {
    "@type": "GeoCoordinates",
    "latitude": -22.906847,
    "longitude": -43.172896
  },
  "openingHoursSpecification": [{
    "@type": "OpeningHoursSpecification",
    "dayOfWeek": ["Monday","Tuesday","Wednesday","Thursday","Friday"],
    "opens": "08:00",
    "closes": "18:00"
  }],
  "sameAs": [
    "https://www.instagram.com/suaempresa",
    "https://www.facebook.com/suaempresa",
    "https://br.linkedin.com/company/suaempresa"
  ]
}
&lt;/script&gt;</pre>
        </div>
        <p class="text-xs text-gray-500"><code class="bg-gray-100 px-1 rounded">sameAs</code> é o que amarra a consistência de marca (fatores 2/8, e GEO acima) — ligando ficha e site às redes oficiais pra IA cruzar os dados.</p>
    </section>

    {{-- Ferramentas --}}
    <section id="ferramentas" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 space-y-4 scroll-mt-20">
        <h2 class="text-lg font-bold text-gray-900 font-heading">🛠️ Ferramentas oficiais e gratuitas</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div class="p-4 bg-gray-50 rounded-xl border border-gray-200">
                <div class="font-bold text-gray-900 text-sm mb-1">Diretrizes do Google Perfil de Empresa</div>
                <p class="text-xs text-gray-600">Regras oficiais sobre nome aceito, categoria e conduta permitida — evita banimento.</p>
            </div>
            <div class="p-4 bg-gray-50 rounded-xl border border-gray-200">
                <div class="font-bold text-gray-900 text-sm mb-1">Formulário de Denúncia de Negócios</div>
                <p class="text-xs text-gray-600">Canal oficial pra denunciar concorrente com nome falso, endereço fantasma ou spam agressivo.</p>
            </div>
            <div class="p-4 bg-gray-50 rounded-xl border border-gray-200">
                <div class="font-bold text-gray-900 text-sm mb-1">Google Rich Results Test</div>
                <p class="text-xs text-gray-600">Valida se os dados estruturados (LocalBusiness) do site funcionam.</p>
            </div>
            <div class="p-4 bg-gray-50 rounded-xl border border-gray-200">
                <div class="font-bold text-gray-900 text-sm mb-1">Google Cloud Vision API (demo)</div>
                <p class="text-xs text-gray-600">Testa como a IA do Google lê objeto, texto e tema nas fotos antes de publicar.</p>
            </div>
        </div>
    </section>

    {{-- Roteiro --}}
    <section id="roteiro" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 space-y-3 scroll-mt-20">
        <h2 class="text-lg font-bold text-gray-900 font-heading">✔️ Roteiro de 7 passos pra revisar uma ficha</h2>
        <div class="divide-y divide-gray-100">
            <div class="py-3 flex items-start gap-3"><span class="w-5 h-5 mt-0.5 border-2 border-gray-300 rounded flex-shrink-0"></span><p class="text-sm text-gray-700"><strong>Revisar categoria</strong> — confirmar a principal, remover secundárias que não representam o negócio.</p></div>
            <div class="py-3 flex items-start gap-3"><span class="w-5 h-5 mt-0.5 border-2 border-gray-300 rounded flex-shrink-0"></span><p class="text-sm text-gray-700"><strong>Revisar serviços</strong> — listar as entregas reais, na linguagem que o cliente reconhece.</p></div>
            <div class="py-3 flex items-start gap-3"><span class="w-5 h-5 mt-0.5 border-2 border-gray-300 rounded flex-shrink-0"></span><p class="text-sm text-gray-700"><strong>Corrigir descrição e perguntas</strong> — transformar dúvida frequente em resposta clara no perfil.</p></div>
            <div class="py-3 flex items-start gap-3"><span class="w-5 h-5 mt-0.5 border-2 border-gray-300 rounded flex-shrink-0"></span><p class="text-sm text-gray-700"><strong>Publicar fotos reais</strong> — equipe, processo, ambiente e serviço em ação.</p></div>
            <div class="py-3 flex items-start gap-3"><span class="w-5 h-5 mt-0.5 border-2 border-gray-300 rounded flex-shrink-0"></span><p class="text-sm text-gray-700"><strong>Solicitar avaliações</strong> — priorizando frequência, autenticidade e contexto.</p></div>
            <div class="py-3 flex items-start gap-3"><span class="w-5 h-5 mt-0.5 border-2 border-gray-300 rounded flex-shrink-0"></span><p class="text-sm text-gray-700"><strong>Revisar site e informações</strong> — consistência e sinais de autoridade.</p></div>
            <div class="py-3 flex items-start gap-3"><span class="w-5 h-5 mt-0.5 border-2 border-gray-300 rounded flex-shrink-0"></span><p class="text-sm text-gray-700"><strong>Medir posição e contatos</strong> — comparar visibilidade, ligações e mensagens ao longo do tempo.</p></div>
        </div>
    </section>

    <p class="text-center text-xs text-gray-400 pt-2">Apostila interna Lead Certo — base para a feature "Análise de qualidade da ficha" do módulo GMB.</p>
</div>
@endsection
