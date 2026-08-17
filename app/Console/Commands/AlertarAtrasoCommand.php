<?php

namespace App\Console\Commands;

use App\Mail\AlertaAtrasoAdminMail;
use App\Mail\AlertaAtrasoMail;
use App\Models\AgendamentoAvaliacao;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Comando CRON: php artisan avaliadores:checar-atraso
 *
 * Roda diariamente via scheduler e:
 * 1. Busca agendamentos com status pendente/enviado e data_agendada < hoje
 * 2. Envia e-mail urgente individual para cada avaliador em atraso
 * 3. Compila relatório gerencial e envia ao admin com CC nos avaliadores
 *
 * Registrar no routes/console.php:
 * Schedule::command('avaliadores:checar-atraso')->dailyAt('08:00');
 */
class AlertarAtrasoCommand extends Command
{
    protected $signature = 'avaliadores:checar-atraso';
    protected $description = 'Verifica avaliações em atraso e envia alertas por e-mail.';

    public function handle(): int
    {
        $this->info('🔍 Verificando avaliações em atraso...');

        $atrasados = AgendamentoAvaliacao::atrasados()
            ->with(['perfil', 'template', 'avaliador'])
            ->get();

        if ($atrasados->isEmpty()) {
            $this->info('✅ Nenhuma avaliação em atraso. Tudo em dia!');
            return self::SUCCESS;
        }

        // ── Agrupar por avaliador ─────────────────────────────────────────────
        $porAvaliador = $atrasados->groupBy('avaliador_id');
        $emailsCC     = [];
        $enviadosIndividuais = 0;

        foreach ($porAvaliador as $avaliadorId => $agendamentos) {
            $avaliador = $agendamentos->first()->avaliador;

            if (!$avaliador || !$avaliador->email) {
                $this->warn("⚠️ Avaliador ID {$avaliadorId} sem e-mail. Pulando...");
                continue;
            }

            // E-mail individual urgente
            Mail::to($avaliador->email)
                ->send(new AlertaAtrasoMail($avaliador, $agendamentos));

            $emailsCC[] = $avaliador->email;
            $enviadosIndividuais++;

            $this->line("  📧 Alerta enviado para: {$avaliador->nome} ({$avaliador->email}) — {$agendamentos->count()} atraso(s)");
        }

        // ── Relatório gerencial para o Admin ──────────────────────────────────
        $adminEmail = config('mail.review_report_to');

        if ($adminEmail) {
            Mail::to($adminEmail)
                ->send(new AlertaAtrasoAdminMail($porAvaliador, $emailsCC));

            $this->info("📊 Relatório gerencial enviado para: {$adminEmail}");
        } else {
            $this->warn('⚠️ MAIL_REVIEW_REPORT_TO não configurado no .env. Relatório admin não enviado.');
        }

        $this->info("✅ Concluído: {$enviadosIndividuais} alerta(s) individual(is), {$atrasados->count()} avaliação(ões) em atraso total.");

        return self::SUCCESS;
    }
}
