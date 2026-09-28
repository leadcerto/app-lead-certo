<?php

namespace Tests\Feature;

use App\Services\PaisTelefoneService;
use Tests\TestCase;

/**
 * Achado real 2026-09-21 (pedido do Leonardo): PaisTelefoneService cobria só
 * 37 países — expandido pra cobertura equivalente ao dropdown do Google
 * Contatos (~195 países, ITU-T E.164). Este teste não usa banco (RefreshDatabase
 * desnecessário), só valida a constante PAISES e o algoritmo de detecção.
 */
class PaisTelefoneServiceCoberturaTest extends TestCase
{
    public function test_cobertura_tem_pelo_menos_150_paises(): void
    {
        $this->assertGreaterThanOrEqual(150, count(PaisTelefoneService::PAISES));
    }

    public function test_nenhum_ddi_duplicado_na_lista(): void
    {
        $ddis = array_column(PaisTelefoneService::PAISES, 'ddi');
        $this->assertSame(count($ddis), count(array_unique($ddis)), 'Há DDIs duplicados na lista — quebraria a detecção automática de país.');
    }

    public function test_nenhum_iso_duplicado_na_lista(): void
    {
        $isos = array_column(PaisTelefoneService::PAISES, 'iso');
        $this->assertSame(count($isos), count(array_unique($isos)), 'Há códigos ISO duplicados na lista.');
    }

    public function test_todo_pais_tem_bandeira_nome_ddi_e_iso_preenchidos(): void
    {
        foreach (PaisTelefoneService::PAISES as $pais) {
            $this->assertNotEmpty($pais['iso'] ?? null, 'País sem ISO: ' . json_encode($pais));
            $this->assertNotEmpty($pais['nome'] ?? null, 'País sem nome: ' . json_encode($pais));
            $this->assertNotEmpty($pais['ddi'] ?? null, 'País sem DDI: ' . json_encode($pais));
            $this->assertNotEmpty($pais['bandeira'] ?? null, 'País sem bandeira: ' . json_encode($pais));
        }
    }

    public function test_identifica_nigeria_recem_adicionada(): void
    {
        $this->assertSame('NG', PaisTelefoneService::identificarPais('+234 803 123 4567')['iso']);
    }

    public function test_identifica_turquia_recem_adicionada(): void
    {
        $this->assertSame('TR', PaisTelefoneService::identificarPais('+90 532 123 4567')['iso']);
    }

    /**
     * Achado real (durante a escrita deste teste, 2026-09-21): identificarPais()
     * descartava o "+" junto com o resto da pontuação antes de decidir se é
     * Brasil — qualquer número com exatamente 10 ou 11 dígitos caía na
     * heurística de "local brasileiro sem DDI", mesmo vindo com "+DDI"
     * explícito e sendo de outro país. Corrigido em 24/09 (ver
     * test_identifica_suica_mesmo_com_11_digitos_parecendo_numero_brasileiro
     * abaixo) — os exemplos abaixo continuam evitando esse comprimento por
     * segurança, mas a limitação em si já não existe mais.
     */
    public function test_identifica_polonia_recem_adicionada(): void
    {
        $this->assertSame('PL', PaisTelefoneService::identificarPais('+48 512 345 6789')['iso']);
    }

    public function test_identifica_filipinas_recem_adicionada(): void
    {
        $this->assertSame('PH', PaisTelefoneService::identificarPais('+63 917 123 4567')['iso']);
    }

    public function test_identifica_quenia_recem_adicionada(): void
    {
        $this->assertSame('KE', PaisTelefoneService::identificarPais('+254 712 345678')['iso']);
    }

    public function test_identifica_vietna_recem_adicionado(): void
    {
        $this->assertSame('VN', PaisTelefoneService::identificarPais('+84 912 345 6789')['iso']);
    }

    public function test_brasil_continua_funcionando_apos_expansao(): void
    {
        $resultado = PaisTelefoneService::identificarPais('5521999999999');
        $this->assertSame('BR', $resultado['iso']);
    }

    // ─── Achado real 24/09 (Leonardo, contato "Vanete" #98404): número suíço
    // virando "+55 (41)..." errado — DDD 41 (Curitiba) e DDI da Suíça são o
    // mesmo número, por isso a checagem de Brasil sem DDI precisa confirmar o
    // formato de celular/fixo real, não só o comprimento total. ─────────────

    public function test_identifica_suica_mesmo_com_11_digitos_parecendo_numero_brasileiro(): void
    {
        // 41 79 258 60 58 — 11 dígitos, "41" é tanto DDI da Suíça quanto DDD
        // de Curitiba, mas "79258..." não tem o formato de celular BR (não
        // começa com 9 depois do DDD) nem de fixo (9 dígitos, não 8).
        $resultado = PaisTelefoneService::identificarPais('41792586058');
        $this->assertSame('CH', $resultado['iso']);
    }

    public function test_ainda_identifica_celular_de_curitiba_sem_ddi_mesmo_com_ddd_igual_ao_da_suica(): void
    {
        // Mesmo DDD "41" da Suíça, mas com formato real de celular brasileiro
        // (9 dígitos, começando com 9) — tem que continuar sendo Brasil.
        $resultado = PaisTelefoneService::identificarPais('41991234567');
        $this->assertSame('BR', $resultado['iso']);
    }

    public function test_identifica_espanha_mesmo_com_ddi_igual_a_ddd_de_minas_gerais(): void
    {
        // DDI da Espanha (34) também é DDD de Minas Gerais — celular espanhol
        // real (9 dígitos, começa com 6 ou 7, nunca 9) não pode virar Brasil.
        $resultado = PaisTelefoneService::identificarPais('34612345678');
        $this->assertSame('ES', $resultado['iso']);
    }
}
