<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contato extends Model
{
    use SoftDeletes;

    protected $table = 'contatos';

    protected $fillable = [
        // Identificação
        'telefone', 'telefone_2', 'tipo_telefone', 'tipo_telefone_2',
        'email', 'email_2',
        // Nome
        'nome', 'nome_do_meio', 'sobrenome', 'prefixo', 'sufixo', 'apelido',
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

    protected function casts(): array
    {
        return [
            'opt_out'     => 'boolean',
            'bloqueado'   => 'boolean',
            'aniversario' => 'date',
            'tags'        => 'array',
        ];
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(TicketAtendimento::class, 'contato_id');
    }
}
