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

        $valores = [
            'nome'      => isset($pessoa['names'][0]['displayName']) ? $google->limparNome($pessoa['names'][0]['displayName']) : null,
            'sobrenome' => $pessoa['names'][0]['familyName'] ?? null,
            'empresa'   => $pessoa['organizations'][0]['name'] ?? null,
            'email'     => $pessoa['emailAddresses'][0]['value'] ?? null,
        ];

        foreach (self::CAMPOS as $campo) {
            $sync->resolverCampoGoogle($vinculo->contato, $vinculo, $campo, $valores[$campo]);
        }
    }
}
