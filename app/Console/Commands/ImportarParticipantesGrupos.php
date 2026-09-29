<?php

namespace App\Console\Commands;

use App\Jobs\MarcarContatoFrioEtiquetaJob;
use App\Jobs\PushContatoParaGoogleJob;
use App\Models\Contato;
use App\Models\Tenant;
use App\Models\VinculoContatoTenant;
use App\Models\WhatsappCanal;
use App\Services\Canais\CanalComGruposInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Plano de extração de contatos de grupos/comunidades (29/09, pedido do
 * Leonardo): reescrito pra funcionar em qualquer canal não-oficial (Uazapi
 * ou Messenger próprio), via CanalComGruposInterface — antes só a Uazapi era
 * suportada. Cadastra só como Contato (etiqueta 🚩 FRIOS, prospecção fria
 * futura), sem criar Ticket/Kanban automático — decisão explícita: menor
 * risco de poluir o Kanban ou de a IA um dia falar com um número que nunca
 * deu esse consentimento. Ver [[whatsapp-extracao-contatos-grupos]].
 */
class ImportarParticipantesGrupos extends Command
{
    protected $signature = 'grupos:importar-participantes {--tenant= : ID do tenant (padrão: todos)}';
    protected $description = 'Extrai contatos de grupos/comunidades do WhatsApp e cadastra como leads frios (🚩 FRIOS), sem criar ticket';

    public function handle(): int
    {
        // provider explícito (mesmo achado do Fase 0 do canal Messenger
        // próprio): WhatsappCanal::servico() cai no default (Uazapi) pra
        // qualquer provider desconhecido — checar só instanceof deixaria um
        // canal de provider não reconhecido ser tratado como Uazapi aqui
        // também.
        $query = WhatsappCanal::withoutGlobalScopes()
            ->where('tipo', 'nao_oficial')
            ->whereIn('provider', ['uazapi', 'messenger_proprio'])
            ->where('status', 'connected');

        if ($tenantId = $this->option('tenant')) {
            $query->where('tenant_id', $tenantId);
        }

        foreach ($query->get() as $canal) {
            $servico = $canal->servico();

            if (! $servico instanceof CanalComGruposInterface) {
                continue;
            }

            $tenant = $canal->tenant;
            $this->info("Tenant #{$tenant->id} — {$tenant->nome} (canal #{$canal->id}, provider={$canal->provider})");

            $this->importar($tenant, $canal, $servico);
        }

        return Command::SUCCESS;
    }

    private function importar(Tenant $tenant, WhatsappCanal $canal, CanalComGruposInterface $servico): void
    {
        $this->line('  Buscando grupos...');
        $grupos = $servico->listarGrupos($canal);

        if (empty($grupos)) {
            $this->warn('  Nenhum grupo encontrado.');
            return;
        }

        $this->line('  ' . count($grupos) . ' grupos encontrados');

        $numeroCanal = preg_replace('/\D/', '', (string) $canal->phone);

        $totalCriados  = 0;
        $totalExistiam = 0;
        $totalSemNum   = 0;

        foreach ($grupos as $grupo) {
            $nomeGrupo     = $grupo['nome'] ?? 'Grupo';
            $jid           = $grupo['jid'] ?? null;
            $participantes = $grupo['participantes'] ?? [];

            $criados = 0;

            foreach ($participantes as $p) {
                $telefone = preg_replace('/\D/', '', (string) ($p['telefone'] ?? ''));

                if (strlen($telefone) < 10) {
                    $totalSemNum++;
                    continue;
                }

                // O próprio número do canal aparece como participante de
                // todo grupo que ele está — não é um lead.
                if ($numeroCanal && $telefone === $numeroCanal) {
                    continue;
                }

                $contato = Contato::where('telefone', $telefone)->first();

                if ($contato) {
                    // Já existe no CRM — só garante o vínculo com o tenant e
                    // registra o grupo em comum (quebra-gelo futuro), nunca
                    // mexe no nome/etiqueta de um contato já classificado.
                    $vinculo = VinculoContatoTenant::firstOrCreate([
                        'contato_id' => $contato->id,
                        'tenant_id'  => $tenant->id,
                    ]);
                    $this->registrarGrupoEmComum($vinculo, $jid, $nomeGrupo);
                    $totalExistiam++;
                    continue;
                }

                $contato = Contato::create([
                    'telefone' => $telefone,
                    'nome'     => 'Sem Nome',
                    'origem'   => 'whatsapp_grupo',
                    'opt_out'  => false,
                ]);

                $vinculo = VinculoContatoTenant::create([
                    'contato_id' => $contato->id,
                    'tenant_id'  => $tenant->id,
                ]);
                $this->registrarGrupoEmComum($vinculo, $jid, $nomeGrupo);

                PushContatoParaGoogleJob::dispatch($contato->id, $tenant->id);
                // Delay: dá tempo do push acima criar o google_resource_name
                // antes do job tentar adicionar ao grupo 🚩 FRIOS (mesmo
                // padrão de MarcarNovoLeadEtiquetaJob).
                MarcarContatoFrioEtiquetaJob::dispatch($vinculo->id)->delay(now()->addMinutes(2));

                $criados++;
                $totalCriados++;
            }

            $this->line("  [{$nomeGrupo}] " . count($participantes) . " participantes — {$criados} novos");
        }

        Log::info('ImportarParticipantesGrupos: importação concluída', [
            'tenant_id' => $tenant->id, 'canal_id' => $canal->id,
            'criados' => $totalCriados, 'existiam' => $totalExistiam, 'sem_numero' => $totalSemNum,
        ]);

        $this->info("  ✓ Novos contatos frios criados: {$totalCriados}");
        $this->info("  ✓ Já existiam no CRM:           {$totalExistiam}");
        $this->line("  - Sem número (ignorados):       {$totalSemNum}");
    }

    /**
     * Guarda jid (estável, nunca muda) + nome do grupo NO MOMENTO da
     * extração — o nome não serve pra identificar o grupo de forma
     * confiável (pode ser editado depois), só pra escrever a mensagem de
     * quebra-gelo. Dedupe por jid: rodar o comando de novo não duplica.
     */
    private function registrarGrupoEmComum(VinculoContatoTenant $vinculo, ?string $jid, string $nomeGrupo): void
    {
        if (! $jid) {
            return;
        }

        $grupos = $vinculo->grupos_whatsapp_em_comum ?? [];

        $jaRegistrado = collect($grupos)->contains(fn ($g) => ($g['jid'] ?? null) === $jid);
        if ($jaRegistrado) {
            return;
        }

        $grupos[] = ['jid' => $jid, 'nome_visto' => $nomeGrupo];
        $vinculo->update(['grupos_whatsapp_em_comum' => $grupos]);
    }
}
