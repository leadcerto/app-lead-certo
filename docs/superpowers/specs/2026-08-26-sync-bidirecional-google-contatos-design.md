# Sync Bidirecional Google Contatos ↔ Lead Certo — Design

> **Status:** Design concluído, aguardando revisão do Leonardo antes do plano de implementação.
> **Contexto:** repo `leadcerto-app`. Pedido original (2026-08-16, retomado em 2026-08-26).

## 1. Problema

O Leonardo, ao vivo:

> "eu tenho várias etiquetas dentro da agenda de contatos do Google da empresa
> cliente... eu não posso ficar alterando as etiquetas que a empresa usa lá no
> Google dela pra não atrapalhar a vida da empresa. [...] eu posso estar
> alterando informações do cliente aqui na nossa base de dados, como ele
> também pode estar alterando lá do Google. Como é que eu consigo manter isso
> atualizado? Porque muitas vezes está aparecendo aqui pra mim, por exemplo,
> como sem nome. Aí lá no frete, o cara do frete vai lá e bota o nome do cara
> de verdade. E aqui aparece como sem nome e lá aparece com o nome."

Dois problemas concretos:

1. **Sync de dados bidirecional incompleto** — o time do cliente edita
   contatos direto no Google Contatos dele (ex: preenche o nome real de um
   lead que estava "Sem Nome"), e essa edição não volta de forma confiável
   pra base da Lead Certo.
2. **Etiquetas/labels do Google são território do cliente** — não podem ser
   criadas, renomeadas ou reorganizadas pelo nosso sync, só respeitadas.

## 2. Comportamento atual (mapeado em 2026-08-26, antes deste design)

**Lead Certo → Google (push):**
- Contato novo vindo da agenda do WhatsApp (`SincronizarAgendaWhatsAppJob`,
  `SincronizarContatosWhatsApp`, `ImportarParticipantesGrupos`) → dispara
  `PushContatoParaGoogleJob` → `GoogleService::criarContato()` (cria do zero).
- Atendente edita nome/sobrenome/e-mail no painel (`ContatosController::atualizar`)
  → `sincronizarComGoogle()` → `GoogleService::enriquecerContato()` (PATCH,
  sobrescreve o que está no Google **imediatamente**, sem checar se mudou lá
  desde o último pull).

**Google → Lead Certo (pull):**
- Cron `contatos:sincronizar-google`, hoje a cada 6h, delta sync via
  SyncToken → `ContatoSyncService::processarPessoa()` — merge campo a campo,
  só preenche o que está **vazio** localmente (ou "Sem Nome"/igual ao
  telefone, via `Contato::semNomeReal()`, corrigido em 2026-08-26). Nunca
  sobrescreve campo já preenchido.

**Conflito hoje:** não existe. Push sempre vence saindo (PATCH direto).
Pull só preenche vazio. Resultado prático: uma correção feita pelo cliente no
Google, depois que o campo local já tem qualquer valor, **se perde pra
sempre** — nunca é lida de volta.

**Etiquetas:** confirmado que nenhum método (`criarContato`,
`enriquecerContato`, `processarPessoa`) toca em `memberships`/`contactGroups`
hoje. Este design mantém essa garantia — nenhuma leitura ou escrita de
`contactGroups` em nenhum ponto novo.

**Padrão reaproveitável já existente:** `ContatosController::atualizar()` já
tem uma regra de governança pra conflito — edição de nome por
usuário não-privilegiado vs. "master" divergente vai pra
`nome_sugerido` + `auditoria_pendente` em `VinculoContatoTenant`, revisado na
tela do Auditor (`AuditorController`). Este design generaliza esse mesmo
mecanismo pra origem "Google", não só pra conflito interno.

## 3. Objetivo deste design

Definir como o sync passa a:
1. Trazer de volta uma correção humana feita no Google, mesmo quando o campo
   local já tem valor — sem sobrescrever uma edição humana feita aqui.
2. Resolver o caso em que os dois lados têm edição humana e os valores
   divergem, sem decidir sozinho qual "vence".
3. Nunca tocar em etiquetas/`contactGroups`.
4. Reduzir a latência pro caso mais sensível ao tempo: lead novo chegando.

**Fora de escopo:** qualquer mudança em como/quando etiquetas são exibidas
na UI da Lead Certo (não existe hoje, não é criado aqui); alteração do fluxo
de importação em massa (`SincronizarAgendaWhatsAppJob` etc.) além de também
gravar a linha de base (`google_valores_enviados`) depois de criar.

## 4. Campos cobertos

`nome`, `sobrenome`, `empresa`, `email` — todos os campos do `Contato` que
hoje sincronizam nos dois sentidos. `telefone` fica de fora: é a chave de
identidade usada pra achar/casar o contato, não um campo de conteúdo
sujeito a "conflito" da mesma forma.

## 5. Modelo de dados

