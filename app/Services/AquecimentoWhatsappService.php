<?php

namespace App\Services;

use App\Models\Contato;
use App\Models\Mensagem;
use App\Models\WhatsappCanal;
use App\Models\WhatsappEnvioDiario;
use Illuminate\Support\Carbon;

/**
 * Aquecimento de número WhatsApp não-oficial — decisão do Leonardo (2026-08-19):
 * TODOS os números não-oficiais passam por isso pra sempre, não só onboarding de
 * número novo. A rampa sobe de um teto baixo pro teto de regime (Seção 8 do manual
 * `-whatsapp-não-oficial--uazapi/regra-geral-de-envio-de-mensagens-no-whatsapp.md`:
 * 50 msgs/dia pra contato frio, 200 pra contato quente) e o teto de regime nunca
 * deixa de ser verificado — a rampa só existe pros primeiros dias, o limite em si
 * é permanente.
 *
 * Dois perfis, mesma tabela, curva diferente:
 * - 'protegido': número que não pode ser perdido (ex.: Adriana) — rampa lenta.
 * - 'descartavel': número dedicado a prospecção fria — rampa mais rápida, aceita
 *   risco maior de perder o número (tem outro pra substituir).
 */
class AquecimentoWhatsappService
{
    private const TETO_REGIME_FRIO   = 50;
    private const TETO_REGIME_QUENTE = 200;

    /**
     * Curva de rampa por dia desde a ativação do número. Cada perfil tem sua
     * própria progressão; o último degrau de cada um já é o teto de regime.
     *
     * @return array{frio: int, quente: int}
     */
    public function limiteHoje(WhatsappCanal $canal): array
    {
        $dias = $this->diasDesdeAtivacao($canal);

        $curva = $canal->perfil_aquecimento === 'descartavel'
            ? [
                // dias => [frio, quente]
                0  => [0, 5],
                3  => [15, 20],
                5  => [35, 50],
                8  => [self::TETO_REGIME_FRIO, 150],
                14 => [self::TETO_REGIME_FRIO, self::TETO_REGIME_QUENTE],
            ]
            : [
                0  => [0, 5],
                3  => [5, 15],
                5  => [15, 40],
                8  => [30, 100],
                14 => [self::TETO_REGIME_FRIO, self::TETO_REGIME_QUENTE],
            ];

        $degrau = [0, 5];
        foreach ($curva as $diaMinimo => $valores) {
            if ($dias >= $diaMinimo) {
                $degrau = $valores;
            }
        }

        return ['frio' => $degrau[0], 'quente' => $degrau[1]];
    }

    private function diasDesdeAtivacao(WhatsappCanal $canal): int
    {
        if (! $canal->aquecimento_iniciado_em) {
            return 0; // sem data registrada — trata como recém-ativado, o mais seguro
        }

        return (int) $canal->aquecimento_iniciado_em->startOfDay()->diffInDays(now()->startOfDay());
    }

    /**
     * WhatsApp Brasil: nunca iniciar/manter conversa entre 23h e 07h (Seção 8 do
     * manual de envio) — horário de Brasília, independente do fuso do servidor.
     */
    public function dentroDoHorarioPermitido(?\DateTimeInterface $agora = null): bool
    {
        $horaBrasilia = Carbon::instance($agora ?? now())->setTimezone('America/Sao_Paulo')->hour;

        return $horaBrasilia >= 7 && $horaBrasilia < 23;
    }

    /**
     * 'quente' = telefone já mandou pelo menos uma mensagem antes, nesse tenant.
     * 'frio' = nunca falou com a gente — é o caso que mais preocupa o WhatsApp.
     */
    public function classificarContato(string $telefone, int $tenantId): string
    {
        $contato = Contato::withoutGlobalScopes()->where('telefone', $telefone)->first();

        if (! $contato) {
            return 'frio';
        }

        $jaRespondeu = Mensagem::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('remetente', 'lead')
            ->whereHas('ticket', fn ($q) => $q->where('contato_id', $contato->id))
            ->exists();

        return $jaRespondeu ? 'quente' : 'frio';
    }

    /**
     * Checagem única que o UazapiChannelService faz antes de qualquer envio —
     * combina horário permitido + teto do dia ainda não estourado. Não distingue
     * o motivo do bloqueio pro chamador (quem quiser o detalhe usa os métodos
     * específicos acima), só diz se pode mandar agora ou não.
     */
    public function podeEnviar(WhatsappCanal $canal, string $telefone): bool
    {
        if (! $this->dentroDoHorarioPermitido()) {
            return false;
        }

        $limites = $this->limiteHoje($canal);
        $tipo    = $this->classificarContato($telefone, $canal->tenant_id);

        $envioHoje = WhatsappEnvioDiario::where('whatsapp_canal_id', $canal->id)
            ->whereDate('data', now()->toDateString())
            ->first();

        $usadoHoje = $tipo === 'frio' ? ($envioHoje->contador_frio ?? 0) : ($envioHoje->contador_quente ?? 0);
        $limite    = $limites[$tipo];

        return $usadoHoje < $limite;
    }

    /**
     * Incrementa o contador do dia — chamar só DEPOIS de confirmar que o envio
     * de verdade aconteceu (não antes, senão um envio que falhou some do teto
     * sem nunca ter contado de fato).
     */
    public function registrarEnvio(WhatsappCanal $canal, string $telefone): void
    {
        $tipo = $this->classificarContato($telefone, $canal->tenant_id);
        $coluna = $tipo === 'frio' ? 'contador_frio' : 'contador_quente';

        // firstOrCreate() comum não serve aqui: o cast 'date' grava "Y-m-d 00:00:00"
        // no SQLite, mas a query de busca comparava com "Y-m-d" puro (string de
        // now()->toDateString()) e nunca batia — cada chamada tentava inserir de
        // novo e estourava a constraint unique(whatsapp_canal_id, data) na segunda
        // chamada do mesmo dia. whereDate() normaliza os dois lados da comparação.
        $envio = WhatsappEnvioDiario::whereDate('data', now()->toDateString())
            ->where('whatsapp_canal_id', $canal->id)
            ->first();

        if (! $envio) {
            $envio = WhatsappEnvioDiario::create([
                'whatsapp_canal_id' => $canal->id,
                'data'              => now()->toDateString(),
                'contador_frio'     => 0,
                'contador_quente'   => 0,
            ]);
        }

        $envio->increment($coluna);
    }
}
