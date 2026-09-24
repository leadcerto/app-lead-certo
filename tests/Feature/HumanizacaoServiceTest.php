<?php

namespace Tests\Feature;

use App\Services\Canais\EnvioBrutoWhatsappInterface;
use App\Services\HumanizacaoService;
use Tests\TestCase;

/**
 * Fase 1 do planejamento do canal WhatsApp Messenger próprio (23/09):
 * HumanizacaoService vira stateless — processar() recebe o serviço de envio
 * bruto como parâmetro em vez de injeção fixa de UazapiService no
 * construtor, pra poder ser reaproveitado por qualquer canal não-oficial
 * (não só Uazapi). Não existia nenhum teste direto deste serviço antes
 * (só cobertura indireta via Http::fake() em UazapiChannelServiceTest/
 * SequenciaMensagemJob*Test) — este arquivo cobre o contrato com um double
 * simples da interface nova, sem precisar de HTTP real.
 */
class HumanizacaoServiceTest extends TestCase
{
    private function servicoFalso(array &$chamadas): EnvioBrutoWhatsappInterface
    {
        return new class($chamadas) implements EnvioBrutoWhatsappInterface {
            public function __construct(private array &$chamadas) {}

            public function enviarTexto(string $instanceToken, string $numero, string $texto): bool
            {
                $this->chamadas[] = ['tipo' => 'texto', 'token' => $instanceToken, 'numero' => $numero, 'texto' => $texto];
                return true;
            }

            public function setPresenca(string $instanceToken, string $presenca, ?string $para = null): bool
            {
                $this->chamadas[] = ['tipo' => 'presenca', 'token' => $instanceToken, 'presenca' => $presenca];
                return true;
            }
        };
    }

    public function test_processar_envia_texto_curto_num_unico_balao(): void
    {
        $chamadas = [];
        $servico  = $this->servicoFalso($chamadas);

        $ok = app(HumanizacaoService::class)->processar($servico, 'tok-123', '5511999998888', 'Oi, tudo bem?');

        $this->assertTrue($ok);
        $textos = array_values(array_filter($chamadas, fn ($c) => $c['tipo'] === 'texto'));
        $this->assertCount(1, $textos);
        $this->assertSame('Oi, tudo bem?', $textos[0]['texto']);
        $this->assertSame('tok-123', $textos[0]['token']);
        $this->assertSame('5511999998888', $textos[0]['numero']);
    }

    public function test_processar_divide_texto_com_paragrafo_duplo_em_baloes_separados(): void
    {
        $chamadas = [];
        $servico  = $this->servicoFalso($chamadas);

        app(HumanizacaoService::class)->processar(
            $servico,
            'tok-123',
            '5511999998888',
            "Primeiro balão.\n\nSegundo balão."
        );

        $textos = array_values(array_filter($chamadas, fn ($c) => $c['tipo'] === 'texto'));
        $this->assertCount(2, $textos);
        $this->assertSame('Primeiro balão.', $textos[0]['texto']);
        $this->assertSame('Segundo balão.', $textos[1]['texto']);
    }

    public function test_processar_envia_presenca_antes_de_cada_balao(): void
    {
        $chamadas = [];
        $servico  = $this->servicoFalso($chamadas);

        app(HumanizacaoService::class)->processar($servico, 'tok-123', '5511999998888', 'Oi!');

        $this->assertSame('presenca', $chamadas[0]['tipo']);
        $this->assertSame('composing', $chamadas[0]['presenca']);
    }

    public function test_processar_retorna_false_quando_envio_do_balao_falha(): void
    {
        $servico = new class implements EnvioBrutoWhatsappInterface {
            public function enviarTexto(string $instanceToken, string $numero, string $texto): bool
            {
                return false;
            }

            public function setPresenca(string $instanceToken, string $presenca, ?string $para = null): bool
            {
                return true;
            }
        };

        $ok = app(HumanizacaoService::class)->processar($servico, 'tok-123', '5511999998888', 'Oi!');

        $this->assertFalse($ok);
    }
}
