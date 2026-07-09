<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SpintaxVariavel extends Model
{
    protected $table    = 'spintax_variaveis';
    protected $fillable = ['tenant_id', 'nome', 'label', 'opcoes'];

    public static array $defaults = [
        'abertura_casual' => [
            'label'  => 'Abertura casual',
            'opcoes' => "Tudo bem com você?\nPassando rapidinho por aqui...\nOpa, tudo certo?\nComo estão as coisas?\nTudo bem aí?",
        ],
        'abertura_empatica' => [
            'label'  => 'Abertura empática',
            'opcoes' => "Sei que a correria de organizar uma mudança é grande...\nImagino que você esteja com a cabeça cheia com os preparativos...\nSei que olhar orçamentos toma bastante tempo...\nEntendo que você deve estar analisando as opções com calma...",
        ],
        'despedida_casual' => [
            'label'  => 'Despedida casual',
            'opcoes' => "Fico no aguardo, um abraço!\nQualquer dúvida, é só me chamar.\nMe avisa o que achou, tá bom?\nEstou à disposição.\nQualquer coisa, é só falar!",
        ],
        'motivo_contato' => [
            'label'  => 'Motivo do contato',
            'opcoes' => "estou passando para checar se ficou alguma dúvida sobre a nossa proposta.\nvoltei aqui para saber se você conseguiu dar uma olhada nos valores que te mandei.\ntô te chamando rapidinho só pra não deixar o seu atendimento pendente aqui.\nqueria ver contigo como está o andamento da sua decisão.\nvim verificar se você precisa de mais alguma informação para fecharmos.",
        ],
        'gatilho_urgencia' => [
            'label'  => 'Gatilho de urgência',
            'opcoes' => "nossa agenda para os próximos dias está se esgotando rápido.\njá estou com poucos caminhões disponíveis para a data que você precisa.\na agenda dessa semana deu uma apertada e quero garantir sua vaga.\npreciso fechar a rota dos caminhões até amanhã.\nos preços de tabela podem sofrer reajuste em breve.",
        ],
        'reforco_valor' => [
            'label'  => 'Reforço de valor',
            'opcoes' => "Vale lembrar que nossa equipe embala tudo com o maior cuidado.\nSó reforçando que nosso serviço inclui seguro total para a sua tranquilidade.\nLembrando que o nosso foco é garantir que seus móveis cheguem intactos e sem estresse.\nComo te falei, temos equipe especializada em desmontagem e montagem.",
        ],
        'cta_fechamento' => [
            'label'  => 'CTA de fechamento',
            'opcoes' => "O que acha de darmos o próximo passo e bloquearmos a sua data?\nPodemos seguir com o agendamento?\nQual é o seu prazo máximo para tomar essa decisão?\nO que está faltando para fecharmos negócio hoje?\nTem algo que eu possa fazer para melhorarmos essa proposta?",
        ],
        'termo_servico' => [
            'label'  => 'Termo para o serviço',
            'opcoes' => "a sua mudança\no transporte dos seus móveis\no seu frete\na logística da sua casa\no seu serviço",
        ],
    ];

    public function sortear(): string
    {
        $opcoes = array_values(array_filter(array_map('trim', explode("\n", $this->opcoes))));

        return $opcoes ? $opcoes[array_rand($opcoes)] : '';
    }

    public static function sorteio(int $tenantId, string $nome): string
    {
        $variavel = static::where('tenant_id', $tenantId)->where('nome', $nome)->first();

        if ($variavel) {
            return $variavel->sortear();
        }

        if (isset(static::$defaults[$nome])) {
            $opcoes = array_values(array_filter(array_map('trim', explode("\n", static::$defaults[$nome]['opcoes']))));

            return $opcoes ? $opcoes[array_rand($opcoes)] : '';
        }

        return '';
    }

    /** Retorna todas as variáveis (defaults + custom do tenant) como arrays de opções. */
    public static function getTodasParaTenant(int $tenantId): array
    {
        $saved  = static::where('tenant_id', $tenantId)->get()->keyBy('nome');
        $result = [];

        foreach (static::$defaults as $nome => $default) {
            $variavel        = $saved->get($nome);
            $opcoesText      = $variavel ? $variavel->opcoes : $default['opcoes'];
            $result[$nome]   = array_values(array_filter(array_map('trim', explode("\n", $opcoesText))));
        }

        foreach ($saved as $nome => $variavel) {
            if (! isset(static::$defaults[$nome])) {
                $result[$nome] = array_values(array_filter(array_map('trim', explode("\n", $variavel->opcoes))));
            }
        }

        return $result;
    }
}
