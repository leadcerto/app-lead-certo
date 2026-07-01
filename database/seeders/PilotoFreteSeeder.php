<?php

namespace Database\Seeders;

use App\Models\SdrPersona;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class PilotoFreteSeeder extends Seeder
{
    public function run(): void
    {
        // ---------------------------------------------------------------
        // Tenant — Frete Rio
        // ---------------------------------------------------------------
        $tenant = Tenant::firstOrCreate(
            ['nicho' => 'frete'],
            [
                'nome'     => 'Frete Rio',
                'status'   => 'ativo',
                'email'    => 'contato@frete.rio.br',
                'dominio'  => 'frete.rio.br',
                'telefone' => '21999999999',
            ]
        );

        // ---------------------------------------------------------------
        // Usuários
        // ---------------------------------------------------------------

        // Admin global Lead Certo (sem tenant)
        $admin = User::firstOrCreate(
            ['email' => 'leadecerto@gmail.com'],
            [
                'tenant_id' => null,
                'nome'      => 'Admin Lead Certo',
                'password'  => Hash::make('admin1234'),
                'perfil'    => 'admin',
                'ativo'     => true,
            ]
        );
        // Garante senha atualizada mesmo que já existisse
        $admin->update(['password' => Hash::make('admin1234'), 'perfil' => 'admin', 'tenant_id' => null]);

        // Dono do tenant frete.rio.br
        $dono = User::firstOrCreate(
            ['email' => 'fretesdoleo@gmail.com'],
            [
                'tenant_id' => $tenant->id,
                'nome'      => 'Leonardo',
                'password'  => Hash::make('frete1234'),
                'perfil'    => 'dono',
                'ativo'     => true,
            ]
        );
        $dono->update(['password' => Hash::make('frete1234'), 'perfil' => 'dono', 'tenant_id' => $tenant->id]);

        // Vendedor do tenant frete.rio.br
        $vendedor = User::firstOrCreate(
            ['email' => 'vendedor@frete.rio.br'],
            [
                'tenant_id' => $tenant->id,
                'nome'      => 'Leonardo',
                'password'  => Hash::make('frete123'),
                'perfil'    => 'vendedor',
                'ativo'     => true,
            ]
        );
        $vendedor->update(['password' => Hash::make('frete123'), 'perfil' => 'vendedor', 'tenant_id' => $tenant->id]);

        // ---------------------------------------------------------------
        // SDR Persona — João (atendimento WhatsApp frete)
        // updateOrCreate permite atualizar o prompt via re-run do seeder
        // ---------------------------------------------------------------
        SdrPersona::updateOrCreate(
            ['tenant_id' => $tenant->id, 'nome_interno' => 'joao-frete'],
            [
                'nome_display'    => 'João',
                'genero'          => 'masculino',
                'idade_aparente'  => 30,
                'localidade'      => 'Rio de Janeiro, RJ',
                'tom_de_voz'      => 'direto',
                'tier'            => 'simples',
                'is_default'      => true,
                'ativo'           => true,
                'system_prompt'   => <<<'PROMPT'
Você é João, atendente da Frete Rio — empresa especializada em fretes, mudanças e transporte de cargas no Rio de Janeiro e região.

IDENTIDADE
- Você é um humano chamado João, não uma IA. Se perguntarem, diga que é atendente.
- Tom: amigável, direto, sem formalidade excessiva. Como uma conversa de WhatsApp.
- Nunca use listas com bullets ou numeração. Escreva em frases curtas e naturais.
- Mensagens curtas. Máximo 2-3 frases por mensagem.

BOAS VINDAS (primeiro contato)
- Quando for a primeira mensagem de um novo cliente, cumprimente pelo nome se souber, senão cumprimente de forma neutra.
- Exemplo: "Oi! Aqui é o João da Frete Rio. Posso te ajudar com um frete ou mudança?"
- Não faça mais de uma pergunta na mensagem de boas-vindas.

NOME DO CLIENTE
- Se não souber o nome do cliente, pergunte de forma natural durante a conversa ("Com quem eu tô falando?")
- Depois de saber o nome, use-o ocasionalmente para tornar o atendimento mais pessoal.

FUNÇÃO
- Qualificar o lead: entender o que precisa ser transportado, origem, destino, data e volume.
- Transferir para vendedor humano quando o cliente estiver qualificado ou pedir falar com humano.
- Nunca dar preços exatos. Dizer que vai verificar e confirmar assim que coletar os dados.

DADOS A COLETAR (um de cada vez, sem pressa)
1. O que vai ser transportado? (móveis, mudança completa, carga, equipamentos?)
2. Endereço de origem (bairro ou cidade)
3. Endereço de destino (bairro ou cidade)
4. Data prevista para o frete
5. Tem elevador ou escada?

REGRAS
- Uma pergunta por mensagem. Nunca mais de uma ao mesmo tempo.
- Se o cliente responder com áudio, agradeça e siga o assunto normalmente.
- Quando tiver todos os dados necessários, diga: "Perfeito! Deixa eu verificar a disponibilidade e já te passo o valor."
- Após essa frase, o atendimento passa para o vendedor humano.
PROMPT
            ]
        );

        $this->command->info("Piloto Frete — tenant_id: {$tenant->id}");
        $this->command->info('Admin:    leadecerto@gmail.com    / admin1234');
        $this->command->info('Dono:     fretesdoleo@gmail.com  / frete1234');
        $this->command->info('Vendedor: vendedor@frete.rio.br  / frete123');
    }
}
