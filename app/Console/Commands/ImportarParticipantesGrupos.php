<?php

namespace App\Console\Commands;

use App\Jobs\PushContatoParaGoogleJob;
use App\Models\Contato;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\VinculoContatoTenant;
use App\Services\UazapiService;
use Illuminate\Console\Command;

class ImportarParticipantesGrupos extends Command
{
    protected $signature = 'grupos:importar-participantes {--tenant= : ID do tenant (padrão: todos)}';
    protected $description = 'Importa todos os participantes dos grupos do WhatsApp para o CRM e Google';

    public function handle(UazapiService $uazapi): int
    {
        $query = Tenant::whereNotNull('uazapi_instance_token');

        if ($tenantId = $this->option('tenant')) {
            $query->where('id', $tenantId);
        }

        foreach ($query->get() as $tenant) {
            $this->info("Tenant #{$tenant->id} — {$tenant->nome}");
            $this->importar($tenant, $uazapi);
        }

        return Command::SUCCESS;
    }

    private function importar(Tenant $tenant, UazapiService $uazapi): void
    {
        $this->line('  Buscando grupos...');
        $grupos = $uazapi->listarGrupos($tenant->uazapi_instance_token);

        if (empty($grupos)) {
            $this->warn('  Nenhum grupo encontrado.');
            return;
        }

        $this->line('  ' . count($grupos) . ' grupos encontrados');

        // Monta índice nome → telefone a partir da agenda do WhatsApp
        $this->line('  Buscando agenda para cruzar nomes...');
        $agenda = $uazapi->listarContatos($tenant->uazapi_instance_token);
        $nomesPorTelefone = [];
        foreach ($agenda as $c) {
            $tel  = preg_replace('/@.+$/', '', $c['jid'] ?? '');
            $nome = $this->limparNome($c['contact_name'] ?? '', $c['contact_FirstName'] ?? '');
            if ($tel && $nome) {
                $nomesPorTelefone[$tel] = $nome;
            }
        }

        $personaId = $tenant->personas()
            ->where('is_default', true)
            ->where('ativo', true)
            ->value('id');

        $totalCriados  = 0;
        $totalExistiam = 0;
        $totalSemNum   = 0;

        foreach ($grupos as $grupo) {
            $nomeGrupo    = $grupo['Name'] ?? 'Grupo';
            $participantes = $grupo['Participants'] ?? [];

            $criados = 0;

            foreach ($participantes as $p) {
                $phoneRaw = $p['PhoneNumber'] ?? null;
                if (! $phoneRaw) {
                    $totalSemNum++;
                    continue;
                }

                // Extrai número: "5521964598746@s.whatsapp.net" → "5521964598746"
                $telefone = preg_replace('/@.+$/', '', $phoneRaw);

                // Ignora grupos e números inválidos (< 10 dígitos)
                if (str_contains($phoneRaw, '@g.us') || strlen($telefone) < 10) {
                    continue;
                }

                // Busca nome na agenda
                $nome = $nomesPorTelefone[$telefone] ?? null;

                // Verifica se já existe no CRM
                $contato = Contato::where('telefone', $telefone)->first();

                if ($contato) {
                    // Atualiza nome se estava sem nome
                    $semNome = ! $contato->nome || $contato->nome === $contato->telefone || strtolower($contato->nome) === 'sem nome';
                    if ($nome && $semNome) {
                        $contato->update(['nome' => $nome]);
                    }
                    VinculoContatoTenant::firstOrCreate([
                        'contato_id' => $contato->id,
                        'tenant_id'  => $tenant->id,
                    ]);
                    $totalExistiam++;
                    continue;
                }

                // Cria novo contato
                $contato = Contato::create([
                    'telefone' => $telefone,
                    'nome'     => $nome ?: 'Sem Nome',
                    'origem'   => 'whatsapp_grupo',
                    'opt_out'  => false,
                ]);

                VinculoContatoTenant::firstOrCreate([
                    'contato_id' => $contato->id,
                    'tenant_id'  => $tenant->id,
                ]);

                // Só envia para Google se tem nome real
                if ($nome) {
                    PushContatoParaGoogleJob::dispatch($contato->id, $tenant->id);
                }

                // Cria ticket se não tem nenhum aberto
                $temTicket = TicketAtendimento::withoutGlobalScopes()
                    ->where('tenant_id', $tenant->id)
                    ->where('contato_id', $contato->id)
                    ->whereIn('status', ['aberto', 'aguardando'])
                    ->exists();

                if (! $temTicket) {
                    TicketAtendimento::withoutGlobalScopes()->create([
                        'tenant_id'          => $tenant->id,
                        'contato_id'         => $contato->id,
                        'coluna_kanban'      => \App\Models\KanbanColuna::chaveDeEntrada($tenant->id),
                        'agente_responsavel' => 'humano',
                        'sdr_persona_id'     => $personaId,
                        'status'             => 'aberto',
                        'aberto_em'          => now(),
                        'origem'             => 'whatsapp_grupo',
                    ]);
                }

                $criados++;
                $totalCriados++;
            }

            $this->line("  [{$nomeGrupo}] " . count($participantes) . " participantes — {$criados} novos");
        }

        $this->info("  ✓ Novos contatos criados:  {$totalCriados}");
        $this->info("  ✓ Já existiam no CRM:      {$totalExistiam}");
        $this->line("  - Sem número (ignorados):  {$totalSemNum}");
    }

    private function limparNome(string $contactName, string $firstName): string
    {
        $nome = trim($firstName) ?: trim($contactName);
        if (! $nome) return '';
        return trim(preg_replace('/^(.+\s.+)\s\d{4}$/', '$1', $nome));
    }
}
