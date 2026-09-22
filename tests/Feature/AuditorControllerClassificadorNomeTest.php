<?php

namespace Tests\Feature;

use App\Http\Controllers\Painel\AuditorController;
use Tests\TestCase;

/**
 * Achado real 2026-09-22 (pedido do Leonardo, aba "Conflitos de Identidade"):
 * ao ligar isNaoPessoa() num caminho automático de sync (ContatoSyncService),
 * uma varredura na base de produção achou 8 nomes REAIS classificados como
 * "não é pessoa" por causa de padrões \w* abertos demais no tagsLixo — o
 * prefixo casava com o começo de nomes de verdade: 'van\w*' pegava
 * "Vanessa"/"Vanda"/"Vania"/"Vaneska"/"Vanderlei" (19+ contatos reais só de
 * "Vanessa"), 'dr\w*' pegava "Drica"/"Dryelle", 'adv\w*' pegava "Advaldo".
 */
class AuditorControllerClassificadorNomeTest extends TestCase
{
    public function test_nomes_reais_que_colidiam_com_prefixos_de_tag_nao_sao_mais_falso_positivo(): void
    {
        foreach (['Vanessa', 'Vanda', 'Vania', 'Vaneska', 'Vanderlei', 'Drica', 'Dryelle', 'Advaldo'] as $nome) {
            $this->assertFalse(
                AuditorController::isNaoPessoa($nome),
                "'{$nome}' é nome de pessoa de verdade, não pode ser classificado como etiqueta comercial"
            );
        }
    }

    public function test_tags_comerciais_continuam_sendo_detectadas_apos_o_ajuste(): void
    {
        foreach (['Frete', 'Frt', 'Van', 'Vans', 'Dr', 'Dra', 'Adv', 'Advogado', 'Advogada', 'Mdm'] as $tag) {
            $this->assertTrue(
                AuditorController::isNaoPessoa($tag),
                "'{$tag}' é etiqueta comercial, tem que continuar sendo detectada"
            );
        }
    }
}
