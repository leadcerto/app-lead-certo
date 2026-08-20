<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Carbon;

abstract class TestCase extends BaseTestCase
{
    /**
     * Achado real 2026-08-19/20: o teto de aquecimento WhatsApp bloqueia envio
     * durante a madrugada (23h-7h Brasília, ver AquecimentoWhatsappService). Sem
     * travar o relógio de teste, QUALQUER teste que dependa de um envio ter
     * sucesso vira flaky — passa de dia, falha de madrugada, dependendo só de
     * que horas são quando alguém roda a suíte (foi exatamente o que aconteceu
     * aqui: testes que sempre passaram começaram a falhar sem nenhuma mudança
     * neles, só porque a hora real virou madrugada). Trava num horário comercial
     * fixo por padrão; testes que precisam de outro horário (ex.: os que testam
     * o próprio bloqueio de madrugada) sobrescrevem com Carbon::setTestNow() no
     * próprio corpo do teste — o reset aqui garante que isso nunca vaza pro
     * próximo teste, mesmo se o teste que sobrescreveu falhar no meio.
     */
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-08-18 14:00:00', 'America/Sao_Paulo'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