Três colunas novas em `vinculos_contato_tenant` (JSON, mesmo padrão já usado
em `WhatsappCanal.config`), substituindo/generalizando `google_given_name` +
`nome_sugerido` + `auditoria_pendente` (que saem):

```php
Schema::table('vinculos_contato_tenant', function (Blueprint $table) {
    // Último valor de cada campo que NÓS empurramos pro Google. Linha de
    // base pro pull saber se algo mudou lá desde nosso último envio.
    // Formato: {"nome": "Marcia Souza", "empresa": "Fretes ABC"}
    $table->json('google_valores_enviados')->nullable()->after('google_etag');

    // Timestamp de quando um HUMANO editou cada campo aqui no Lead Certo
    // (painel /contatos). Edição automática (IA extraindo nome da
    // conversa, import de agenda) NUNCA marca isso.
    // Formato: {"nome": "2026-08-26T14:00:00-03:00"}
    $table->json('campos_editados_humano')->nullable()->after('google_valores_enviados');

    // Fila de conflito: campo com edição humana dos dois lados e valores
    // diferentes. Fica aqui até alguém decidir na tela do Auditor.
    // Formato: {"nome": {"sugerido": "Marcia S. Souza", "origem": "google"}}
    $table->json('campos_pendentes_auditoria')->nullable()->after('campos_editados_humano');
});
```

Uma migration de dados (não só de schema) roda junto, ver seção 8 (Rollout).

**Nota:** o backfill (seção 8) só preenche `campos_editados_humano` — não
preenche `google_valores_enviados` retroativamente (não temos como saber
com certeza o que foi enviado no passado). Isso é seguro por construção:
pra um vínculo legado, `google_valores_enviados[campo]` vem `null`, o que
já cai naturalmente no caminho "diferente da linha de base" do fluxo da
seção 6 — e dali em diante o desfecho depende só de
`campos_editados_humano` (backfillado), exatamente como desenhado. Nenhum
tratamento especial extra é necessário pra vínculo legado.

**Call sites que passam a ler os campos novos em vez dos antigos**
(`google_given_name`, `nome_sugerido`, `auditoria_pendente` saem de uso —
a coluna pode ficar ou ser dropada, decisão de implementação):
`AuditorController` (linhas ~28, 154-217), `Painel/ContatosController`
(linhas ~915-946), `Painel/KanbanController` (linhas ~119-130, 178-179),
`Internal/ContatoController` (linhas ~45-56), `Painel/DashboardController`
(linha ~89).

## 6. Fluxo — Pull (cron, a cada 15 min)

Pra cada campo sincronizado, em `ContatoSyncService::processarPessoa()`:

1. Valor do Google bate com `google_valores_enviados[campo]` (ou os dois
   vazios)? → nada mudou lá desde nosso último envio. Não faz nada.
2. Google ausente/vazio pro campo? → nunca interpretado como "apagar aqui".
   Ignora, não mexe no local.
3. Google tem um valor presente e diferente da linha de base → alguém mexeu
   lá. Verifica `campos_editados_humano[campo]`:
   - **Não marcado** (campo local vazio, placeholder tipo "Sem Nome", ou só
     preenchido automaticamente) → aceita o valor do Google: atualiza o
     campo local **e** atualiza `google_valores_enviados[campo]` pra esse
     novo valor (nova linha de base). Sem necessidade de revisão.
   - **Marcado** (edição humana local) **e** o valor local diverge do que
     veio do Google → **não sobrescreve nada**. Grava em
     `campos_pendentes_auditoria[campo] = {sugerido: valorGoogle, origem:
     'google'}` e **também** atualiza `google_valores_enviados[campo]` pra
     esse novo valor — isso evita recriar a mesma pendência de novo no
     próximo ciclo do cron enquanto ninguém resolveu a anterior.
   - **Marcado**, mas o valor local já bate com o que veio do Google →
     não é conflito de verdade (ex: os dois foram editados pro mesmo valor
     por coincidência). Só atualiza a linha de base.

## 7. Fluxo — Push (edição no painel, `ContatosController::atualizar`)

Continua imediato como hoje (`sincronizarComGoogle()` → `enriquecerContato()`),
com duas adições:
- Marca `campos_editados_humano[campo] = now()` pra cada campo salvo nesta
  requisição (é aqui, o endpoint do painel usado por um usuário logado, que
  sabemos com certeza que foi humano — nunca chamado por job automático).
- Depois de um PATCH bem-sucedido, atualiza
  `google_valores_enviados[campo]` pro valor que acabou de mandar — sem
  isso, o pull nunca teria como distinguir "isso aqui fui eu que mandei" de
  "isso aqui o cliente mudou depois".

`PushContatoParaGoogleJob` (criação automática, vinda de import) também
passa a gravar `google_valores_enviados` depois de criar o contato no
Google — mas **não** marca `campos_editados_humano` (não é edição humana).

