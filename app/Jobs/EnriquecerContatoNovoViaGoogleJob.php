<?php

namespace App\Jobs;

use App\Models\GoogleToken;
use App\Models\VinculoContatoTenant;
use App\Services\ContatoSyncService;
use App\Services\GoogleService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Design: docs/superpowers/specs/2026-08-26-sync-bidirecional-google-contatos-design.md
 * seção 10. Disparado sem delay quando um VinculoContatoTenant novo é criado
 * (ver VinculoContatoTenant::booted()) — pro lead inicial não esperar o
 * próximo ciclo do cron (até 15 min) pra mostrar o nome real, se já existir
 * salvo no Google do cliente. Roda em background (fila default), nunca
 * bloqueia a resposta do webhook/app/formulário que criou o contato.
 */
class EnriquecerContatoNovoViaGoogleJob implements ShouldQueue
{
    use Queueable;

    private const CAMPOS = ['nome', 'sobrenome', 'empresa', 'email'];

    public function __construct(private int $vinculoId) {}

    public function handle(GoogleService $google, ContatoSyncService $sync): void
    {
        $vinculo = VinculoContatoTenant::with('contato')->find($this->vinculoId);
        if (! $vinculo || ! $vinculo->contato) {
            return;
        }

        $token = GoogleToken::where('tenant_id', $vinculo->tenant_id)->first();
        if (! $token) {
            return;
        }

        $pessoa = $google->buscarContatoPorTelefone($token, $vinculo->contato->telefone);
        if (! $pessoa) {
            return;
        }

        // Achado da revisão de branch: o job achava o contato no Google e
        // jogava fora resourceName/etag, extraindo só os valores de campo. Sem
        // gravar o vínculo, o PushContatoParaGoogleJob (disparado por outro
        // caminho) não tinha como saber que esse contato JÁ existe lá e criava
        // um cartão DUPLICADO na agenda do cliente. Só preenche quando o
        // vínculo ainda não aponta pra ninguém — se já aponta, o etag da busca
        // pode ser de outro resource e sobrescrever quebraria o próximo PATCH.
        if (! $vinculo->google_resource_name && ! empty($pessoa['resourceName'])) {
            $vinculo->update(array_filter([
                'google_resource_name' => $pessoa['resourceName'],
                'google_etag'          => $pessoa['etag'] ?? null,
            ]));
        }

        // Mesma razão de ContatoSyncService::processarPessoa(): displayName é
        // composto pelo Google a partir de givenName + middleName + familyName,
        // e o middleName carrega o ID do banco que nós mesmos gravamos lá.
        $nomeRaw = trim((string) ($pessoa['names'][0]['givenName'] ?? ''))
            ?: ($pessoa['names'][0]['displayName'] ?? null);

        // Mesma guarda de ContatoSyncService::extrairDados(): o endpoint legado
        // atualizarGoogleSobrenome() ainda grava o ID do banco no familyName
        // (convenção antiga) — sem essa guarda, esse ID vazaria pro campo
        // sobrenome também por este caminho de busca em tempo real.
        $sobrenomeRaw = trim((string) ($pessoa['names'][0]['familyName'] ?? ''));
        $sobrenome    = $sobrenomeRaw !== '' && ctype_digit($sobrenomeRaw) ? '' : $sobrenomeRaw;

        $valores = [
            'nome'      => $nomeRaw ? $sync->limparNome($nomeRaw) : null,
            'sobrenome' => $sobrenome ?: null,
            'empresa'   => $pessoa['organizations'][0]['name'] ?? null,
            'email'     => $pessoa['emailAddresses'][0]['value'] ?? null,
        ];

        foreach (self::CAMPOS as $campo) {
            $sync->resolverCampoGoogle($vinculo->contato, $vinculo, $campo, $valores[$campo]);
        }
    }
}
