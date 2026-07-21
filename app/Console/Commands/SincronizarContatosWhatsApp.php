<?php

namespace App\Console\Commands;

use App\Jobs\PushContatoParaGoogleJob;
use App\Models\Contato;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\VinculoContatoTenant;
use App\Services\UazapiService;
use Illuminate\Console\Command;

class SincronizarContatosWhatsApp extends Command
{
    protected $signature = 'contatos:sincronizar-whatsapp {--tenant= : ID do tenant (padrão: todos com instância ativa)}';
    protected $description = 'Importa contatos da agenda WhatsApp: salva no CRM, cria ticket e sincroniza com Google';

    public function handle(UazapiService $uazapi): int
    {
        $query = Tenant::whereNotNull('uazapi_instance_token');

        if ($tenantId = $this->option('tenant')) {
            $query->where('id', $tenantId);
        }

        $tenants = $query->get();

        if ($tenants->isEmpty()) {
            $this->warn('Nenhum tenant com WhatsApp conectado.');
            return Command::SUCCESS;
        }

        foreach ($tenants as $tenant) {
            $this->info("Tenant #{$tenant->id} — {$tenant->nome}");
            $this->sincronizar($tenant, $uazapi);
        }

        return Command::SUCCESS;
    }

    private function sincronizar(Tenant $tenant, UazapiService $uazapi): void
    {
        $this->line('  Buscando contatos do WhatsApp...');

        $contatos = $uazapi->listarContatos($tenant->uazapi_instance_token);

        if (empty($contatos)) {
            $this->warn('  Nenhum contato retornado pela API.');
            return;
        }

        $this->line("  " . count($contatos) . " contatos encontrados na agenda");

        $personaId = $tenant->personas()
            ->where('is_default', true)
            ->where('ativo', true)
            ->value('id');

        $criados      = 0;
        $atualizados  = 0;
        $ticketsCriados = 0;
        $semNome      = 0;

        foreach ($contatos as $wa) {
            $jid  = $wa['jid'] ?? null;
            $nome = $this->limparNome($wa['contact_name'] ?? '', $wa['contact_FirstName'] ?? '');

            if (! $jid || ! str_contains($jid, '@s.whatsapp.net')) {
                $semNome++;
                continue;
            }

            $telefoneComDdi = preg_replace('/@.+$/', '', $jid);

            // Normaliza para formato 55+DDD+número (12-13 dígitos)
            $telefone = $telefoneComDdi;

            // Busca pelo número com ou sem DDI
            $contato = Contato::where('telefone', $telefone)
                ->orWhere('telefone', ltrim($telefone, '55'))
                ->first();

            $novoContato = false;

            if (! $contato) {
                if (! $nome) {
                    // Sem nome e sem cadastro — pula
                    $semNome++;
                    continue;
                }

                $contato = Contato::create([
                    'telefone' => $telefone,
                    'nome'     => $nome ?: 'Sem Nome',
                    'origem'   => 'whatsapp_agenda',
                    'opt_out'  => false,
                ]);
                $novoContato = true;
                $criados++;
            } elseif ($nome) {
                $semNomeAtual = ! $contato->nome || $contato->nome === $contato->telefone || strtolower($contato->nome) === 'sem nome';
                if ($semNomeAtual) {
                    $contato->update(['nome' => $nome]);
                }
                $atualizados++;
            }

            // Vincula ao tenant
            [$vinculo, $vinculoNovo] = [
                VinculoContatoTenant::firstOrCreate([
                    'contato_id' => $contato->id,
                    'tenant_id'  => $tenant->id,
                ]),
                false,
            ];
            $vinculo = VinculoContatoTenant::where('contato_id', $contato->id)
                ->where('tenant_id', $tenant->id)
                ->first();
            $vinculoNovo = $vinculo->wasRecentlyCreated ?? false;

            // Envia para o Google apenas se for um contato recém-criado
            if ($novoContato) {
                PushContatoParaGoogleJob::dispatch($contato->id, $tenant->id);
            }

            // Cria ticket se não tem um aberto
            $temTicket = TicketAtendimento::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('contato_id', $contato->id)
                ->whereIn('status', ['aberto', 'aguardando'])
                ->exists();

            if (! $temTicket && $novoContato) {
                TicketAtendimento::withoutGlobalScopes()->create([
                    'tenant_id'          => $tenant->id,
                    'contato_id'         => $contato->id,
                    'coluna_kanban'      => \App\Models\KanbanColuna::chaveDeEntrada($tenant->id),
                    'agente_responsavel' => 'humano',
                    'sdr_persona_id'     => $personaId,
                    'status'             => 'aberto',
                    'aberto_em'          => now(),
                    'origem'             => 'whatsapp_agenda',
                ]);
                $ticketsCriados++;
            }
        }

        $this->info("  ✓ Contatos criados:  {$criados}");
        $this->info("  ✓ Nomes atualizados: {$atualizados}");
        $this->info("  ✓ Tickets criados:   {$ticketsCriados}");
        $this->info("  ✓ Google: jobs disparados para novos/sem resource");
        $this->line("  - Sem nome (ignorados): {$semNome}");
    }

    private function limparNome(string $contactName, string $firstName): string
    {
        $nome = trim($firstName) ?: trim($contactName);

        if (! $nome) {
            return '';
        }

        // Remove sufixo de 4 dígitos: "João Silva 1234" → "João Silva"
        $nome = trim(preg_replace('/^(.+\s.+)\s\d{4}$/', '$1', $nome));

        return $nome;
    }
}
