<?php

namespace App\Console\Commands;

use App\Jobs\ProvisionarEtiquetasGoogleJob;
use App\Models\GoogleToken;
use App\Services\GoogleService;
use Illuminate\Console\Command;

/**
 * Pra tenant que já conectou o Google ANTES desta feature existir
 * (GoogleToken::booted() só dispara em created(), não retroativamente) —
 * roda a mesma provisão + marcação em massa manualmente. Caso de uso:
 * Frete Rio, já conectado, precisa deste comando rodar uma vez.
 */
class BackfillEtiquetasValidacaoContatos extends Command
{
    protected $signature = 'contatos:backfill-etiquetas-validacao {--tenant= : ID do tenant}';

    protected $description = 'Provisiona as etiquetas de validação e marca a base existente pra um tenant já conectado ao Google';

    public function handle(GoogleService $google): int
    {
        $tenantId = (int) $this->option('tenant');
        if (! $tenantId) {
            $this->error('--tenant é obrigatório.');

            return 1;
        }

        $token = GoogleToken::where('tenant_id', $tenantId)->first();
        if (! $token) {
            $this->error('Tenant sem GoogleToken conectado.');

            return 1;
        }

        (new ProvisionarEtiquetasGoogleJob($token->id))->handle($google);

        $this->info('Etiquetas de validação provisionadas e base existente marcada como LEADS EM ANÁLISE.');

        return 0;
    }
}
