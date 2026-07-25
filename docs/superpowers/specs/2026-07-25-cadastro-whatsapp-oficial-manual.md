# Manual — Cadastro da Empresa e Concessão de Permissões
## API Oficial do WhatsApp (Meta Cloud API) — Fluxo de Embedded Signup

> Manual funcional — descreve o fluxo real que o dono da empresa precisa percorrer para conectar seu número oficial de WhatsApp ao Lead Certo, o que a página de cadastro do Lead Certo precisa pedir/mostrar, e o que é tecnicamente impossível de automatizar (e por quê). Não contém código nem schema de banco.
>
> Contexto: complementa o manual do Push Engine (`2026-07-25-push-engine-persona-manual.md`), que já apontava a integração com a API Oficial como módulo futuro para o número principal da empresa.

---

## 1. Por que esse fluxo não pode ser 100% automatizado

Antes de descrever os passos, um ponto que não é um detalhe técnico qualquer — é o desenho de segurança da própria Meta:

> **Só o dono ou um administrador autorizado do Business Portfolio da empresa pode iniciar e concluir esse cadastro.** O processo usa o Facebook Login for Business — uma tela de autorização real, da própria Meta, onde a pessoa loga com a conta dela e clica "permitir".

Isso significa que **nenhuma automação, MCP ou IA consegue concluir esse cadastro no lugar do dono da empresa** — nem a Lead Certo, nem qualquer ferramenta de terceiros. É proposital: é o mecanismo que impede alguém de conceder acesso a um negócio que não é seu. Verificação de empresa (Business Verification) exige documento real (CNPJ, comprovante) vinculado à pessoa jurídica.

O que a Lead Certo pode e deve fazer é **conduzir o dono por esse fluxo da forma mais simples possível**, com uma página própria que inicia o processo e recebe o resultado.

## 2. Visão Geral do Fluxo (Embedded Signup)

```
[Dono clica em "Conectar WhatsApp Oficial" na página do Lead Certo]
        ↓
[Popup abre — login com a conta Facebook do dono da empresa]
        ↓
[Dono escolhe ou cria o Business Portfolio da empresa]
        ↓
[Dono informa dados da empresa: razão social, endereço, site, telefone]
        ↓
[Dono escolhe ou cria a WhatsApp Business Account (WABA)]
        ↓
[Dono escolhe ou cria o perfil da empresa no WhatsApp (nome exibido, foto, categoria)]
        ↓
[Dono seleciona/cadastra o número de telefone que será usado]
        ↓
[Confirmação — o popup fecha e devolve ao Lead Certo os identificadores da conta]
        ↓
[Lead Certo usa esses identificadores para subscrever o número no seu próprio
 webhook e gerar um token de acesso de longa duração, guardado com segurança]
```

## 3. O que a Página de Cadastro do Lead Certo precisa mostrar ao Dono

### 3.1 Antes de clicar em "Conectar"

A página deve deixar claro, em linguagem simples, o que vai acontecer:
- Que ele vai ser redirecionado para uma tela oficial da Meta/Facebook (não do Lead Certo).
- Que ele precisa ter (ou vai criar ali) uma conta de administrador do Business Portfolio da empresa.
- Que ele vai precisar ter em mãos: nome legal da empresa, endereço, site, e o número de telefone que será o WhatsApp oficial.
- Que, se esse número já estiver em uso no aplicativo comum do WhatsApp ou no WhatsApp Business App do celular, ele **deixará de funcionar nesses apps** assim que for migrado para a API Oficial — toda a comunicação passa a ser só pela plataforma (ver seção 6).

### 3.2 Durante o processo

O fluxo de tela em si é conduzido inteiramente pela Meta (o popup mencionado no diagrama) — o Lead Certo não constrói nem controla essas telas, só inicia o processo e recebe o resultado ao final.

### 3.3 Depois de concluído

A página deve confirmar visualmente que a conexão foi bem-sucedida, mostrando:
- O nome e número conectados.
- Se a verificação de empresa (Business Verification) ainda está pendente ou já foi concluída — enquanto pendente, o número pode operar com limitações de volume (ver seção 5).

## 4. Permissões Concedidas nesse Processo

Quando o dono autoriza no popup da Meta, ele está concedendo ao Lead Certo (tecnicamente, ao App Meta da plataforma) permissão para, em nome daquele número:

| Permissão | O que ela habilita |
|---|---|
| Gestão de mensagens (`whatsapp_business_messaging`) | Enviar e receber mensagens em nome do número da empresa |
| Gestão da conta do WhatsApp Business (`whatsapp_business_management`) | Editar dados do perfil, templates de mensagem, configurações da conta |
| Gestão do negócio (`business_management`) | Acesso administrativo ao Business Portfolio, necessário para operar a conta em nome da empresa |

**Importante para o manual do dono:** essas permissões dão ao Lead Certo acesso operacional ao WhatsApp daquele número — é o equivalente, em espírito, a "dar a chave da conta" para a plataforma operar em nome dele. Isso deve estar dito de forma clara e honesta na página, não escondido em letra miúda.

