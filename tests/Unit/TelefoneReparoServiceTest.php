<?php

namespace Tests\Unit;

use App\Services\TelefoneReparoService;
use App\Services\TelefoneService;
use Tests\TestCase;

class TelefoneReparoServiceTest extends TestCase
{
    private TelefoneReparoService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TelefoneReparoService(new TelefoneService());
    }

    public function test_telefone_ja_canonico_e_reconhecido_como_tal(): void
    {
        $this->assertTrue($this->service->ehCanonico('5521994359537'));
        $this->assertSame(['5521994359537'], $this->service->candidatos('5521994359537'));
    }

    public function test_12_digitos_sem_o_9_gera_candidato_13_digitos(): void
    {
        // Ademir: 555481126376 (12) -> insere 9 na 5a posicao -> 5554981126376
        $this->assertFalse($this->service->ehCanonico('555481126376'));
        $this->assertContains('5554981126376', $this->service->candidatos('555481126376'));
    }

    public function test_11_digitos_sem_o_55_gera_candidato_13_digitos(): void
    {
        // Ademir: 54981126376 (11) -> prefixa 55 -> 5554981126376
        $this->assertContains('5554981126376', $this->service->candidatos('54981126376'));
    }

    public function test_prefixo_0_espurio_e_removido_recursivamente(): void
    {
        // 0212124460642 -> remove "0" -> 212124460642 (12 digitos, DDD 21,
        // sobra "2124460642" comecando por 2, nao gera candidato adicional
        // por essa regra, mas o "0" tem que sumir do candidato testado)
        $candidatos = $this->service->candidatos('021996731736');
        // 021996731736 -> remove "0" -> 21996731736 (11 digitos, comeca com 9 na 3a posicao) -> prefixa 55 -> 5521996731736
        $this->assertContains('5521996731736', $candidatos);
    }

    public function test_55_duplicado_e_removido_recursivamente(): void
    {
        // Achado real: 5555481126376 = "55" + 55481126376 (o proprio
        // malformado de 11 digitos). Remove o 55 duplicado e tenta de novo
        // nesse resultado -- mas 55481126376 nao bate no padrao "11 digitos
        // comecando por 9 na 3a posicao" (comeca com 4), entao nao produz
        // candidato canonico -- e o esperado, esse caso fica sem candidato.
        $candidatos = $this->service->candidatos('5555481126376');
        $this->assertNotContains('5554981126376', $candidatos, 'nao deve inventar um candidato que a regra nao sustenta');
    }

    public function test_pablo_e_paulo_nunca_se_cruzam(): void
    {
        // Pablo Cesar Da Silva (DDD 19) e Paulo Cesar (DDD 21) -- numeros
        // reais diferentes, nunca podem gerar o mesmo candidato.
        $pablo = $this->service->candidatos('551996731736'); // Pablo, 12 digitos
        $paulo = $this->service->candidatos('21996731736');  // Paulo, 11 digitos

        $this->assertContains('5519996731736', $pablo);
        $this->assertContains('5521996731736', $paulo);
        $this->assertEmpty(array_intersect($pablo, $paulo));
    }

    public function test_codigo_de_pais_estrangeiro_reconhecido_e_canonico(): void
    {
        $this->assertTrue($this->service->ehCanonico('351919303068')); // Portugal
        $this->assertTrue($this->service->ehCanonico('447981567044'));  // Reino Unido
        $this->assertTrue($this->service->ehCanonico('393883846031'));  // Italia
        $this->assertTrue($this->service->ehCanonico('4917675439289')); // Alemanha
        $this->assertTrue($this->service->ehCanonico('526121373773'));  // Mexico
        $this->assertTrue($this->service->ehCanonico('5493415830092')); // Argentina
    }

    public function test_telefone_sem_candidato_nenhum_retorna_vazio(): void
    {
        // Digito genuinamente perdido/trocado -- nao bate em nenhuma regra
        // conhecida, nem BR nem internacional.
        $this->assertSame([], $this->service->candidatos('55481126376'));
        $this->assertFalse($this->service->ehCanonico('55481126376'));
    }

    public function test_numero_brasileiro_com_ddd_que_colide_com_codigo_de_pais_nao_e_falso_positivo(): void
    {
        // Bug fix: DDDs brasileiros 11-19, 33, 34 não devem ser confundidos com
        // códigos de país (1=EUA/Canadá, 33=França, 34=Espanha removidos da lista).
        // Números brasileiros sem o "55" não são canônicos.
        $this->assertFalse($this->service->ehCanonico('19987654321')); // DDD 19, sem o 55
        $this->assertFalse($this->service->ehCanonico('11987654321')); // DDD 11, sem o 55
        $this->assertFalse($this->service->ehCanonico('34987654321')); // DDD 34, sem o 55
        $this->assertFalse($this->service->ehCanonico('33987654321')); // DDD 33, sem o 55
    }

    public function test_55_duplicado_na_fronteira_exata_de_13_caracteres_produz_candidato(): void
    {
        // 13 caracteres exatos -- e a fronteira que diferenciava a guarda
        // antiga (> 13, bloqueava este caso) da nova (>= 13, deixa rodar).
        // Verificado manualmente: sem a correcao, candidatos() retorna [].
        $telefone = '5502161234567';
        $this->assertSame(13, strlen($telefone));

        $candidatos = $this->service->candidatos($telefone);

        $this->assertContains('5521961234567', $candidatos);
    }
}
