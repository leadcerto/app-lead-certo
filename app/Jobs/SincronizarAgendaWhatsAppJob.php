<?php

namespace App\Jobs;

use App\Models\Contato;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\VinculoContatoTenant;
use App\Services\UazapiService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Disparado automaticamente quando um tenant conecta o WhatsApp pela primeira vez.
 * Importa todos os contatos da agenda do celular → CRM + Google + ticket.
 */
class SincronizarAgendaWhatsAppJob implements ShouldQueue
{
    use Queueable;

    public int $tries   = 2;
    public int $timeout = 300;

    public function __construct(public int $tenantId) {}

    public function handle(UazapiService $uazapi): void
    {
        $tenant = Tenant::find($this->tenantId);

        if (! $tenant || ! $tenant->uazapi_instance_token) {
            return;
        }

        $contatos = $uazapi->listarContatos($tenant->uazapi_instance_token);

        if (empty($contatos)) {
            Log::info("SincronizarAgendaWhatsApp: sem contatos para tenant #{$this->tenantId}");
            return;
        }

        $personaId = $tenant->personas()
            ->where('is_default', true)
            ->where('ativo', true)
            ->value('id');

        $criados = 0;

        foreach ($contatos as $wa) {
            $jid  = $wa['jid'] ?? null;
            $nome = $this->limparNome($wa['contact_name'] ?? '', $wa['contact_FirstName'] ?? '');

            if (! $jid || ! str_contains($jid, '@s.whatsapp.net') || ! $nome) {
                continue;
            }

            $telefone = preg_replace('/@.+$/', '', $jid);

            $contato = Contato::where('telefone', $telefone)
                ->orWhere('telefone', ltrim($telefone, '55'))
                ->first();

            if ($contato) {
                // Só atualiza vínculo e Google se não tiver resource
                VinculoContatoTenant::firstOrCreate([
                    'contato_id' => $contato->id,
                    'tenant_id'  => $tenant->id,
                ]);
                continue;
            }

            $contato = Contato::create([
                'telefone' => $telefone,
                'nome'     => $nome ?: 'Sem Nome',
                'origem'   => 'whatsapp_agenda',
                'opt_out'  => false,
            ]);

            VinculoContatoTenant::firstOrCreate([
                'contato_id' => $contato->id,
                'tenant_id'  => $tenant->id,
            ]);

            // Sincroniza com Google apenas novos
            PushContatoParaGoogleJob::dispatch($contato->id, $tenant->id);

            // Cria ticket se não existe
            $temTicket = TicketAtendimento::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('contato_id', $contato->id)
                ->whereIn('status', ['aberto', 'aguardando'])
                ->exists();

            if (! $temTicket) {
                TicketAtendimento::withoutGlobalScopes()->create([
                    'tenant_id'          => $tenant->id,
                    'contato_id'         => $contato->id,
                    'coluna_kanban'      => 'lead_novo',
                    'agente_responsavel' => 'humano',
                    'sdr_persona_id'     => $personaId,
                    'status'             => 'aberto',
                    'aberto_em'          => now(),
                    'origem'             => 'whatsapp_agenda',
                ]);
            }

            $criados++;
        }

        Log::info("SincronizarAgendaWhatsApp: tenant #{$this->tenantId} — {$criados} novos contatos importados");
    }

    private function limparNome(string $contactName, string $firstName): string
    {
        $nome = trim($firstName) ?: trim($contactName);
        if (! $nome) return '';
        return trim(preg_replace('/^(.+\s.+)\s\d{4}$/', '$1', $nome));
    }
}
