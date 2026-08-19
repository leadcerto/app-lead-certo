<?php

namespace App\Console\Commands;

use App\Models\WhatsappCanal;
use App\Models\WhatsappGrupoPost;
use App\Services\AquecimentoWhatsappService;
use App\Services\UazapiService;
use Illuminate\Console\Command;

/**
 * Posta uma reação casual em 1-2 grupos por canal, a cada execução — pedido do
 * Leonardo (2026-08-19): número em aquecimento precisa continuar ativo nos grupos
 * de figurinha em que entrou (recebe volume pra maturar), senão o grupo expulsa
 * por inatividade. Nunca mais de 1 post por grupo por dia — postar demais é tão
 * suspeito quanto ficar mudo.
 *
 * Mensagens de outras pessoas nesses grupos NUNCA passam por aqui nem por
 * nenhum outro lugar do sistema — UazapiWebhookController já ignora qualquer
 * evento com isGroup=true antes de processar qualquer coisa. Essa tabela só
 * registra o que NÓS postamos, pro controle de "1x por dia".
 *
 * Agendar rodando a cada poucas horas, com jitter (nunca em horário fixo — ver
 * routes/console.php), igual ao resto dos comandos anti-detecção do sistema.
 */
class AquecimentoGruposCommand extends Command
{
    protected $signature = 'whatsapp:aquecimento-grupos
                            {--dry-run : Mostra o que faria sem enviar}';

    protected $description = 'Posta uma reação casual em grupos pra manter número de aquecimento ativo, sem ficar mudo';

    /** Pool variado — nunca repetir o mesmo duas vezes seguidas no mesmo grupo. */
    private const REACOES = [
        '😂', '🔥', '👏', '😅', 'kkkkk', 'boa!', '😍', 'top', 'kkkkkk 😂',
        '👍', 'rs', 'muito bom', '😄', '🙌', 'kkkkk mds',
    ];

    public function handle(UazapiService $uazapi, AquecimentoWhatsappService $aquecimento): int
    {
        $dry = $this->option('dry-run');

        if (! $aquecimento->dentroDoHorarioPermitido()) {
            $this->info('Fora do horário permitido (23h-7h Brasília) — nada a fazer.');
            return self::SUCCESS;
        }

        $canais = WhatsappCanal::where('tipo', 'nao_oficial')->get();

        foreach ($canais as $canal) {
            $token = $canal->tokenUazapi();
            if (! $token) {
                continue;
            }

            $grupos = $uazapi->listarGrupos($token);
            if (empty($grupos)) {
                continue;
            }

            $grupoEscolhido = $this->escolherGrupoSemPostHoje($canal, $grupos);
            if (! $grupoEscolhido) {
                continue;
            }

            $chatid = $grupoEscolhido['chatid'] ?? $grupoEscolhido['id'] ?? null;
            if (! $chatid) {
                continue;
            }

            $reacao = self::REACOES[array_rand(self::REACOES)];

            $this->line("Canal {$canal->id}: postando \"{$reacao}\" em {$chatid}" . ($dry ? ' (dry-run)' : ''));

            if ($dry) {
                continue;
            }

            $enviado = $uazapi->enviarTexto($token, $chatid, $reacao);

            if ($enviado) {
                WhatsappGrupoPost::create([
                    'whatsapp_canal_id' => $canal->id,
                    'grupo_chatid'      => $chatid,
                    'conteudo'          => $reacao,
                    'postado_em'        => now(),
                ]);
            }
        }

        return self::SUCCESS;
    }

    /**
     * Só 1 grupo por canal por execução (evita postar em massa de uma vez só,
     * que é tão suspeito quanto ficar mudo) — entre os que ainda não receberam
     * post hoje, escolhe um aleatório.
     */
    private function escolherGrupoSemPostHoje(WhatsappCanal $canal, array $grupos): ?array
    {
        $chatidsPostadosHoje = WhatsappGrupoPost::where('whatsapp_canal_id', $canal->id)
            ->whereDate('postado_em', now()->toDateString())
            ->pluck('grupo_chatid')
            ->all();

        $disponiveis = array_filter($grupos, function ($grupo) use ($chatidsPostadosHoje) {
            $chatid = $grupo['chatid'] ?? $grupo['id'] ?? null;
            return $chatid && ! in_array($chatid, $chatidsPostadosHoje, true);
        });

        if (empty($disponiveis)) {
            return null;
        }

        return $disponiveis[array_rand($disponiveis)];
    }
}
