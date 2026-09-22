<?php

namespace App\Jobs;

use App\Models\GoogleToken;
use App\Models\VinculoContatoTenant;
use App\Services\ContatoSyncService;
use App\Services\GoogleService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Achado real 2026-09-22 (pedido do Leonardo, aba "Conflitos de Identidade"):
 * quando o Google traz uma etiqueta comercial no lugar do nome ("Frete",
 * "Frt", "Mdm") pra um telefone que já tem nome real cadastrado localmente,
 * ContatoSyncService::processarPessoa() dispara este job em vez de abrir
 * conflito de "número possivelmente reciclado" — empurra o nome real local
 * pro Google, fora da transação do sync (mesmo padrão assíncrono de
 * PushContatoParaGoogleJob), pra manter os dois cadastros iguais.
 */
class AtualizarNomeGoogleComDadoLocalJob implements ShouldQueue
{
    use Queueable;

    public int $tries   = 3;
    public int $timeout = 30;

    public function __construct(private int $vinculoId) {}

    public function handle(GoogleService $google, ContatoSyncService $sync): void
    {
        $vinculo = VinculoContatoTenant::with('contato')->find($this->vinculoId);
        if (! $vinculo || ! $vinculo->contato || ! $vinculo->google_resource_name || ! $vinculo->google_etag) {
            return;
        }

        $token = GoogleToken::where('tenant_id', $vinculo->tenant_id)->first();
        if (! $token) {
            return;
        }

        $nameEntry = $google->formatarNomeParaGoogle($vinculo->contato);

        $enviou = $google->atualizarNomeContato(
            $token,
            $vinculo->google_resource_name,
            $vinculo->google_etag,
            $nameEntry['givenName'],
            $nameEntry['familyName'] ?? '',
            $nameEntry['middleName'] ?? null
        );

        if ($enviou) {
            $vinculo->update([
                'google_valores_enviados' => array_filter(array_merge(
                    $vinculo->google_valores_enviados ?? [],
                    [
                        'nome'      => $sync->limparNome($nameEntry['givenName']),
                        'sobrenome' => $nameEntry['familyName'] ?? null,
                    ]
                )),
            ]);
        }
    }
}
