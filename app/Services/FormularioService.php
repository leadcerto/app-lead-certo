<?php

namespace App\Services;

use App\Jobs\FormularioLeadJob;
use App\Models\Contato;
use App\Models\Formulario;
use App\Models\FormularioEnvio;
use App\Models\TicketAtendimento;
use App\Models\VinculoContatoTenant;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class FormularioService
{
    /**
     * Verifica se o domínio da requisição está autorizado.
     * Extrai host de Origin ou Referer headers.
     */
    public function dominioAutorizado(Formulario $formulario, ?string $origin, ?string $referer): bool
    {
        // Em preview direto (sem Referer/Origin) — rejeita
        $dominios = $formulario->dominios->pluck('dominio')->map(fn ($d) => strtolower(trim($d)));

        if ($dominios->isEmpty()) {
            // Sem whitelist configurada, nenhuma requisição externa é aceita
            return false;
        }

        $url = $origin ?? $referer ?? '';
        if (! $url) {
            return false;
        }

        $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
        // Remove www. para comparação mais tolerante
        $hostSemWww = preg_replace('/^www\./', '', $host);

        foreach ($dominios as $dominioPermitido) {
            $permSemWww = preg_replace('/^www\./', '', $dominioPermitido);
            if ($host === $dominioPermitido || $hostSemWww === $permSemWww) {
                return true;
            }
        }

        Log::warning('FormularioService: domínio não autorizado', [
            'formulario_id' => $formulario->id,
            'host'          => $host,
            'permitidos'    => $dominios->toArray(),
        ]);

        return false;
    }

    /**
     * Processa a submissão: cria contato, ticket, envio e dispara o job.
     * Retorna o array de resposta.
     */
    public function processar(Formulario $formulario, array $dados, string $dominioOrigem): array
    {
        $tenant = $formulario->tenant;

        // Extrair campos padrão
        $telefone = preg_replace('/\D/', '', $dados['telefone'] ?? '');
        $nome     = trim($dados['nome'] ?? $dados['name'] ?? $telefone);
        $email    = strtolower(trim($dados['email'] ?? ''));

        // Normaliza telefone para formato 55+DDD+número
        if (strlen($telefone) <= 11) {
            $telefone = '55' . $telefone;
        }

        // Campos extras (tudo que não é nome/email/telefone) vão para dados_envio
        $camposExtras = collect($dados)
            ->except(['nome', 'name', 'email', 'telefone'])
            ->toArray();

        // Cria/atualiza contato
        $contato = Contato::firstOrCreate(
            ['telefone' => $telefone],
            [
                'nome'   => $nome,
                'email'  => $email ?: null,
                'origem' => 'formulario',
            ]
        );

        // Atualiza email/nome se vieram vazios
        $updates = [];
        if ($email && ! $contato->email) {
            $updates['email'] = $email;
        }
        if ($nome && $contato->nome === $contato->telefone) {
            $updates['nome'] = $nome;
        }
        if ($updates) {
            $contato->update($updates);
        }

        // Vincula ao tenant
        VinculoContatoTenant::firstOrCreate([
            'contato_id' => $contato->id,
            'tenant_id'  => $tenant->id,
        ]);

        // Verifica ticket já aberto para não duplicar
        $ticketExistente = TicketAtendimento::where('tenant_id', $tenant->id)
            ->where('contato_id', $contato->id)
            ->whereIn('coluna_kanban', ['lead_novo', 'em_atendimento'])
            ->first();

        if ($ticketExistente) {
            $envio = FormularioEnvio::create([
                'formulario_id'  => $formulario->id,
                'contato_id'     => $contato->id,
                'ticket_id'      => $ticketExistente->id,
                'dominio_origem' => $dominioOrigem,
                'dados_envio'    => $dados,
                'processado'     => false,
            ]);

            return ['ok' => true, 'acao' => 'ticket_existente', 'ticket_id' => $ticketExistente->id];
        }

        // Cria ticket
        $ticket = TicketAtendimento::create([
            'tenant_id'          => $tenant->id,
            'contato_id'         => $contato->id,
            'coluna_kanban'      => 'lead_novo',
            'agente_responsavel' => 'bot',
            'etapa_ia'           => 'etapa_1',
            'origem'             => 'formulario',
            'formulario_id'      => $formulario->id,
        ]);

        $envio = FormularioEnvio::create([
            'formulario_id'  => $formulario->id,
            'contato_id'     => $contato->id,
            'ticket_id'      => $ticket->id,
            'dominio_origem' => $dominioOrigem,
            'dados_envio'    => $dados,
            'processado'     => false,
        ]);

        // Regra 5 do arquiteto: dispara o job DEPOIS de retornar 200
        FormularioLeadJob::dispatch($envio->id, $ticket->id)->onQueue('default');

        Log::info('Formulário processado', [
            'formulario'  => $formulario->id,
            'contato'     => $contato->id,
            'ticket'      => $ticket->id,
        ]);

        return ['ok' => true, 'acao' => 'lead_criado', 'ticket_id' => $ticket->id];
    }

    /**
     * Valida campos obrigatórios baseado na config do formulário.
     * Retorna array de erros ou array vazio se OK.
     */
    public function validarCampos(Formulario $formulario, array $dados): array
    {
        $erros = [];

        foreach ($formulario->campos as $campo) {
            if (! $campo->obrigatorio) {
                continue;
            }

            $valor = trim($dados[$campo->chave] ?? '');

            if ($valor === '') {
                $erros[$campo->chave] = "O campo \"{$campo->rotulo}\" é obrigatório.";
                continue;
            }

            if ($campo->tipo === 'email' && ! filter_var($valor, FILTER_VALIDATE_EMAIL)) {
                $erros[$campo->chave] = "O campo \"{$campo->rotulo}\" deve ser um e-mail válido.";
            }

            if ($campo->tipo === 'telefone' && strlen(preg_replace('/\D/', '', $valor)) < 10) {
                $erros[$campo->chave] = "O campo \"{$campo->rotulo}\" deve ser um telefone válido.";
            }
        }

        return $erros;
    }
}
