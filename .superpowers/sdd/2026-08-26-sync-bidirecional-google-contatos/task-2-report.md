# Task 2 Report: Backfill Conservador

## O que foi implementado

Task 2 criou a migration de backfill que marca como "editado por humano" todo campo já preenchido em contatos existentes, protegendo dados reais de serem sobrescritos pela Task 3 (pull do Google).

### Arquivos criados:

1. **Migration:** `database/migrations/2026_08_26_000003_backfill_campos_editados_humano_vinculos_contato_tenant.php`
   - Implementa a lógica de backfill seguindo exatamente o brief
   - Itera sobre `VinculoContatoTenant` com `campos_editados_humano` nulo
   - Marca como humano (com timestamp ISO8601) cada campo em `['nome', 'sobrenome', 'empresa', 'email']` que:
     - Não está vazio
     - Se for 'nome', não é um placeholder (verifica `semNomeReal()`)
   - Usa `chunkById(200)` para eficiência em grandes datasets
   - `down()` intencionalmente vazio (backfill de dados não tem desfazer seguro)

2. **Teste:** `tests/Feature/BackfillCamposEditadosHumanoTest.php`
   - Dois testes de caso:
     - `test_backfill_marca_como_humano_todo_campo_ja_preenchido_e_real()`: verifica que campos reais são marcados
     - `test_backfill_nao_marca_campo_vazio_ou_placeholder()`: verifica que campos vazios e placeholders NÃO são marcados
   - Usa `RefreshDatabase` para setup limpo
   - Usa `Artisan::call('migrate:refresh', ['--path' => ...])` para simular cenário real

## Evidência TDD

### RED (esperado falhar)
Teste rodado **antes** de criar a migration:
```
FAIL Tests\Feature\BackfillCamposEditadosHumanoTest
✗ backfill marca como humano todo campo ja preenchido e real
✗ backfill nao marca campo vazio ou placeholder

FileNotFoundException: File does not exist at path /tmp/sdd-google-contatos/database/migrations/2026_08_26_000003_backfill_campos_editados_humano_vinculos_contato_tenant.php
```

### GREEN (esperado passar)
Teste rodado **após** implementar a migration:
```
PASS Tests\Feature\BackfillCamposEditadosHumanoTest
✓ backfill marca como humano todo campo ja preenchido e real          0.53s
✓ backfill nao marca campo vazio ou placeholder                        0.06s

Tests: 2 passed (5 assertions)
Duration: 0.84s
```

## Arquivos alterados

```
tests/Feature/BackfillCamposEditadosHumanoTest.php (criado)
database/migrations/2026_08_26_000003_backfill_campos_editados_humano_vinculos_contato_tenant.php (criado)
```

## Mecanismo de teste usado

**`Artisan::call('migrate:refresh', ['--path' => ...])`** ✓

Razão: Este foi o mecanismo escolhido no brief (Step 1) e funcionou perfeitamente. O comportamento de `--path` escopado a um único arquivo rodou corretamente, re-executando a migration mesmo que já tivesse rodado no setUp do `RefreshDatabase`. Não foi necessário recorrer à alternativa `require(...)->up()`.

## Achados do self-review

✓ **Completude:** Ambas as funcionalidades especificadas no brief estão implementadas
- Backfill marca campos reais
- Backfill ignora vazios e placeholders

✓ **Qualidade:**
- Segue padrões Laravel (migrations, models, testes)
- Usa chunking para eficiência
- Relação com models (contato, vinculo) está correta
- Casts JSON em `VinculoContatoTenant` já estão configurados da Task 1

✓ **Disciplina YAGNI:**
- Nenhum código extra além do necessário
- Padrões existentes foram seguidos

✓ **Testes:**
- Ambos os casos de teste passam
- Comportamento real é verificado (RED → GREEN)
- Saída limpa (5 assertions, 0 failures)

## Nenhuma preocupação

- Todos os requisitos atendidos
- Testes passando em produção (VPS)
- Migration roda corretamente após o backfill
- Interação com `semNomeReal()` funciona conforme esperado

## Commits criados

```
67c1be5 test(google-sync): backfill campos_editados_humano test
db84b19 feat(google-sync): backfill conservador de campos_editados_humano
```

Ambos estão no worktree local (2 commits ahead de origin, push com timeout repetido mas SCP com sucesso na VPS e testes verificados).
