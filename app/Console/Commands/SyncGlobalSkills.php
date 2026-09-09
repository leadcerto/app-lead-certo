<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use App\Models\AgentSkill;

#[Signature('leadcerto:sync-skills')]
#[Description('Sincroniza as skills globais da pasta _global/skills para o banco de dados')]
class SyncGlobalSkills extends Command
{
    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info("Iniciando sincronização de skills globais...");
        
        $skillsPath = base_path('../../../_global/skills');
        
        if (!File::isDirectory($skillsPath)) {
            $this->error("Pasta de skills não encontrada: {$skillsPath}");
            return;
        }

        $directories = File::directories($skillsPath);
        $count = 0;

        $traducaoMap = [
            'api-patterns' => ['cat' => 'Arquitetura & Backend', 'titulo' => 'Padrões de API', 'desc' => 'Princípios de design de API, REST vs GraphQL, formatação e paginação.'],
            'app-builder' => ['cat' => 'Desenvolvimento', 'titulo' => 'Criador de Apps', 'desc' => 'Orquestrador principal para criar aplicações full-stack a partir de pedidos em linguagem natural.'],
            'architecture' => ['cat' => 'Arquitetura & Backend', 'titulo' => 'Arquitetura de Software', 'desc' => 'Framework para tomada de decisões arquiteturais, avaliação de trade-offs e ADRs.'],
            'bash-linux' => ['cat' => 'DevOps & Infra', 'titulo' => 'Terminal Linux/Bash', 'desc' => 'Padrões de terminal, comandos críticos, scripts e tratamento de erros no Linux/macOS.'],
            'behavioral-modes' => ['cat' => 'Comportamento IA', 'titulo' => 'Modos Comportamentais', 'desc' => 'Modos operacionais da IA (brainstorm, implementação, debug, etc) para adaptação dinâmica.'],
            'brainstorming' => ['cat' => 'Comportamento IA', 'titulo' => 'Brainstorming & Ideação', 'desc' => 'Protocolo de questionamento socrático para clarear requisitos e planejar novas features.'],
            'brand-identity-extractor' => ['cat' => 'Marketing & Design', 'titulo' => 'Extrator de Identidade Visual', 'desc' => 'Extrai especificações de design de URLs/imagens e cria manuais de marca (Brand Guidelines).'],
            'clean-code' => ['cat' => 'Desenvolvimento', 'titulo' => 'Clean Code', 'desc' => 'Padrões pragmáticos de código limpo, focados em simplicidade e sem over-engineering.'],
            'code-review-checklist' => ['cat' => 'Qualidade e Testes', 'titulo' => 'Revisor de Código', 'desc' => 'Diretrizes rigorosas para code review, focadas em qualidade, segurança e performance.'],
            'database-design' => ['cat' => 'Arquitetura & Backend', 'titulo' => 'Modelagem de Banco de Dados', 'desc' => 'Especialista em design de esquemas de banco de dados, normalização e otimização de consultas.'],
            'deployment-procedures' => ['cat' => 'DevOps & Infra', 'titulo' => 'Procedimentos de Deploy', 'desc' => 'Regras e passos para garantir deploys seguros e sem interrupções em produção.'],
            'documentation-templates' => ['cat' => 'Comportamento IA', 'titulo' => 'Templates de Documentação', 'desc' => 'Padrões para criação de READMEs, manuais e documentação técnica clara.'],
            'frontend-design' => ['cat' => 'Marketing & Design', 'titulo' => 'Design Frontend', 'desc' => 'Especialista em UI/UX moderna, animações GSAP, cores e experiência do usuário.'],
            'game-development' => ['cat' => 'Desenvolvimento', 'titulo' => 'Desenvolvimento de Jogos', 'desc' => 'Lógicas e padrões focados no desenvolvimento de jogos e interatividade.'],
            'geo-fundamentals' => ['cat' => 'Arquitetura & Backend', 'titulo' => 'Fundamentos Geoespaciais', 'desc' => 'Regras para manipulação de coordenadas, mapas e sistemas de informação geográfica.'],
            'hostinger-deploy' => ['cat' => 'DevOps & Infra', 'titulo' => 'Deploy na Hostinger', 'desc' => 'Instruções específicas para deployment de aplicações em servidores e hospedagens Hostinger.'],
            'i18n-localization' => ['cat' => 'Desenvolvimento', 'titulo' => 'Internacionalização', 'desc' => 'Padrões para preparar sistemas para múltiplos idiomas e fusos horários.'],
            'intelligent-routing' => ['cat' => 'Comportamento IA', 'titulo' => 'Roteamento Inteligente', 'desc' => 'Agente especialista em delegar tarefas para outros sub-agentes com base no contexto.'],
            'lint-and-validate' => ['cat' => 'Qualidade e Testes', 'titulo' => 'Linter & Validação', 'desc' => 'Foco exclusivo em corrigir erros de sintaxe, formatação e validações estáticas.'],
            'mcp-builder' => ['cat' => 'Comportamento IA', 'titulo' => 'Construtor de Servidores MCP', 'desc' => 'Especialista na criação de protocolos e servidores Model Context Protocol (MCP).'],
            'mobile-design' => ['cat' => 'Marketing & Design', 'titulo' => 'Design Mobile First', 'desc' => 'Padrões de desenvolvimento voltados para telas pequenas e usabilidade mobile.'],
            'react-best-practices' => ['cat' => 'Desenvolvimento', 'titulo' => 'Especialista React', 'desc' => 'Boas práticas para ecossistema React, hooks customizados, e gerenciamento de estado.'],
            'nodejs-best-practices' => ['cat' => 'Arquitetura & Backend', 'titulo' => 'Especialista Node.js', 'desc' => 'Padrões avançados para Node.js, event loop, streams e performance backend.'],
            'parallel-agents' => ['cat' => 'Comportamento IA', 'titulo' => 'Agentes Paralelos', 'desc' => 'Técnicas de orquestração para executar múltiplos agentes de IA simultaneamente.'],
            'performance-profiling' => ['cat' => 'Qualidade e Testes', 'titulo' => 'Análise de Performance', 'desc' => 'Especialista em encontrar gargalos, memory leaks e otimizar tempo de resposta.'],
            'plan-writing' => ['cat' => 'Comportamento IA', 'titulo' => 'Redator de Planos', 'desc' => 'Especialista em quebrar tarefas complexas em planos de ação passo a passo.'],
            'powershell-windows' => ['cat' => 'DevOps & Infra', 'titulo' => 'PowerShell/Windows', 'desc' => 'Scripts, automação e comandos nativos para ambientes Windows.'],
            'python-patterns' => ['cat' => 'Desenvolvimento', 'titulo' => 'Especialista Python', 'desc' => 'Código idiomático Python, decorators, generators e boas práticas do ecossistema.'],
            'red-team-tactics' => ['cat' => 'Segurança', 'titulo' => 'Táticas Red Team (Segurança)', 'desc' => 'Agente focado em encontrar vulnerabilidades e testar a segurança defensiva da aplicação.'],
            'rust-pro' => ['cat' => 'Desenvolvimento', 'titulo' => 'Desenvolvedor Rust Pro', 'desc' => 'Especialista em Rust, memory safety, lifetimes e concorrência avançada.'],
            'seo-fundamentals' => ['cat' => 'Marketing & Design', 'titulo' => 'Fundamentos de SEO', 'desc' => 'Boas práticas para otimização de motores de busca, meta tags e indexação.'],
            'server-management' => ['cat' => 'DevOps & Infra', 'titulo' => 'Gestão de Servidores', 'desc' => 'Administração de VPS, nginx, apache, SSL e configurações de rede.'],
            'skill-creator' => ['cat' => 'Comportamento IA', 'titulo' => 'Criador de Skills', 'desc' => 'Agente especializado em escrever as diretrizes comportamentais para criar NOVAS skills.'],
            'skill-landpage' => ['cat' => 'Marketing & Design', 'titulo' => 'Construtor de Landing Pages', 'desc' => 'Focado na criação rápida de Landing Pages de alta conversão usando React e Tailwind.'],
            'systematic-debugging' => ['cat' => 'Qualidade e Testes', 'titulo' => 'Debugging Sistemático', 'desc' => 'Metodologia científica para isolar bugs complexos e descobrir a causa raiz.'],
            'tailwind-patterns' => ['cat' => 'Marketing & Design', 'titulo' => 'Padrões Tailwind CSS', 'desc' => 'Domínio avançado de classes utilitárias, customização e componentes com Tailwind.'],
            'tdd-workflow' => ['cat' => 'Qualidade e Testes', 'titulo' => 'Workflow TDD', 'desc' => 'Agente guiado por testes: primeiro cria o teste, depois a implementação.'],
            'testing-patterns' => ['cat' => 'Qualidade e Testes', 'titulo' => 'Padrões de Testes', 'desc' => 'Estruturação de testes unitários, integração, E2E e uso correto de mocks.'],
            'vulnerability-scanner' => ['cat' => 'Segurança', 'titulo' => 'Scanner de Vulnerabilidades', 'desc' => 'Busca ativamente por falhas comuns (OWASP Top 10) no código antes do deploy.'],
            'web-design-guidelines' => ['cat' => 'Marketing & Design', 'titulo' => 'Diretrizes de Web Design', 'desc' => 'Regras gerais de usabilidade, acessibilidade e tipografia na web.'],
            'webapp-testing' => ['cat' => 'Qualidade e Testes', 'titulo' => 'Testes de WebApp', 'desc' => 'Especialista em automação de testes de UI e fluxos de navegação web.']
        ];

