# Agendamento de Postagens — Facebook & Instagram (Meta)

**Data:** 04/09/2026
**Status:** Aprovado, pronto para plano de implementação

## 1. Contexto

O Lead Certo já tem um motor completo de agendamento de postagens para o
Google Meu Negócio (`gmb_posts` + `GmbPostController` + `GmbPostPublishService`
+ comando agendado `gmb:publicar-posts`), com uma tela de calendário semanal
(Total da Semana / Agendados / Publicados / Falhas, navegação por semana,
botão "+ Individual").

A integração Meta (Facebook & Instagram) já foi corrigida nesta mesma sessão
para vincular corretamente páginas/contas por tenant (ver commits
`e27d6b0` e seguintes) e o `MetaService` já expõe:

- `publicarPostFacebookPage(string $pageId, string $pageAccessToken, array $dados): ?string`
- `publicarPostInstagram(string $igUserId, string $accessToken, array $dados): ?string`

Também já existe `meta_campanhas_gatilho` (Comment-to-DM): um comentário
com uma palavra-chave em um post específico (`post_id_especifico`) dispara
uma resposta pública + mensagem no Direct/Messenger.

Falta: a tabela de agendamento em si, a tela e o comando agendado —
seguindo o mesmo padrão do GMB, mas com as particularidades do Facebook/
Instagram (canal, botão CTA condicional, gatilho de comentário embutido).

## 2. Modelo de dados

Nova tabela `meta_posts`, migration `create_meta_posts_table`:

```php
Schema::create('meta_posts', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
    $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

    // Canal e destino
    $table->enum('canal_alvo', ['facebook', 'instagram', 'ambos']);
    $table->foreignId('meta_pagina_id')->nullable()->constrained('meta_paginas')->nullOnDelete();
    $table->foreignId('meta_conta_instagram_id')->nullable()->constrained('meta_contas_instagram')->nullOnDelete();

    // Conteúdo
    $table->text('texto');
    $table->string('imagem_url', 500)->nullable();

    // CTA — só usado quando canal_alvo inclui facebook (Instagram não suporta botão em post orgânico)
    $table->enum('cta_tipo', ['NENHUM', 'BOOK', 'ORDER', 'SHOP', 'LEARN_MORE', 'SIGN_UP', 'CALL'])->default('NENHUM');
    $table->string('cta_url', 500)->nullable();

    // Gatilho Comment-to-DM embutido (mesmo shape de meta_campanhas_gatilho)
    $table->enum('modo_gatilho', ['nenhum', 'qualquer_comentario', 'palavra_chave'])->default('nenhum');
    $table->json('palavras_chave')->nullable();
    $table->string('resposta_publica_comentario', 500)->nullable();
    $table->text('mensagem_direct')->nullable();

    // Agendamento e ciclo de vida
    $table->dateTime('data_agendada');
    $table->dateTime('publicado_em')->nullable();
    $table->enum('status', ['agendado', 'publicando', 'publicado', 'falha', 'cancelado'])->default('agendado');

    // Retorno da API da Meta
    $table->string('facebook_post_id')->nullable();
    $table->string('instagram_media_id')->nullable();
    $table->text('log_erro')->nullable();
    $table->unsignedInteger('tentativas')->default(0);

    $table->timestamps();

    $table->index(['status', 'data_agendada'], 'idx_meta_posts_agendamento');
    $table->index(['tenant_id'], 'idx_meta_posts_tenant');
});
```

Migration adicional, `add_meta_post_id_to_meta_campanhas_gatilho_table`:

```php
Schema::table('meta_campanhas_gatilho', function (Blueprint $table) {
    $table->foreignId('meta_post_id')->nullable()->after('id')
        ->constrained('meta_posts')->nullOnDelete();
});
```
(Rastreabilidade: saber que aquele gatilho nasceu de um agendamento. Não é
obrigatório para o funcionamento — é `nullOnDelete` para não travar a
exclusão de um post antigo.)

## 3. Motor de publicação

`app/Services/MetaPostPublishService.php`, espelhando `GmbPostPublishService`:

Importante para o botão "Publicar Agora" em posts que falharam
parcialmente (ex.: `ambos`, Facebook publicou mas Instagram falhou): uma
nova tentativa **não pode reenviar ao canal que já tem
`facebook_post_id`/`instagram_media_id` preenchido**, senão duplica o
post naquele canal. Cada bloco abaixo só executa se o canal foi
solicitado E ainda não tem id de retorno gravado.

```php
public function publicar(MetaPost $post): bool
{
    $sucessoFacebook = $post->facebook_post_id ? true : null;
    $sucessoInstagram = $post->instagram_media_id ? true : null;

    if (in_array($post->canal_alvo, ['facebook', 'ambos']) && ! $post->facebook_post_id) {
        $pagina = $post->paginaFacebook; // relationship
        $id = $this->meta->publicarPostFacebookPage($pagina->facebook_page_id, $pagina->page_access_token, [
            'legenda'   => $post->texto,
            'imagem_url'=> $post->imagem_url,
            'link'      => $post->cta_url,
        ]);
        $sucessoFacebook = $id !== null;
        if ($sucessoFacebook) $post->facebook_post_id = $id;
    }

    if (in_array($post->canal_alvo, ['instagram', 'ambos']) && ! $post->instagram_media_id) {
        $conta = $post->contaInstagram; // relationship
        $id = $this->meta->publicarPostInstagram($conta->instagram_business_id, $conta->paginaFacebook->page_access_token, [
            'legenda'    => $post->texto,
            'imagem_url' => $post->imagem_url,
        ]);
        $sucessoInstagram = $id !== null;
        if ($sucessoInstagram) $post->instagram_media_id = $id;
    }

    $falhouAlgumCanalSolicitado =
        ($post->canal_alvo !== 'instagram' && $sucessoFacebook === false) ||
        ($post->canal_alvo !== 'facebook' && $sucessoInstagram === false);

    if ($falhouAlgumCanalSolicitado) {
        $post->status = 'falha';
        $post->log_erro = $this->montarLogErro($sucessoFacebook, $sucessoInstagram);
        $post->tentativas++;
        $post->save();
        return false;
    }

    $post->status = 'publicado';
    $post->publicado_em = now();
    $post->save();

    $this->criarGatilhosComentario($post); // seção 4

    return true;
}
```

