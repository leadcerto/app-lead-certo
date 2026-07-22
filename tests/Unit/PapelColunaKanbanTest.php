<?php

namespace Tests\Unit;

use App\Enums\PapelColunaKanban;
use PHPUnit\Framework\TestCase;

class PapelColunaKanbanTest extends TestCase
{
    public function test_valores_do_enum(): void
    {
        $this->assertSame('entrada', PapelColunaKanban::Entrada->value);
        $this->assertSame('em_andamento', PapelColunaKanban::EmAndamento->value);
        $this->assertSame('encerramento', PapelColunaKanban::Encerramento->value);
        $this->assertSame('transferencia_humana', PapelColunaKanban::TransferenciaHumana->value);
    }

    public function test_todo_papel_tem_label_descricao_e_objetivo_exemplo_nao_vazios(): void
    {
        foreach (PapelColunaKanban::cases() as $papel) {
            $this->assertNotSame('', $papel->label());
            $this->assertNotSame('', $papel->descricao());
            $this->assertNotSame('', $papel->objetivoExemplo());
        }
    }

    public function test_prompt_exemplo_de_transferencia_humana_e_vazio_pois_nao_usa_ia(): void
    {
        $this->assertSame('', PapelColunaKanban::TransferenciaHumana->promptExemplo());
    }

    public function test_prompt_exemplo_dos_demais_papeis_nao_e_vazio(): void
    {
        $this->assertNotSame('', PapelColunaKanban::Entrada->promptExemplo());
        $this->assertNotSame('', PapelColunaKanban::EmAndamento->promptExemplo());
        $this->assertNotSame('', PapelColunaKanban::Encerramento->promptExemplo());
    }
}
