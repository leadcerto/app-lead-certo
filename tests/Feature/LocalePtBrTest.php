<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Tests\TestCase;

class LocalePtBrTest extends TestCase
{
    /**
     * Achado ao vivo (2026-08-18): produção sem APP_LOCALE fazia o
     * painel do avaliador mostrar dia da semana em inglês ("TUESDAY").
     * AppServiceProvider força Carbon::setLocale() no boot — esse teste
     * garante que isso não regride silenciosamente.
     */
    public function test_carbon_usa_locale_pt_br_por_padrao(): void
    {
        $this->assertSame('pt_BR', Carbon::getLocale());

        $terca = Carbon::parse('2026-08-18'); // é uma terça-feira de verdade
        $this->assertSame('terça-feira, 18/08', $terca->translatedFormat('l, d/m'));
    }
}