Comando agendado `app/Console/Commands/PublicarMetaPostsCommand.php`
(`meta:publicar-posts`), idêntico em estrutura ao `PublicarGmbPostsCommand`:
busca `status=agendado` e `data_agendada <= now()`, chama o serviço,
reporta sucessos/falhas. Registrado em `routes/console.php` junto do
`gmb:publicar-posts` existente.

Botão "Publicar Agora" no card de falha (igual ao GMB) chama o mesmo
serviço de forma síncrona via um endpoint dedicado.

## 4. Criação automática do gatilho Comment-to-DM

Ao publicar com sucesso, se `modo_gatilho !== 'nenhum'`, o serviço cria
1 ou 2 registros em `meta_campanhas_gatilho` (um por canal efetivamente
publicado), já **ativos**:

```php
private function criarGatilhosComentario(MetaPost $post): void
{
    if ($post->modo_gatilho === 'nenhum') return;

    $base = [
        'tenant_id'                   => $post->tenant_id,
        'nome'                        => "Auto — Post #{$post->id}",
        'modo_gatilho'                => $post->modo_gatilho,
        'palavras_chave'              => $post->palavras_chave,
        'resposta_publica_comentario' => $post->resposta_publica_comentario,
        'mensagem_direct'             => $post->mensagem_direct,
        'meta_post_id'                => $post->id,
        'ativo'                       => true,
    ];

    if ($post->facebook_post_id) {
        MetaCampanhaGatilho::create($base + [
            'canal_alvo'         => 'facebook',
            'facebook_pagina_id' => $post->meta_pagina_id,
            'post_id_especifico' => $post->facebook_post_id,
        ]);
    }

    if ($post->instagram_media_id) {
        MetaCampanhaGatilho::create($base + [
            'canal_alvo'          => 'instagram',
            'instagram_conta_id'  => $post->meta_conta_instagram_id,
            'post_id_especifico'  => $post->instagram_media_id,
        ]);
    }
}
```

## 5. Interface

Nova entrada de menu (ao lado de "GMB"), rotas sob `/painel/meta-posts`
(nomes `meta-posts.*`), controller `MetaPostController`:

- **Índice/Calendário** (`GET meta-posts`): mesmo layout visual da tela de
  Postagens do GMB — cabeçalho com semana atual, navegação
  Anterior/Atual/Próxima, 4 cards de contagem (Total, Agendados,
  Publicados, Falhas), lista de posts agrupados por dia. Cada card de
  post mostra ícone do canal (FB azul / IG gradiente / ambos), miniatura,
  trecho do texto, horário, status, e "Publicar Agora" quando em falha.
- **Criar** (`GET/POST meta-posts/criar`): formulário com:
  - Seletor de página/conta (some sozinho se só houver uma vinculada,
    caso comum hoje)
  - `canal_alvo` (Facebook / Instagram / Ambos)
  - Texto (textarea), upload de imagem (salva em `storage/app/public`,
    mesmo padrão do `storeImagem` do GMB, sem biblioteca de imagens
    separada nesta primeira versão)
  - Bloco de botão CTA — visível via JS só quando Facebook está marcado
  - Bloco "Gatilho de comentário" (modo, palavras-chave, resposta
    pública, mensagem do Direct) — mesmos campos/validação do
    `MetaCampanhasGatilhoController::store`
  - Data/hora agendada
- **Publicar agora** (`POST meta-posts/{post}/publicar-agora`): dispara
  `MetaPostPublishService::publicar()` de forma síncrona.
- **Cancelar** (`DELETE meta-posts/{post}`): marca `status=cancelado`
  (soft — não apaga a linha, para manter histórico); se havia gatilhos
  vinculados (`meta_post_id`), desativa-os (`ativo=false`).

Sem Gerador em Lote nem Templates nesta primeira versão — fica para uma
iteração futura, replicando o padrão do GMB (`GmbPostIaService`,
`GmbPostTemplate`) quando houver necessidade real.

## 6. Testes

Feature tests (Pest/PHPUnit, padrão do projeto), com `Http::fake()` para
a Graph API:

- Criação de post agendado (facebook / instagram / ambos) — validação de
  campos condicionais (CTA exige facebook no canal).
- `MetaPostPublishService::publicar()`:
  - sucesso em ambos os canais → status `publicado`, ids preenchidos,
    gatilhos criados.
  - sucesso só no Facebook quando canal é `ambos` mas Instagram falha →
    status `falha`, `log_erro` menciona o Instagram, sem gatilho criado
    (falha não publica parcialmente como sucesso).
  - `modo_gatilho = nenhum` → nenhum gatilho criado.
- Comando `meta:publicar-posts`: só pega posts `agendado` com
  `data_agendada <= now()`, ignora os demais.
- Cancelamento desativa gatilhos vinculados.

## 7. Fora de escopo (adiado)

- Gerador em lote via IA
- Templates reutilizáveis
- Biblioteca de imagens separada (upload fica embutido no form)
- Vídeo/Reels no Instagram (a API do `MetaService::publicarPostInstagram`
  já suporta `video_url`, mas a tela desta versão só oferece imagem)