## 8. Rollout — backfill conservador

Contatos que já existem hoje não têm `campos_editados_humano` preenchido.
Sem tratar isso, o primeiro sync depois do deploy trataria TODO campo local
já preenchido como "não-humano" e poderia sobrescrever um monte de dado real
com o que estiver no Google na hora.

Uma migration de dados, rodando uma vez após a migration de schema, marca
como humano todo campo que já tem valor preenchido hoje:

```php
// Pseudocódigo da migration de dados (não do schema)
VinculoContatoTenant::whereHas('contato', fn ($q) => /* qualquer campo preenchido */)
    ->chunkById(200, function ($vinculos) {
        foreach ($vinculos as $v) {
            $campos = [];
            foreach (['nome', 'sobrenome', 'empresa', 'email'] as $campo) {
                if (! empty($v->contato->$campo) && ! $v->contato->semNomeReal()) {
                    $campos[$campo] = now()->toIso8601String();
                }
            }
            if ($campos) {
                $v->update(['campos_editados_humano' => $campos]);
            }
        }
    });
```

Trata tudo que já existe como "humano" por segurança — só entra na regra
nova (IA perde pra qualquer edição humana) a partir daqui pra frente.

## 9. Tela do Auditor — generalização

`AuditorController`/`auditor/index.blade.php` hoje só lista/resolve
`nome_sugerido`. Passa a iterar `campos_pendentes_auditoria` (JSON) e listar
cada campo pendente como uma linha própria: campo, valor atual, valor
sugerido, origem ("Google"). Aprovar aplica o valor sugerido ao campo local
(marca como editado-humano também, já que foi uma decisão humana de
aprovar); rejeitar descarta a sugestão e mantém o valor local. Os dois
removem a entrada de `campos_pendentes_auditoria[campo]`.

O card do Kanban (`KanbanController`, hoje mostra `nome_local`/
`auditoria_pendente` só pra nome) passa a mostrar um indicador se
`campos_pendentes_auditoria` tem qualquer entrada, não só nome.

## 10. Busca em tempo real no primeiro contato (lead inicial)

**Por que:** pro caso mais sensível — lead novo chegando (ligação perdida,
primeira mensagem, formulário) — esperar até 15 minutos do próximo cron
ainda é tempo demais quando "tempo é fundamental" pra não perder a venda.

**Gatilho:** hook no evento `created()` de `VinculoContatoTenant` (não de
`Contato` — `Contato` é global entre tenants, só o vínculo sabe qual tenant
e portanto qual `GoogleToken` usar). Dispara um job novo, sem delay:
`EnriquecerContatoNovoViaGoogleJob($vinculoId)`.

**O que o job faz:**
1. Busca o `GoogleToken` do tenant do vínculo — sem token, encerra
   silenciosamente (nem todo tenant tem Google conectado).
2. Chama a Google People API `people:searchContacts` (busca pontual por
   telefone, `readMask=names,phoneNumbers,organizations,emailAddresses`) —
   diferente do `connections.list` usado pelo sync em lote, que lista tudo
   via delta token. Aqui é uma busca direta, sem custo de token de sync.
3. Achou um resultado com telefone batendo? Aplica a mesma regra de
   conflito da seção 6 (reaproveita o método, não duplica lógica).
4. Não achou nada, ou telefone não bate exatamente → encerra sem erro
   (contato realmente novo pro cliente também).

Roda em background (fila `default`, sem delay) — não atrasa a resposta pro
app Android/webhook que está aguardando o ticket ser criado.

## 11. Testes a cobrir

- `ContatoSyncService`: pull aceita correção do Google quando local não é
  humano; pull NÃO sobrescreve quando os dois lados são humanos e diferem
  (vai pra `campos_pendentes_auditoria`); pull não recria a mesma pendência
  duas vezes seguidas; campo ausente no Google nunca apaga o local.
  Cobrir os 4 campos (nome, sobrenome, empresa, email), não só nome.
- `ContatosController::atualizar`: edição no painel marca
  `campos_editados_humano` e atualiza `google_valores_enviados` após push
  bem-sucedido.
- `EnriquecerContatoNovoViaGoogleJob`: aplica valor achado via
  `searchContacts`; não faz nada sem `GoogleToken`; não faz nada sem
  resultado.
- `AuditorController`: aprova/rejeita um campo específico dentre vários
  pendentes no mesmo contato, sem afetar os outros.
- Migration de dados: backfill marca como humano só campo já preenchido
  (não mexe em campo vazio/placeholder).

## 12. Etiquetas — garantia mantida

Nenhum ponto deste design lê ou escreve `contactGroups`/`memberships`. A
busca (`searchContacts`) e o pull (`connections.list`) usam `readMask`
explícito, sem incluir `memberships` — mesmo que a API devolvesse por
padrão, não pedimos.
