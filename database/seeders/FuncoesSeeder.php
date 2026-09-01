<?php

namespace Database\Seeders;

use App\Models\Cargo;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class FuncoesSeeder extends Seeder
{
    public function run(): void
    {
        $funcoes = [
            // 1. Suporte
            [
                'nome'                  => 'Gerente de Suporte',
                'tipo'                  => 'suporte',
                'icone'                 => '🎧',
                'descricao'             => 'Atendimento dedicado ao cliente da plataforma, triagem de chamados, suporte a dúvidas e coleta de feedbacks operacionais.',
                'descricao_cliente'     => 'Vou te ajudar a entender sobre cada detalhe da nossa ferramenta e te guio no passo a passo das configurações que não souber fazer.',
                'detalhes_escopo'       => "1. Recepção e atendimento em primeiro nível de chamados e dúvidas de clientes da plataforma.\n2. Leitura, resposta e triagem de e-mails de suporte (adrianaaviag@gmail.com).\n3. Coleta de sugestões de melhorias e análise de viabilidade técnica/negócio para priorização da equipe.\n4. Orientação passo a passo sobre configurações de WhatsApp, Kanban, regras de roteamento e integrações.",
                'ferramentas'           => 'Canal /equipe/suporte, Uazapi WhatsApp, Gmail SMTP/IMAP, Painel de Feedbacks',
                'kpis'                  => 'Tempo médio de primeira resposta (< 15 min), Taxa de resolução de dúvidas, Índice de satisfação (CSAT)',
                'diretriz_ia'           => 'Atue com empatia, linguagem acolhedora, sem jargões excessivos e com foco total em resolver o problema do cliente no menor tempo possível.',
                'cargo_pai_id'          => null,
                'ordem'                 => 1,
                'visivel_para_clientes' => true,
            ],
            // 2. Marketing
            [
                'nome'                  => 'Diretora de Marketing',
                'tipo'                  => 'marketing',
                'icone'                 => '📢',
                'descricao'             => 'Gestão estratégica de marketing, Google Meu Negócio (GMB), personas de IA, campanhas de aquisição e templates de avaliação.',
                'descricao_cliente'     => 'Responsável pela visibilidade, branding, presença local e atração de novos clientes para o negócio.',
                'detalhes_escopo'       => "1. Gestão e agendamento de postagens no Google Meu Negócio (GMB) e templates de avaliação para atração local.\n2. Coordenação dos gestores especialistas (Tráfego, Criação, Copywriting e SEO).\n3. Definição do tom de voz e calibração das SDR Personas por nicho de atuação.\n4. Planejamento de campanhas de atração e presença digital multicanal.",
                'ferramentas'           => 'Google Business Profile API, Módulo GMB, Gemini Pro, Meta Business Suite',
                'kpis'                  => 'Visualizações e ações no GMB, Volume de avaliações 5 estrelas geradas, CPL geral, Número de novos leads',
                'diretriz_ia'           => 'Pensamento analítico e estratégico de crescimento. Priorizar consistência de marca, relevância local e eficiência de aquisição.',
                'cargo_pai_id'          => null,
                'ordem'                 => 2,
                'visivel_para_clientes' => false,
            ],
            // 3. Comercial
            [
                'nome'                  => 'Gestor Comercial',
                'tipo'                  => 'comercial',
                'icone'                 => '📊',
                'descricao'             => 'Supervisão do funil de vendas, análise do Kanban em tempo real, monitoramento de conversas, gargalos de atendimento e sugestões de ação.',
                'descricao_cliente'     => 'Supervisiona a performance comercial, agilidade no atendimento e taxa de conversão do time de vendas.',
                'detalhes_escopo'       => "1. Monitoramento coluna a coluna do Kanban em tempo real em todas as etapas do funil.\n2. Identificação proativa de leads parados sem resposta ou em risco de abandono.\n3. Avaliação da qualidade das conversas dos vendedores humanos e das respostas da IA.\n4. Mapeamento dos motivos de desfecho/perda e sugestão de próximas ações para destravar negociações.",
                'ferramentas'           => 'Kanban Lead Certo, GestorKanbanService, Relatórios de Desfecho, Alertas Internos',
                'kpis'                  => 'Taxa de conversão por etapa, Tempo médio de atendimento, % de leads recuperados após alerta de estagnação',
                'diretriz_ia'           => 'Foco obsessivo em conversão e velocidade de resposta. Detectar objeções não respondidas e sinalizar oportunidades imediatas.',
                'cargo_pai_id'          => null,
                'ordem'                 => 3,
                'visivel_para_clientes' => false,
            ],
            // 4. Tráfego (Subordinado ao Marketing)
            [
                'nome'                  => 'Gestor de Tráfego',
                'tipo'                  => 'marketing',
                'icone'                 => '🎯',
                'descricao'             => 'Mídia paga (Google Ads, Meta Ads, TikTok Ads), configuração técnica (pixels, UTMs, conversões), otimização de campanhas e orçamento.',
                'descricao_cliente'     => 'Especialista em compra de tráfego qualificado e retorno sobre investimento em anúncios.',
                'detalhes_escopo'       => "1. Configuração e gestão técnica de anúncios patrocinados no Google Ads, Meta Ads e TikTok.\n2. Parametrização de UTMs, pixels de conversão e webhooks de eventos para rastreamento de ponta a ponta.\n3. Testes A/B de públicos, segmentações geográficas e distribuição orçamentária por canal.\n4. Otimização contínua do custo por lead (CPL) e custo por aquisição (CPA).",
                'ferramentas'           => 'Google Ads, Meta Ads Manager, TikTok Ads, UTM Builder Lead Certo',
                'kpis'                  => 'CPL (Custo por Lead), ROAS, Taxa de cliques (CTR), Taxa de conversão da landing page',
                'diretriz_ia'           => 'Análise puramente quantitativa de métricas. Cortar rápido criativos e públicos com ROI negativo e escalar os vencedores.',
                'cargo_pai_nome'        => 'Diretora de Marketing',
                'ordem'                 => 4,
                'visivel_para_clientes' => false,
            ],
            // 5. Criação (Subordinado ao Marketing)
            [
                'nome'                  => 'Gestor de Criação',
                'tipo'                  => 'marketing',
                'icone'                 => '🎨',
                'descricao'             => 'Produção de criativos publicitários (imagens, vídeos curtos e peças visuais) através de ferramentas e inteligência artificial.',
                'descricao_cliente'     => 'Criação de anúncios visuais de alto impacto para campanhas publicitárias.',
                'detalhes_escopo'       => "1. Geração de imagens promocionais e capas persuasivas via IA e templates validados.\n2. Produção e edição de vídeos curtos em formato vertical (Reels, TikTok, Shorts) para anúncios.\n3. Adaptação visual dos criativos por formato de canal e nicho do cliente.\n4. Criação de variações de criativos para testes A/B de tráfego pago.",
                'ferramentas'           => 'Kairogen MCP, Covercut API, Canva / Figma, Stable Diffusion / Imagen',
                'kpis'                  => 'Taxa de retenção nos primeiros 3 segundos de vídeo, CTR dos criativos visuais, Volume de peças produzidas',
                'diretriz_ia'           => 'Criatividade alinhada a gatilhos visuais de atenção (padrão de interrupção, contraste de cores e legibilidade instantânea).',
                'cargo_pai_nome'        => 'Diretora de Marketing',
                'ordem'                 => 5,
                'visivel_para_clientes' => false,
            ],
            // 6. Copywriting (Subordinado ao Marketing)
            [
                'nome'                  => 'Gestor de Copywriting',
                'tipo'                  => 'marketing',
                'icone'                 => '✍️',
                'descricao'             => 'Textos persuasivos para anúncios, landing pages, mensagens de WhatsApp, e-mails e quebra de objeções por nicho.',
                'descricao_cliente'     => 'Redação persuasiva de mensagens e ofertas pensadas para converter visitantes em clientes.',
                'detalhes_escopo'       => "1. Redação de headlines de alto impacto e chamadas para ação (CTAs) em anúncios e páginas.\n2. Construção de roteiros de abordagem e quebra de objeções para conversas no WhatsApp.\n3. Elaboração de textos para landing pages e sequências automáticas de follow-up.\n4. Criação de variações spintax para humanização de envios em lote.",
                'ferramentas'           => 'OpenRouter, Gemini Pro, Spintax Engine Lead Certo, Prompt Master 8Ps',
                'kpis'                  => 'Taxa de resposta no WhatsApp, Taxa de cliques em links (CTR), Conversão de páginas de vendas',
                'diretriz_ia'           => 'Escrever com clareza, concisão e foco nas dores reais e desejos do consumidor, evitando clichês corporativos vazios.',
                'cargo_pai_nome'        => 'Diretora de Marketing',
                'ordem'                 => 6,
                'visivel_para_clientes' => false,
            ],
            // 7. SEO (Subordinado ao Marketing)
            [
                'nome'                  => 'Gestor de SEO',
                'tipo'                  => 'marketing',
                'icone'                 => '🔍',
                'descricao'             => 'Tráfego orgânico via motores de busca, pesquisa de palavras-chave, otimização de páginas, blogs e Search Console.',
                'descricao_cliente'     => 'Posicionamento orgânico nas buscas do Google e atração de leads sem custo por clique.',
                'detalhes_escopo'       => "1. Pesquisa e mapeamento de palavras-chave comerciais e locais de alta intenção de compra.\n2. Otimização on-page (títulos, meta-tags, heading tags, dados estruturados Schema.org).\n3. Monitoramento de indexação e consultas no Google Search Console.\n4. Produção de artigos de blog e páginas de cidades/bairros orientadas a SEO local.",
                'ferramentas'           => 'Google Search Console, Google Trends, Ubersuggest / Ahrefs, Blog Engine',
                'kpis'                  => 'Posição média nas palavras-chave alvo, Cliques orgânicos mensais, Páginas indexadas com sucesso',
                'diretriz_ia'           => 'Priorizar intenção de busca local e relevância semântica, produzindo conteúdo útil, direto e perfeitamente estruturado.',
                'cargo_pai_nome'        => 'Diretora de Marketing',
                'ordem'                 => 7,
                'visivel_para_clientes' => false,
            ],
            // 8. Inteligência / Supervisor Geral
            [
                'nome'                  => 'Orquestrador Geral IA',
                'tipo'                  => 'inteligencia',
                'icone'                 => '🧠',
                'descricao'             => 'Supervisor central com Gemini Pro que monitora o SaaS, conformidade de regras, qualidade de textos e relatórios executivos.',
                'descricao_cliente'     => 'Orquestrador inteligente de processos e auditoria contínua de qualidade de todo o sistema.',
                'detalhes_escopo'       => "1. Monitoramento transversal de conformidade e integridade dos dados da plataforma.\n2. Auditoria da qualidade de textos gerados por outras IAs e mensagens automáticas.\n3. Consolidação de dados e geração de relatórios executivos para a franqueadora.\n4. Supervisão dos 5 mentores setoriais e alerta proativo de anomalias operacionais.",
                'ferramentas'           => 'Gemini Pro API, Audit Log Engine, Scheduler de Relatórios, Console de Comando',
                'kpis'                  => 'Índice de conformidade de regras (> 99%), Precisão dos relatórios executivos, Tempo de detecção de anomalias',
                'diretriz_ia'           => 'Visão holística, rigor analítico e imparcialidade. Auditar criticamente sem assumir premissas não validadas.',
                'cargo_pai_id'          => null,
                'ordem'                 => 8,
                'visivel_para_clientes' => false,
            ],
            // 9. Mentor 01 - Prospecção
            [
                'nome'                  => 'Mentor 01 — Prospecção & Mineração',
                'tipo'                  => 'mentor',
                'icone'                 => '⛏️',
                'descricao'             => 'Mineração ativa de contatos (Google Maps, redes sociais, formulários), aquisição de leads frios e controle de CPL.',
                'descricao_cliente'     => 'Especialista em geração contínua de novos contatos e alimentação de topo de funil.',
                'detalhes_escopo'       => "1. Gestão dos fluxos de mineração de contatos em fontes públicas e redes sociais.\n2. Classificação de contatos brutos e transformação em leads com interesse inicial.\n3. Monitoramento do custo por contato minerado e taxa de resposta da abordagem fria.\n4. Relatório periódico de performance dos canais de aquisição de topo de funil.",
                'ferramentas'           => 'n8n Mineradores, Extratores GMB/Instagram/Facebook, Módulo de Campanhas',
                'kpis'                  => 'Volume de novos contatos minerados por dia, Taxa de conversão Contato → Lead, Custo unitário de extração',
                'diretriz_ia'           => 'Identificar rapidamente padrões de resposta positiva e calibrar parâmetros de busca geográfica e nicho.',
                'cargo_pai_id'          => null,
                'ordem'                 => 9,
                'visivel_para_clientes' => false,
            ],
            // 10. Mentor 02 - Comercial 1
            [
                'nome'                  => 'Mentor 02 — Comercial 1 (Primeira Venda)',
                'tipo'                  => 'mentor',
                'icone'                 => '🤝',
                'descricao'             => 'Conversão rápida de lead em primeiro comprador. Diagnóstico de ofertas, quebra de objeções e avaliação de fechamentos.',
                'descricao_cliente'     => 'Estratégias de fechamento rápido para transformar interessados em clientes pagantes.',
                'detalhes_escopo'       => "1. Foco exclusivo na transformação de lead em cliente através da primeira venda.\n2. Diagnóstico aprofundado das objeções que impedem o fechamento inicial.\n3. Avaliação da força da oferta principal (preço, prazo, facilidade de pagamento).\n4. Treinamento e sugestão de scripts de fechamento para os vendedores da ponta.",
                'ferramentas'           => 'Histórico de Conversas Kanban, Matriz de Objeções, IA de Fechamento',
                'kpis'                  => 'Taxa de conversão Lead → Primeira Compra, Tempo médio de ciclo até a primeira compra',
                'diretriz_ia'           => 'Agressividade saudável orientada a fechamento rápido e resolução imediata da hesitação de compra.',
                'cargo_pai_id'          => null,
                'ordem'                 => 10,
                'visivel_para_clientes' => false,
            ],
            // 11. Mentor 03 - Comercial 2
            [
                'nome'                  => 'Mentor 03 — Comercial 2 (LTV & Recorrência)',
                'tipo'                  => 'mentor',
                'icone'                 => '🔄',
                'descricao'             => 'Vendas contínuas para clientes da base, gestão da esteira de produtos, predição de recompra e aumento do LTV.',
                'descricao_cliente'     => 'Multiplicação do faturamento através de recompra e esteira contínua de produtos.',
                'detalhes_escopo'       => "1. Acompanhamento do histórico de compras de cada cliente após a primeira transação.\n2. Identificação do momento ideal para ofertas de upsell, cross-sell e recompra.\n3. Sugestão de novos produtos e serviços baseados nos desejos expressos da base.\n4. Automação de campanhas de reativação para clientes inativos.",
                'ferramentas'           => 'CRM de Clientes, Linha do Tempo de Pedidos, Gatilhos de Recompra',
                'kpis'                  => 'LTV (Lifetime Value), Taxa de recompra em 30/60/90 dias, Ticket médio da base',
                'diretriz_ia'           => 'Cultivar relacionamento de confiança e antecipar necessidades complementares do cliente já conquistado.',
                'cargo_pai_id'          => null,
                'ordem'                 => 11,
                'visivel_para_clientes' => false,
            ],
            // 12. Mentor 04 - Pós-Venda
            [
                'nome'                  => 'Mentor 04 — Pós-Venda & Indicações',
                'tipo'                  => 'mentor',
                'icone'                 => '⭐',
                'descricao'             => 'Pesquisa de motivos de compra, fidelização e máquina automatizada de extração de indicações de compradores.',
                'descricao_cliente'     => 'Geração de novos contatos qualificados a partir da indicação de clientes satisfeitos.',
                'detalhes_escopo'       => "1. Contato com clientes recentes para capturar o motivo da escolha e nível de satisfação.\n2. Extração sistemática de 10 a 50 contatos de amigos/familiares indicados pelo cliente.\n3. Acionamento dos indicados com abordagem personalizada referenciando o indicador.\n4. Criação de ofertas exclusivas de indicação com validade de escassez (ex: 48h).",
                'ferramentas'           => 'Módulo de Indicações, Mensagens de Boas-Vindas Pós-Venda, Automação de Indicação',
                'kpis'                  => 'Volume de indicações geradas por cliente, Taxa de conversão de contatos indicados, NPS',
                'diretriz_ia'           => 'Transformar clientes satisfeitos em embaixadores ativos da marca através de abordagem calorosa e incentivos claros.',
                'cargo_pai_id'          => null,
                'ordem'                 => 12,
                'visivel_para_clientes' => false,
            ],
            // 13. Mentor 05 - Recuperação
            [
                'nome'                  => 'Mentor 05 — Recuperação & Troca',
                'tipo'                  => 'mentor',
                'icone'                 => '🎁',
                'descricao'             => 'Mapeamento de motivos de não-compra, recuperação de oportunidades perdidas e incentivo a indicações via cashback/benefícios.',
                'descricao_cliente'     => 'Resgate de negócios não fechados e transformação de objeções em novas conexões.',
                'detalhes_escopo'       => "1. Mapeamento sistemático do motivo pelo qual o lead não comprou (preço, prazo, momento).\n2. Criação de ofertas de resgate adaptadas ao motivo identificado.\n3. Sistema de troca por indicação: o lead indica contatos e ganha descontos/cashback progressivo.\n4. Reaquecimento de leads dormentes com novas condições comerciais.",
                'ferramentas'           => 'Módulo de Perdas, Motor de Cashback/Indicação, Fluxos de Reengajamento',
                'kpis'                  => '% de leads recuperados que voltam a comprar, Volume de indicações obtidas de não-compradores',
                'diretriz_ia'           => 'Empatia máxima para desarmar a frustração do lead e transformar uma recusa em oportunidade de conexão e indicação.',
                'cargo_pai_id'          => null,
                'ordem'                 => 13,
                'visivel_para_clientes' => false,
            ],
            // 14. Atendimento WhatsApp
            [
                'nome'                  => 'SDR Atendimento WhatsApp (L1)',
                'tipo'                  => 'atendimento',
                'icone'                 => '💬',
                'descricao'             => 'Triagem automatizada no WhatsApp, captura de dados básicos, delay anti-robô e handoff para vendedor humano.',
                'descricao_cliente'     => 'Atendimento inicial ágil e qualificação de leads via WhatsApp.',
                'detalhes_escopo'       => "1. Recepção imediata de novas mensagens de entrada no canal de WhatsApp.\n2. Extração inteligente de nome, necessidade principal e dados preliminares do lead.\n3. Aplicação de delay humanizado anti-robô e quebra de mensagens em blocos naturais.\n4. Classificação e movimentação automática para a coluna correta do Kanban com handoff limpo.",
                'ferramentas'           => 'Uazapi WhatsApp Gateway, SdrPersona Engine, Kanban Router, Delay Jitter',
                'kpis'                  => 'Tempo de resposta inicial (< 30s), Taxa de conclusão de qualificação, Taxa de retenção anti-bloqueio',
                'diretriz_ia'           => 'Comunicação humana, ágil e acolhedora. Nunca soar robótico ou burocrático; adaptar vocabulário ao DDD e estilo do cliente.',
                'cargo_pai_id'          => null,
                'ordem'                 => 14,
                'visivel_para_clientes' => false,
            ],
        ];

        // 1. Cadastrar / Atualizar Cargos
        $cargosCriados = [];
        foreach ($funcoes as $f) {
            $cargoPaiNome = $f['cargo_pai_nome'] ?? null;
            unset($f['cargo_pai_nome']);

            $cargo = Cargo::updateOrCreate(
                ['nome' => $f['nome']],
                [
                    'tipo'                  => $f['tipo'],
                    'icone'                 => $f['icone'],
                    'descricao'             => $f['descricao'],
                    'descricao_cliente'     => $f['descricao_cliente'] ?? null,
                    'detalhes_escopo'       => $f['detalhes_escopo'] ?? null,
                    'ferramentas'           => $f['ferramentas'] ?? null,
                    'kpis'                  => $f['kpis'] ?? null,
                    'diretriz_ia'           => $f['diretriz_ia'] ?? null,
                    'cargo_pai_id'          => $f['cargo_pai_id'] ?? null,
                    'ordem'                 => $f['ordem'],
                    'ativo'                 => true,
                    'visivel_para_clientes' => $f['visivel_para_clientes'],
                ]
            );
            $cargosCriados[$f['nome']] = $cargo;
        }

        // Atualiza relacionamentos de hierarquia pai/filho
        if (isset($cargosCriados['Diretora de Marketing'])) {
            $marketingId = $cargosCriados['Diretora de Marketing']->id;
            foreach (['Gestor de Tráfego', 'Gestor de Criação', 'Gestor de Copywriting', 'Gestor de SEO'] as $subordinado) {
                if (isset($cargosCriados[$subordinado])) {
                    $cargosCriados[$subordinado]->update(['cargo_pai_id' => $marketingId]);
                }
            }
        }

        // 2. Garantir Usuários dos Agentes IA da Lead Certo
        // Adriana Aviag (Gerente de Suporte)
        $adriana = User::where('email', 'adrianaaviag@gmail.com')->first();
        if (!$adriana) {
            $adriana = User::create([
                'tenant_id'        => 2,
                'nome'             => 'Adriana Aviag',
                'email'            => 'adrianaaviag@gmail.com',
                'password'         => Hash::make('LeadCerto@2026'),
                'perfil'           => 'dono',
                'whatsapp'         => '21984503924',
                'is_ia'            => true,
                'gemini_email'     => 'adrianaaviag@gmail.com',
                'gemini_instrucoes'=> 'Atue como Gerente de Suporte dedicada da Lead Certo. Responda com clareza, empatia e resolutividade.',
                'ativo'            => true,
            ]);
        } else {
            $adriana->update([
                'is_ia' => true,
                'gemini_email' => 'adrianaaviag@gmail.com',
                'gemini_instrucoes'=> 'Atue como Gerente de Suporte dedicada da Lead Certo. Responda com clareza, empatia e resolutividade.',
            ]);
        }
        if (isset($cargosCriados['Gerente de Suporte'])) {
            $adriana->cargos()->syncWithoutDetaching([$cargosCriados['Gerente de Suporte']->id]);
        }

        // Nathanel Fernandes (Diretora de Marketing + Gestor Comercial)
        $nathanel = User::where('email', 'nathanelllfernandees@gmail.com')->first();
        if (!$nathanel) {
            $nathanel = User::create([
                'tenant_id'        => 2,
                'nome'             => 'Nathanel Fernandes',
                'email'            => 'nathanelllfernandees@gmail.com',
                'password'         => Hash::make('LeadCerto@2026'),
                'perfil'           => 'diretor_marketing',
                'whatsapp'         => '21984503924',
                'is_ia'            => true,
                'gemini_email'     => 'nathanelllfernandees@gmail.com',
                'gemini_instrucoes'=> 'Atue como Diretora de Marketing e Gestora Comercial da Lead Certo.',
                'ativo'            => true,
            ]);
        } else {
            $nathanel->update([
                'is_ia' => true,
                'gemini_email' => 'nathanelllfernandees@gmail.com',
                'gemini_instrucoes'=> 'Atue como Diretora de Marketing e Gestora Comercial da Lead Certo.',
            ]);
        }
        $cargosNathanel = [];
        if (isset($cargosCriados['Diretora de Marketing'])) {
            $cargosNathanel[] = $cargosCriados['Diretora de Marketing']->id;
        }
        if (isset($cargosCriados['Gestor Comercial'])) {
            $cargosNathanel[] = $cargosCriados['Gestor Comercial']->id;
        }
        if (!empty($cargosNathanel)) {
            $nathanel->cargos()->syncWithoutDetaching($cargosNathanel);
        }

        // Gabriel (Orquestrador Geral)
        $gabriel = User::where('email', 'gabriel.orquestrador@leadcerto.com')->first();
        if (!$gabriel) {
            $gabriel = User::create([
                'tenant_id'        => 2,
                'nome'             => 'Gabriel',
                'email'            => 'gabriel.orquestrador@leadcerto.com',
                'password'         => Hash::make('LeadCerto@2026'),
                'perfil'           => 'admin',
                'is_ia'            => true,
                'gemini_email'     => 'gabriel.orquestrador@leadcerto.com',
                'gemini_instrucoes'=> 'Atue como Orquestrador Geral e Auditor do sistema SaaS Lead Certo.',
                'ativo'            => true,
            ]);
        } else {
            $gabriel->update([
                'nome'             => 'Gabriel',
                'is_ia'            => true,
                'gemini_email'     => 'gabriel.orquestrador@leadcerto.com',
                'gemini_instrucoes' => 'Atue como Orquestrador Geral e Auditor do sistema SaaS Lead Certo.',
            ]);
        }
        if (isset($cargosCriados['Orquestrador Geral IA'])) {
            $gabriel->cargos()->syncWithoutDetaching([$cargosCriados['Orquestrador Geral IA']->id]);
        }
    }
}
