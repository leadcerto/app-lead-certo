<?php

namespace Tests\Feature;

use App\Services\PaisIdiomaService;
use Tests\TestCase;

class PaisIdiomaServiceTest extends TestCase
{
    public function test_reconhece_ddi_brasileiro(): void
    {
        $this->assertSame('pt-BR', app(PaisIdiomaService::class)->sugerirIdioma('5521987654321'));
    }

    public function test_reconhece_ddi_portugues(): void
    {
        $this->assertSame('pt-PT', app(PaisIdiomaService::class)->sugerirIdioma('351912345678'));
    }

    public function test_reconhece_ddi_espanhol(): void
    {
        $this->assertSame('es-ES', app(PaisIdiomaService::class)->sugerirIdioma('34612345678'));
    }

    public function test_reconhece_ddi_americano(): void
    {
        $this->assertSame('en-US', app(PaisIdiomaService::class)->sugerirIdioma('12025551234'));
    }

    public function test_ddi_desconhecido_retorna_null(): void
    {
        $this->assertNull(app(PaisIdiomaService::class)->sugerirIdioma('8613800001234'));
    }
}
