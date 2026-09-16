<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contato extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'contatos';

    protected $fillable = [
        // Identificação
        'telefone', 'telefone_2', 'tipo_telefone', 'tipo_telefone_2',
        'email', 'email_2',
        // Nome — nome_do_meio NÃO entra aqui de propósito, ver getNomeDoMeioAttribute()
        'nome', 'sobrenome', 'prefixo', 'sufixo', 'apelido', 'nome_revisado_ia_em',
        // Documentos
        'cpf', 'rg', 'passaporte',
        // Pessoal
        'genero', 'estado_civil', 'nacionalidade', 'foto_url',
        // Profissional
        'profissao', 'empresa', 'departamento', 'tipo_empresa',
        // Data
        'aniversario',
        // Endereço principal
        'endereco', 'cidade', 'estado', 'cep', 'pais',
        // Endereço secundário
        'endereco_2', 'cidade_2', 'estado_2', 'cep_2', 'pais_2',
        // Online
        'website', 'instagram', 'facebook', 'linkedin', 'twitter', 'tiktok', 'youtube', 'whatsapp_negocio',
        // Extra
        'observacoes',
        // Controle
        'origem', 'opt_out', 'bloqueado', 'tipo_contato',
        // Classificação
        'tipo_pessoa', 'status_validacao',
        // Lead Certo
        'tipo', 'score', 'tags',
        // Pessoa Jurídica
        'cnpj', 'razao_social', 'nome_fantasia', 'inscricao_estadual', 'inscricao_municipal',
    ];

    /**
     * Protocolo de nomes do Lead Certo (migration cria_cargo_auditor_contatos_ia,
     * confirmado com o Leonardo 2026-09-16): Nome do meio é sempre o ID do próprio
     * contato, nunca um dado editável — ignora completamente o que estiver gravado
     * na coluna (lixo histórico de sync/import) e nunca aceita sobrescrita via
     * mass assignment (removido de $fillable).
     */
    public function getNomeDoMeioAttribute(): string
    {
        return (string) $this->id;
    }

    protected function casts(): array
    {
        return [
            'opt_out'             => 'boolean',
            'bloqueado'           => 'boolean',
            'aniversario'         => 'date',
            'tags'                => 'array',
            'nome_revisado_ia_em' => 'datetime',
        ];
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(TicketAtendimento::class, 'contato_id');
    }

    /**
     * "Sem Nome" e o telefone repetido no campo nome sao placeholders, não um
     * nome de verdade — usado em vários pontos (sync do Google, formulário,
     * mesclagem de duplicatas) pra decidir se um nome recém-descoberto deve
     * substituir o que já está salvo.
     */
    public function semNomeReal(): bool
    {
        $nome = trim((string) $this->nome);

        if ($nome === '' || $nome === $this->telefone || mb_strtolower($nome) === 'sem nome' || mb_strtolower($nome) === 'sem_nome') {
            return true;
        }

        return ! app(\App\Services\NomeExtracaoService::class)->pushNameValido($nome);
    }

    /**
     * Pedido do Leonardo (2026-08-28): o time do cliente marca um contato
     * numa etiqueta do Google Contatos dele (ex: "Pessoal", "Fornecedor") pra
     * tirar alguém que não é lead de vendas da esteira comercial —
     * ContatoSyncService::detectarTipoContato() já lê isso e grava em
     * tipo_contato. "lead" (o valor padrão de todo cadastro novo) nunca
     * exclui; qualquer outro tipo preenchido exclui. Checado em todo ponto
     * que cria um TicketAtendimento novo pra um lead — nunca em pontos que
     * reabrem/recuperam um ticket já existente.
     */
    public function excluidoDoFunilComercial(): bool
    {
        return (bool) $this->tipo_contato && $this->tipo_contato !== 'lead';
    }
}
