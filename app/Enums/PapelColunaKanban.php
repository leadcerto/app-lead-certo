<?php

namespace App\Enums;

enum PapelColunaKanban: string
{
    case Entrada = 'entrada';
    case EmAndamento = 'em_andamento';
    case Encerramento = 'encerramento';
    case TransferenciaHumana = 'transferencia_humana';

    public function label(): string
    {
        return match ($this) {
            self::Entrada => 'Entrada',
            self::EmAndamento => 'Em Andamento',
            self::Encerramento => 'Encerramento',
            self::TransferenciaHumana => 'Transferência Humana',
        };
    }

    public function descricao(): string
    {
        return match ($this) {
            self::Entrada => 'Onde tickets novos chegam. Quando o lead responde pela primeira vez, o ticket avança sozinho pra próxima coluna. Só pode haver 1 por Kanban.',
            self::EmAndamento => 'Coluna neutra — nenhuma automação especial além do que você configurar (IA, sequência, botão rápido).',
            self::Encerramento => 'Marca o ticket como encerrado. Se o lead voltar a falar, a IA decide se reabre ou mantém encerrado.',
            self::TransferenciaHumana => 'Tira o ticket da automação e passa pro atendimento humano.',
        };
    }

    public function objetivoExemplo(): string
    {
        return match ($this) {
            self::Entrada => 'Ex: Capturar o interesse inicial, coletar nome e o que o lead precisa, e iniciar o relacionamento com simpatia.',
            self::EmAndamento => 'Ex: Aprofundar as informações necessárias e conduzir o lead para a próxima etapa do atendimento.',
            self::Encerramento => 'Ex: Registrar o motivo do encerramento, agradecer o contato e deixar a porta aberta para o futuro.',
            self::TransferenciaHumana => 'Ex: Conversa que precisa de atenção humana direta — sem automação de IA nesta coluna.',
        };
    }

    public function promptExemplo(): string
    {
        return match ($this) {
            self::Entrada => 'Ex: Você é o atendente da empresa. O lead acabou de entrar em contato. Colete as informações iniciais com simpatia, sem prometer preço ainda.',
            self::EmAndamento => 'Ex: O lead já demonstrou interesse. Aprofunde as informações necessárias e seja consultivo.',
            self::Encerramento => 'Ex: O atendimento foi encerrado. Agradeça o contato e, se o lead voltar a falar, avalie se é uma despedida ou um interesse real de retomar.',
            self::TransferenciaHumana => '',
        };
    }
}
