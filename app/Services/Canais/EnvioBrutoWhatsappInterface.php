<?php

namespace App\Services\Canais;

/**
 * Fase 1 do planejamento do canal WhatsApp Messenger próprio (23/09):
 * HumanizacaoService (o "Músculo" do motor de humanização — ver
 * leadcerto/integracoes/whatsapp-uazapi/regra-geral-de-envio-de-mensagens-no-whatsapp.md)
 * era hard-wired em UazapiService, apesar da própria arquitetura descrita no
 * manual ser universal (Mente × Músculo, não específica de provedor). Essa
 * interface é o contrato mínimo que HumanizacaoService precisa pra enviar um
 * balão de texto e o indicador de presença — qualquer canal não-oficial
 * (Uazapi hoje, o canal Messenger próprio amanhã) implementa e passa como
 * parâmetro em processar().
 */
interface EnvioBrutoWhatsappInterface
{
    public function enviarTexto(string $instanceToken, string $numero, string $texto): bool;

    public function setPresenca(string $instanceToken, string $presenca, ?string $para = null): bool;
}
