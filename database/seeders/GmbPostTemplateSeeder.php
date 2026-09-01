<?php

namespace Database\Seeders;

use App\Models\GmbPostTemplate;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class GmbPostTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $tenants = Tenant::all();

        $templatesPadrao = [
            [
                'categoria'       => 'promocoes',
                'titulo_template' => 'Oferta Especial da Semana no {bairro}',
                'texto_template'  => "🔥 PROMOÇÃO EXCLUSIVA EM {bairro} E REGIÃO!\n\nPrecisando de atendimento rápido, confiável e com a melhor condição do mercado? A {empresa} preparou condições imperdíveis para esta semana.\n\n✅ Atendimento personalizado e ágil\n✅ Qualidade garantida com quem tem experiência em {cidade}\n✅ Orçamento sem compromisso pelo WhatsApp\n\n👉 Não perca tempo, clique no botão abaixo ou ligue agora mesmo para garantir a sua vaga!",
                'cta_tipo_padrao' => 'CALL',
            ],
            [
                'categoria'       => 'servicos',
                'titulo_template' => 'Soluções Completas e Profissionais em {bairro}',
                'texto_template'  => "Procurando excelência e pontualidade em {bairro}?\n\nNa {empresa}, cuidamos de cada detalhe com máxima segurança, dedicação e equipe qualificada. Seja para pequenas demandas ou grandes projetos em {cidade}, estamos prontos para atender você!\n\n⭐ Agilidade na execução\n⭐ Equipe de confiança\n⭐ Preço justo e transparente\n\nFale com nosso time agora mesmo!",
                'cta_tipo_padrao' => 'LEARN_MORE',
            ],
            [
                'categoria'       => 'dicas',
                'titulo_template' => 'Dica de Ouro do Especialista para {cidade}',
                'texto_template'  => "💡 DICA RÁPIDA DA {empresa}!\n\nVocê sabia que planejar com antecedência pode economizar até 30% no seu orçamento em {bairro}? \n\nAntes de contratar qualquer serviço em {cidade}, sempre confira a reputação, as avaliações no Google e exija transparência nas condições. Nós da {empresa} prezamos pelo seu sossego e confiança!\n\nTem alguma dúvida? Mande uma mensagem ou ligue para nossa equipe!",
                'cta_tipo_padrao' => 'CALL',
            ],
            [
                'categoria'       => 'depoimentos',
                'titulo_template' => 'Quem Contrata em {bairro} Recomenda a {empresa}',
                'texto_template'  => "⭐⭐⭐⭐⭐ 'Atendimento impecável, equipe extremamente pontual e atenciosa!' — esse é o feedback que nos move todos os dias em {bairro} e em toda {cidade}.\n\nNosso compromisso é entregar a melhor experiência para você do início ao fim.\n\nVenha você também para a {empresa} e comprove a diferença de um serviço 5 estrelas!",
                'cta_tipo_padrao' => 'LEARN_MORE',
            ],
            [
                'categoria'       => 'institucional',
                'titulo_template' => 'Referência e Confiança em {bairro}, {cidade}',
                'texto_template'  => "📍 A {empresa} tem orgulho de estar presente no dia a dia dos moradores e empresas de {bairro}!\n\nCom estrutura moderna e foco total na satisfação do cliente, somos a sua escolha certa em {cidade}.\n\nSalvamos o seu dia com soluções práticas e seguras. Entre em contato e converse com nosso atendimento!",
                'cta_tipo_padrao' => 'CALL',
            ],
        ];

        foreach ($tenants as $tenant) {
            foreach ($templatesPadrao as $tpl) {
                GmbPostTemplate::withoutGlobalScopes()->firstOrCreate(
                    [
                        'tenant_id'       => $tenant->id,
                        'titulo_template' => $tpl['titulo_template'],
                    ],
                    [
                        'categoria'       => $tpl['categoria'],
                        'texto_template'  => $tpl['texto_template'],
                        'cta_tipo_padrao' => $tpl['cta_tipo_padrao'],
                        'ativo'           => true,
                    ]
                );
            }
        }
    }
}
