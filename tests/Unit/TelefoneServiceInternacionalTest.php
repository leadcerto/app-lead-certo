<?php

namespace Tests\Unit;

use App\Services\TelefoneService;
use PHPUnit\Framework\TestCase;

class TelefoneServiceInternacionalTest extends TestCase
{
    private TelefoneService $tel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tel = new TelefoneService();
    }

    public function test_reconhece_portugal_como_valido(): void
    {
        $this->assertNumeroInternacionalValido('351911926680');
    }

    public function test_reconhece_reino_unido_como_valido(): void
    {
        $this->assertNumeroInternacionalValido('447981567044');
    }

    public function test_reconhece_italia_como_valido(): void
    {
        $this->assertNumeroInternacionalValido('393883846031');
    }

    public function test_reconhece_alemanha_como_valido(): void
    {
        $this->assertNumeroInternacionalValido('4917675439289');
    }

    public function test_reconhece_chile_como_valido(): void
    {
        $this->assertNumeroInternacionalValido('56982283272');
    }

    public function test_reconhece_paraguai_como_valido(): void
    {
        $this->assertNumeroInternacionalValido('595975285162');
    }

    private function assertNumeroInternacionalValido(string $numero): void
    {
        $this->assertSame($numero, $this->tel->normalizar($numero));

        $diagnostico = $this->tel->diagnosticar($numero);
        $this->assertSame('valido', $diagnostico['status']);
    }

    public function test_numero_brasileiro_valido_continua_funcionando(): void
    {
        $this->assertSame('5521999998888', $this->tel->normalizar('5521999998888'));
        $this->assertSame('5521999998888', $this->tel->normalizar('21999998888'));
    }

    public function test_numero_verdadeiramente_invalido_continua_falhando(): void
    {
        // Prefixo curto demais para bater com qualquer DDI reconhecido
        $this->assertNull($this->tel->normalizar('00912345'));
    }
}