## 5. O que o Lead Certo recebe e precisa guardar com segurança

Ao final do fluxo, a Meta devolve identificadores técnicos (ID da conta, ID do número, e um mecanismo para gerar um token de acesso de longa duração). Esse token é o que permite ao Lead Certo continuar enviando/recebendo mensagens depois, sem o dono precisar logar de novo.

**Regras não-negociáveis sobre esse token:**
- Deve ser armazenado de forma protegida — nunca em texto puro visível em log, tela ou export.
- Nunca deve ser exibido para o próprio dono da empresa (ele não precisa nem deve ver o valor bruto — só precisa saber que "está conectado").
- Deve estar vinculado de forma inequívoca a um único tenant/franqueado — o mesmo cuidado que já existe hoje com o token da Uazapi por tenant.

## 6. Cuidados Operacionais Importantes

### 6.1 O número não pode operar em dois lugares ao mesmo tempo

Um número que já está ativo no WhatsApp comum ou no WhatsApp Business App do celular **precisa ser migrado** — depois da conexão à API Oficial, o aplicativo do celular para aquele número deixa de funcionar para conversas normais. Isso precisa estar muito claro para o dono **antes** de ele iniciar o processo, para não ser pego de surpresa perdendo acesso ao WhatsApp que já usa no dia a dia.

### 6.2 Verificação de Empresa pode demorar

A verificação de empresa (documentos) pode levar de alguns dias até cerca de duas semanas. Enquanto não concluída, o número opera com limite de volume mais baixo (ver a tabela de tiers, seção 6.3). A página do Lead Certo deve deixar visível esse status "em verificação" para o dono não achar que algo deu errado.

### 6.3 Limites de Volume por Tier

Toda conta nova começa num nível de volume limitado, que sobe automaticamente conforme o uso e a qualidade da conta (poucos bloqueios, boa taxa de entrega):

| Tier | Contatos únicos por 24h |
|---|---|
| Tier 1 (inicial) | 1.000 |
| Tier 2 | 10.000 |
| Tier 3 | 100.000 |
| Tier 4 | Sem limite prático |

### 6.4 Mensagens fora da janela de 24h exigem template aprovado

Diferente da Uazapi (texto livre), a API Oficial só permite que a empresa **inicie** uma conversa (fora da janela de 24h desde a última mensagem do cliente) usando um **template de mensagem pré-aprovado pela própria Meta**. Isso precisa entrar na tela de configuração de sequências/campanhas quando esse canal estiver ativo para um tenant — texto livre continua valendo só dentro da janela de resposta.

## 7. Rota Recomendada para Escala: Direto vs. via Parceiro (Tech Provider)

| Rota | Como funciona | Quando faz sentido |
|---|---|---|
| **Direta** | Cada franqueado passa pelo fluxo de Embedded Signup ligado diretamente ao App Meta do Lead Certo | Poucos franqueados, ou fase inicial de testes |
| **Via Parceiro/BSP** | A Lead Certo se cadastra como Tech Provider de um parceiro (ex: 360dialog, Gupshup, Twilio, Infobip), que já resolve boa parte da complexidade de onboarding em escala | Recomendado assim que o número de franqueados crescer — evita que a Lead Certo precise lidar sozinha com toda a burocracia de verificação para cada empresa nova |

## 8. O que Fazer / O que Não Fazer

| ✅ Deve fazer | ❌ Não deve fazer | Por quê |
|---|---|---|
| Explicar antes do clique que o número em uso no app do celular vai parar de funcionar ali | Deixar o dono descobrir isso só depois de concluir o processo | Gera desconfiança e pode causar perda de comunicação da empresa sem aviso |
| Mostrar claramente o status de verificação de empresa (pendente/concluída) | Deixar a tela silenciosa sobre isso, sem explicar por que o volume está limitado | O dono pode achar que o sistema está com problema, quando na verdade é uma limitação temporária esperada |
| Guardar o token de acesso de forma protegida, vinculado a um único tenant | Expor o valor do token em qualquer tela, log ou exportação visível ao usuário | É a credencial que opera o WhatsApp da empresa em nome dela — vazamento é grave |
| Deixar explícito, na própria página, quais permissões estão sendo concedidas e o que elas significam | Esconder isso em termos de uso genéricos, sem explicação em linguagem simples | Transparência é parte do processo de confiança — é a conta da empresa dele que está sendo autorizada |
| Considerar a rota via parceiro/Tech Provider assim que o volume de franqueados justificar | Insistir na rota direta indefinidamente, franqueado por franqueado | A complexidade de onboarding direto não escala bem além de poucos tenants |

---

Sources consultadas para este manual:
- [WhatsApp Cloud API Get Started - Meta for Developers](https://developers.facebook.com/documentation/business-messaging/whatsapp/get-started)
- [Embedded Signup - Meta for Developers](https://developers.facebook.com/documentation/business-messaging/whatsapp/embedded-signup/overview)
- [WhatsApp Business API 2026: Complete Guide](https://www.messagecentral.com/blog/whatsapp-business-api-complete-guide)
