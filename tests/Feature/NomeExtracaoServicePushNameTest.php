<?php

namespace Tests\Feature;

use App\Services\NomeExtracaoService;
use Tests\TestCase;

/**
 * Achado real 2026-08-20 (Leonardo): pushName de conta comercial/anúncio
 * ("Kasia Ramos proteção veicular", "Mudatech") virava o "nome" do contato
 * sem filtro nenhum, e nomes em minúsculas do WhatsApp ("maria") não eram
 * capitalizados. Ver TAREFAS.md, item 06 do roteiro de 2026-08-20.
 */
class NomeExtracaoServicePushNameTest extends TestCase
{
    private function service(): NomeExtracaoService
    {
        return app(NomeExtracaoService::class);
    }

    public function test_rejeita_pushname_de_propaganda_com_descricao_de_negocio(): void
    {
        $this->assertFalse($this->service()->pushNameValido('Kasia Ramos proteção veicular'));
    }

    public function test_rejeita_pushname_com_palavra_chave_de_empresa(): void
    {
        foreach (['Mudatech Portal de Mudanças', 'Equipe Comercial', 'Frete Ltda'] as $nome) {
            $this->assertFalse($this->service()->pushNameValido($nome), "'{$nome}' deveria ser rejeitado");
        }
    }

    public function test_rejeita_pushname_muito_longo(): void
    {
        $this->assertFalse($this->service()->pushNameValido('Fulano de Tal da Silva Sauro Pereira Junior'));
    }

    public function test_aceita_pushname_normal_de_pessoa(): void
    {
        $this->assertTrue($this->service()->pushNameValido('Maria'));
        $this->assertTrue($this->service()->pushNameValido('João da Silva'));
    }

    public function test_rejeita_pushname_vazio_curto_ou_so_numero(): void
    {
        $this->assertFalse($this->service()->pushNameValido(null));
        $this->assertFalse($this->service()->pushNameValido(''));
        $this->assertFalse($this->service()->pushNameValido('A'));
        $this->assertFalse($this->service()->pushNameValido('5521999998888'));
        $this->assertFalse($this->service()->pushNameValido('~Ocupado'));
        $this->assertFalse($this->service()->pushNameValido('😀😀'));
    }

    public function test_formata_nome_em_minusculo_para_title_case(): void
    {
        $this->assertSame('Maria', $this->service()->formatarNome('maria'));
        $this->assertSame('João Da Silva', $this->service()->formatarNome('joão da silva'));
        $this->assertSame('Ana', $this->service()->formatarNome('ANA'));
    }
}