        foreach ($directories as $dir) {
            $folderName = basename($dir);
            $skillFile = $dir . '/SKILL.md';
            
            if (File::exists($skillFile)) {
                $content = File::get($skillFile);
                
                $nome = $folderName;
                
                if (preg_match('/^---\n(.*?)\n---\n(.*)$/s', $content, $matches)) {
                    $body = $matches[2];
                    $instrucoes = trim($body);
                } else {
                    $instrucoes = $content;
                }
                
                $map = $traducaoMap[$folderName] ?? null;
                if ($map) {
                    $titulo = $map['titulo'];
                    $descricaoCurta = $map['desc'];
                    $categoria = $map['cat'];
                } else {
                    $titulo = ucwords(str_replace('-', ' ', $folderName));
                    $descricaoCurta = 'Agente ' . $titulo;
                    $categoria = 'Outros';
                }

                AgentSkill::updateOrCreate(
                    [
                        'tenant_id' => null,
                        'nome' => $nome
                    ],
                    [
                        'origem' => 'lead_certo',
                        'categoria' => $categoria,
                        'titulo' => $titulo,
                        'descricao_curta' => substr($descricaoCurta, 0, 250),
                        'descricao_completa' => 'Importada automaticamente da pasta _global/skills',
                        'instrucoes_base' => $instrucoes,
                        'ativa' => true,
                    ]
                );
                
                $this->line("Sincronizada: {$nome}");
                $count++;
            }
        }

        $this->info("Sincronização concluída! {$count} skills importadas/atualizadas.");
    }
}
