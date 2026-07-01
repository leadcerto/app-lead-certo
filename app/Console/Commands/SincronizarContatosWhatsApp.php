<?php

namespace App\Console\Commands;

use App\Models\Contato;
use App\Models\Tenant;
use App\Models\VinculoContatoTenant;
use App\Services\UazapiService;
use Illuminate\Console\Command;

class SincronizarContatosWhatsApp extends Command
{
    protected $signature = 'contatos:sincronizar-whatsapp {--tenant= : ID do tenant (padrão: todos com instância ativa)}';
    protected $description = 'Cruza a agenda do WhatsApp com o CRM e preenche nomes faltantes';

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

        $this->line("  {$tenant->nome}: " . count($contatos) . " contatos no WhatsApp");

        $atualizados  = 0;
        $semNome      = 0;
        $naoEncontrados = 0;

        foreach ($contatos as $wa) {
            $jid  = $wa['jid'] ?? null;
            $nome = $this->limparNome($wa['contact_name'] ?? '', $wa['contact_FirstName'] ?? '');

            if (! $jid || ! $nome) {
                $semNome++;
                continue;
            }

            // Extrai número do JID: 5521999999999@s.whatsapp.net → 5521999999999
            $telefoneComDdi = preg_replace('/@.+$/', '', $jid);

            // Versão sem DDI 55 (formato interno do CRM)
            $telefoneSemDdi = (str_starts_with($telefoneComDdi, '55') && strlen($telefoneComDdi) > 12)
                ? substr($telefoneComDdi, 2)
                : $telefoneComDdi;

            // Busca pelo número com ou sem DDI
            $contato = Contato::where('telefone', $telefoneSemDdi)
                ->orWhere('telefone', $telefoneComDdi)
                ->first();

            if (! $contato) {
                // Contato não está no CRM — cria com os dados da agenda do WhatsApp
                $novo = Contato::create([
                    'telefone' => $telefoneSemDdi,
                    'nome'     => $nome,
                    'origem'   => 'whatsapp_agenda',
                    'opt_out'  => false,
                ]);
                VinculoContatoTenant::firstOrCreate([
                    'contato_id' => $novo->id,
                    'tenant_id'  => $tenant->id,
                ]);
                $naoEncontrados++;
                continue;
            }

            // Só atualiza se o contato está sem nome ou com nome igual ao telefone
            if (! $contato->nome || $contato->nome === $contato->telefone) {
                $contato->update(['nome' => $nome]);
                $atualizados++;
            }
        }

        $this->info("  ✓ Nomes preenchidos: {$atualizados}");
        $this->info("  ✓ Criados da agenda WA: {$naoEncontrados}");
        $this->line("  - Sem nome na agenda WA: {$semNome}");
    }

    private function limparNome(string $contactName, string $firstName): string
    {
        // Prefere contact_FirstName se disponível e não vazio
        $nome = trim($firstName) ?: trim($contactName);

        if (! $nome) {
            return '';
        }

        // Remove sufixo de 4 dígitos quando há 2+ palavras: "João Silva 1234" → "João Silva"
        $nome = trim(preg_replace('/^(.+\s.+)\s\d{4}$/', '$1', $nome));

        return $nome;
    }
}
