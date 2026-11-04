---
checkpoint: null
complexity: G
created_at: "2026-11-03 22:55:28"
criteria:
    - done: true
      test: application/vendor/bin/phpunit --filter testProdutosEServicosSemPermissaoRespondem403 tests/Unit/ApiCadastrosTest.php
      text: Sem permissão, GET de produtos e DELETE de serviços respondem 403 com a mensagem da rota e nada é apagado
    - done: true
      test: application/vendor/bin/phpunit --filter testUsuariosSemPermissaoResponde403 tests/Unit/ApiCadastrosTest.php
      text: GET de usuários sem cUsuario responde 403
    - done: true
      test: '! grep -nE ''checkPermission|HTTP_UNAUTHORIZED'' application/controllers/api/v1/ProdutosController.php application/controllers/api/v1/ServicosController.php'
      text: ProdutosController e ServicosController não usam mais checkPermission com 401
    - done: true
      test: application/vendor/bin/phpunit --filter testSemCUsuarioNaoEditaOutroUsuario tests/Unit/ApiSegurancaTest.php
      text: usuarioApiPlanoEdicao sem cUsuario editando outro usuário devolve status 403
    - done: true
      test: application/vendor/bin/phpunit --filter testPutDeUsuarioComGrupoForaDoAlcanceResponde403 tests/Unit/ApiCadastrosTest.php
      text: PUT de usuário com permissoes_id fora de podeDelegarGrupo responde 403 e o grupo não muda
    - done: true
      test: application/vendor/bin/phpunit --filter testUsuarioNaoExcluiASiMesmo tests/Unit/ApiCadastrosTest.php
      text: DELETE do próprio usuário responde 403 'Você não pode excluir o próprio usuário.'
    - done: true
      test: application/vendor/bin/phpunit --filter testExclusaoDeUsuarioComFalhaResponde500 tests/Unit/ApiCadastrosTest.php
      text: DELETE de usuário com delete() falso responde 500 'Não foi possível excluir. Nada foi alterado.' sem log_app
    - done: true
      test: application/vendor/bin/phpunit --filter testPaginacaoDeProdutosEServicos tests/Unit/ApiCadastrosTest.php
      text: perPage=1000000 devolve no máximo 100 produtos e perPage=abc em serviços responde 422
    - done: true
      test: application/vendor/bin/phpunit --filter testPaginacaoDeUsuarios tests/Unit/ApiCadastrosTest.php
      text: page=2&perPage=10 em usuários chega ao model com limite 10 e offset 20
    - done: true
      test: application/vendor/bin/phpunit --filter testExclusaoDeProdutoComFalhaResponde500ENaoApagaNada tests/Unit/ApiCadastrosTest.php
      text: DELETE de produto com falha simulada responde 500 e mantém o produto e suas linhas de produtos_os
    - done: true
      test: application/vendor/bin/phpunit --filter testExclusaoDeProdutoEServicoApagaTudo tests/Unit/ApiCadastrosTest.php
      text: DELETE de produto e serviço existentes responde 200 e apaga o registro com as linhas filhas
    - done: true
      test: application/vendor/bin/phpunit --filter testExclusaoDeInexistenteResponde404 tests/Unit/ApiCadastrosTest.php
      text: DELETE de produto e serviço inexistentes responde 404
    - done: true
      test: application/vendor/bin/phpunit --filter testPostDeProdutoDevolveORegistroInserido tests/Unit/ApiCadastrosTest.php
      text: POST de produto devolve o registro do insert_id mesmo com outro produto de descrição parecida, e idProdutos do corpo é ignorado
    - done: true
      test: '! grep -n "descricao = ''" application/controllers/api/v1/ProdutosController.php application/controllers/api/v1/ServicosController.php'
      text: POST de produto e serviço não buscam mais o registro pela descrição
    - done: true
      test: application/vendor/bin/phpunit --filter testPostDeUsuarioComFalhaResponde500 tests/Unit/ApiCadastrosTest.php
      text: POST de usuário com add() falso responde 500 'Não foi possível adicionar o Usuário.'
    - done: true
      test: application/vendor/bin/phpunit tests/Unit/ApiCadastrosTest.php tests/Unit/ApiSegurancaTest.php
      text: Suítes da API de cadastros passam
    - done: true
      test: application/vendor/bin/phpstan analyse --memory-limit=1G --no-progress application/controllers/api/v1/ProdutosController.php application/controllers/api/v1/ServicosController.php application/controllers/api/v1/UsuariosController.php application/helpers/api_helper.php
      text: PHPStan não sobe a baseline nos arquivos da task
deps:
    - TASK-003
    - TASK-017
diff_base: 26f71a085ce43bafea2b09538debf39483c37c14
diff_tip: b676d45ea1229b0a3d40e1ae2a00e32655c2e130
files:
    - application/controllers/api/v1/ProdutosController.php
    - application/controllers/api/v1/ServicosController.php
    - application/controllers/api/v1/UsuariosController.php
    - application/helpers/api_helper.php
    - tests/Unit/ApiCadastrosTest.php
    - tests/Unit/ApiSegurancaTest.php
id: TASK-021
linter: composer analyse
n45_version: 1.1.0
outcome: |
    **Backend — API do operador (produtos, serviços, usuários)**

    - Falta de permissão em `GET/POST/PUT/DELETE` de `/api/v1/produtos`, `/api/v1/servicos` e `GET/POST/DELETE` de `/api/v1/usuarios` responde **403** `{status:false, message}` via `REST_Controller::exigirPermissao`; 401 fica só para token ausente/inválido.
    - `usuarioApiPlanoEdicao()` (`application/helpers/api_helper.php`): "sem `cUsuario` editando outro usuário" → `status` 403.
    - Listagens (`GET /produtos`, `/servicos`, `/usuarios` sem `search`): `paginacaoDaApi()` → `perPage` 1..100 (padrão 20, acima de 100 = 100), `page` >= 0; inválido → 422 `{status:false, message:'Parâmetros de paginação inválidos.', erros}`. Models recebem `(limite, offset)`.
    - `POST /produtos` e `/servicos`: 201 com `result` = registro do `insert_id`; allow-list única por controller (`dadosDoProduto`), id do corpo ignorado.
    - `DELETE /produtos/{id}` e `/servicos/{id}`: 404 `Produto/Serviço não encontrado.`; `excluirComDependentes()` falso → 500 `Não foi possível excluir. Nada foi alterado.`; verdadeiro → `log_app` + 200.
    - `DELETE /usuarios/{id}`: 403 sem `cUsuario` ou ao excluir a si mesmo (`Você não pode excluir o próprio usuário.`); `delete()` falso → 500 `Não foi possível excluir. Nada foi alterado.` (log de erro, sem `log_app`); `PUT` com grupo fora de `podeDelegarGrupo()` segue 403.
    - `POST /usuarios` com `add()` falso → 500 `Não foi possível adicionar o Usuário.`.
persona: backend
phase: Phase 3 — Primeiro acesso do cliente e APIs do operador e do cliente
roadmap: feat-0001-melhoria-seguranca
status: done
tdd:
    green: true
    red: true
    refactor: true
tests: application/vendor/bin/phpunit tests/Unit/ApiCadastrosTest.php tests/Unit/ApiSegurancaTest.php tests/Unit/ApiBaseTest.php tests/Unit/UsuariosPermissoesTest.php
title: 'API de produtos, serviços e usuários: 403, allow-list, paginação e exclusão'
updated_at: "2026-11-04 01:00:45"
---
## Contexto

Na API do operador, os recursos de produtos, serviços e usuários (`application/controllers/api/v1/{ProdutosController,ServicosController,UsuariosController}.php`) têm as mesmas lacunas que a auditoria apontou na OS:

- falta de permissão responde **401** (`checkPermission` + `HTTP_UNAUTHORIZED`), quando 401 deve ser só token ausente/inválido; `usuarioApiPlanoEdicao()` (em `application/helpers/api_helper.php`) também devolve 401 para "sem `cUsuario` editando outro usuário";
- listagens com `perPage` sem teto (`$this->get('perPage') ?: 20`), sem validar inteiro;
- exclusão de produto/serviço em `delete()` soltos (o produto some das OS e das vendas mesmo se o `DELETE` final falha), e exclusão de usuário sem conferir o retorno (`delete()` ignorado, sempre "excluído com sucesso");
- `POST` de produto/serviço devolve o registro achado por `get(..., "descricao = '...'", 1, 0, true)` — uma busca `LIKE` pela descrição, que devolve outro registro com descrição parecida; e `POST /usuarios` sem resposta quando `add()` falha (a requisição termina sem corpo).

Contratos já entregues que esta task consome:

- `REST_Controller` (TASK-003): `protected exigirPermissao(string $permissao, string $mensagem): void` → 403 `{status:false, message}` e encerra; `protected paginacaoDaApi(): array` → `[int $limite, int $offset]` (`perPage` 1..100, padrão 20, acima de 100 = 100; `page` ≥ 0; `offset = page * perPage`) ou 422 `{status:false, message:'Parâmetros de paginação inválidos.', erros}`.
- `Produtos_model::excluirComDependentes(int $idProduto): bool` e `Servicos_model::excluirComDependentes(int $idServico): bool` (TASK-017, fase atual): `true` = registro e linhas de `produtos_os`/`itens_de_vendas` (produto) ou `servicos_os` (serviço) apagados numa transação; `false` = nada mudou (inclusive registro inexistente), log `transacao rollback operacao=excluir_produto|excluir_servico id=<id>` já feito pelo model.
- `UsuariosController::podeDelegarGrupo(int $idGrupo): bool` e `usuarioApiPodeDelegarGrupo()` já existem: `POST /usuarios` já recusa grupo fora do alcance com 403; `PUT` usa `usuarioApiPlanoEdicao()`, que já devolve 403 para grupo fora de `podeDelegarGrupo()` (RF-10 — manter e cobrir com teste).
- Os models `Produtos_model::get($table, $fields, $where, $perpage, $start)`, `Servicos_model::get(...)` e `Usuarios_model::get($perpage, $start)` recebem limite e offset.

Regras: escrita por allow-list explícita (os arrays `$data` atuais já são allow-list; garantir que `idProdutos`, `idServicos`, `idUsuarios`, `senha_trocar`, `token_versao`, `sessao_versao` nunca entram); `null` convertido antes de função de string.

## Comportamento Esperado

| Entrada / Ação | Saída / Efeito esperado |
| --- | --- |
| `GET /api/v1/produtos` sem `vProduto` | 403 `{status:false, message:'Você não está autorizado a Visualizar Produtos'}` |
| `DELETE /api/v1/servicos/4` sem `dServico` | 403; serviço 4 intacto |
| `GET /api/v1/usuarios` sem `cUsuario` | 403 `Você não está autorizado a Visualizar Usuários` |
| `usuarioApiPlanoEdicao($usuarios, 2, 3, false, <corpo>, fn () => true)` (sem `cUsuario`, outro usuário) | `['ok' => false, 'status' => 403, 'message' => 'Você não está autorizado a Editar Usuários!', ...]` |
| `PUT /api/v1/usuarios/3` com `permissoes_id` de grupo que `podeDelegarGrupo()` recusa | 403 `Você só pode escolher grupos com permissões que você também tem.`; grupo do usuário 3 inalterado |
| `DELETE /api/v1/usuarios/1` feito pelo próprio usuário 1 (com `cUsuario`) | 403 `Você não pode excluir o próprio usuário.` |
| `DELETE /api/v1/usuarios/5` com `delete()` devolvendo `false` | 500 `{status:false, message:'Não foi possível excluir. Nada foi alterado.'}`; sem `log_app` |
| `GET /api/v1/produtos?perPage=1000000` com 130 produtos | 200 com 100 itens |
| `GET /api/v1/servicos?perPage=abc` | 422 `Parâmetros de paginação inválidos.` com `erros.perPage` |
| `GET /api/v1/usuarios?page=2&perPage=10` (sem `search`) | `Usuarios_model::get` recebe limite 10 e offset 20 |
| `DELETE /api/v1/produtos/7` existente, model `true` | 200 `Produto excluído com sucesso!`; `log_app` chamado |
| `DELETE /api/v1/produtos/7` com falha simulada no `DELETE` de `produtos` | 500 `Não foi possível excluir. Nada foi alterado.`; produto 7 e as linhas de `produtos_os` dele continuam |
| `DELETE /api/v1/produtos/99` (inexistente) | 404 `{status:false, message:'Produto não encontrado.'}` |
| `DELETE /api/v1/servicos/99` (inexistente) | 404 `Serviço não encontrado.` |
| `POST /api/v1/produtos` com `descricao: "Tela"` existindo outro produto `"Tela notebook"` (id maior) | 201 com `result.idProdutos` = id inserido por esta requisição (`insert_id`) |
| `POST /api/v1/produtos` com `idProdutos: 500, entrada: 1` no corpo | o produto criado não tem id 500 |
| `POST /api/v1/usuarios` com `add()` falso | 500 `{status:false, message:'Não foi possível adicionar o Usuário.'}` |

## TDD

Suíte nova `tests/Unit/ApiCadastrosTest.php` (classe `final`, `MaposTestCase`, SQLite em memória), no molde de `tests/Unit/ApiOsSegurancaTest.php`: subclasse anônima de cada controller com `response()` lançando `RuntimeException(json_encode(['code' => ..., 'data' => ...]))`, `logged_user()` stub (`level`, `usuario->idUsuarios`, `usuario->nome`), `permission` stub com `pode`, `get()`/`post()`/`put()` lendo um array `entrada`, `log_app()` contando chamadas, models reais com `setPrivateProperty($model, 'db', $this->db)`. Falha de banco simulada com trigger `RAISE(ABORT)` como em `tests/Unit/OsVendasTransacaoTest.php::falhaAntesDe()`.

```php
public function testExclusaoDeProdutoComFalhaResponde500ENaoApagaNada(): void
{
    $controller = $this->produtos(); // produto 7 com 2 linhas em produtos_os
    $this->falhaAntesDe('DELETE', 'produtos');

    $resposta = $this->chamar($controller, 'index_delete', [7]);

    $this->assertSame(500, $resposta['code']);
    $this->assertSame('Não foi possível excluir. Nada foi alterado.', $resposta['data']['message']);
    $this->assertSame(2, $this->db->where('produtos_id', 7)->count_all_results('produtos_os'));
    $this->assertSame(0, $controller->chamadasLogApp);
}
```

Cobrir os demais casos da tabela (nomes nos critérios). Em `tests/Unit/ApiSegurancaTest.php`, `testSemCUsuarioNaoEditaOutroUsuario` passa a esperar **403**.

**Refatoração:** a montagem do `$data` de `POST`/`PUT` de produto e de serviço fica num só método privado por controller, usado pelos dois verbos.

## O que Implementar

### Arquivos a Criar ou Modificar

| Arquivo | Ação | Descrição |
| --- | --- | --- |
| `application/controllers/api/v1/ProdutosController.php` | Modificar | `exigirPermissao`, `paginacaoDaApi`, `insert_id` no POST, exclusão por `excluirComDependentes` com 404/500 |
| `application/controllers/api/v1/ServicosController.php` | Modificar | idem para serviços |
| `application/controllers/api/v1/UsuariosController.php` | Modificar | `exigirPermissao('cUsuario', ...)` em GET/POST/DELETE, paginação, 403 para excluir a si mesmo, retorno do `delete()` e do `add()` conferidos |
| `application/helpers/api_helper.php` | Modificar | `usuarioApiPlanoEdicao`: 401 → 403 para "sem cUsuario editando outro" |
| `tests/Unit/ApiCadastrosTest.php` | Criar | Suíte nova |
| `tests/Unit/ApiSegurancaTest.php` | Modificar | 401 → 403 no teste do plano de edição |

### Detalhamento Técnico

1. **Permissão:** em `ProdutosController` e `ServicosController`, cada `if (! checkPermission(...)) { response(..., HTTP_UNAUTHORIZED) }` vira `$this->exigirPermissao('<perm>', '<mesma mensagem>')`. Em `UsuariosController`: `index_get`, `index_post` e `index_delete` usam `exigirPermissao('cUsuario', <mensagem atual>)`; no `index_delete`, o "próprio usuário" sai do `if` da permissão e vira checagem própria → 403 `Você não pode excluir o próprio usuário.`. `index_put` continua com `usuarioApiPlanoEdicao` (que decide 403).
2. **`usuarioApiPlanoEdicao`:** `return $erro(401, 'Você não está autorizado a Editar Usuários!');` → `$erro(403, ...)`. Nada mais muda na função.
3. **Paginação:** nos três `index_get` sem id: `[$limite, $offset] = $this->paginacaoDaApi();` e os models recebem `$limite, $offset`. Em usuários, o ramo com `search` (`api_model->searchUsuario`) segue como está.
4. **POST de produto/serviço:** depois de `add()` verdadeiro, `$id = (int) $this->db->insert_id();` e `result` = `getById($id)`. Remover a busca por `"descricao = '...'"`. Textos com `(string) (... ?? '')` antes de `str_replace`.
5. **DELETE de produto/serviço:** `$id` inteiro positivo (senão 400 como hoje); `getById($id)` nulo → 404 `Produto não encontrado.` / `Serviço não encontrado.`; `excluirComDependentes($id)` falso → 500 `Não foi possível excluir. Nada foi alterado.`; verdadeiro → `log_app` e 200 com a mensagem atual.
6. **DELETE de usuário:** mantém as regras atuais (`usuarioPodeSerRemovido`, `podeDelegarGrupo`, `referencias`); `$this->usuarios_model->delete('usuarios', 'idUsuarios', $id)` falso → 500 `Não foi possível excluir. Nada foi alterado.` e `log_message('error', sprintf('usuario exclusao falha id=%d', $id))`; verdadeiro → `log_app` e 200.
7. **POST de usuário:** `add()` falso → 500 `Não foi possível adicionar o Usuário.`.

**Contrato HTTP:**

```
GET /api/v1/produtos?perPage=20&page=0&search=tela        (idem /servicos, /usuarios)
Authorization: Bearer <token>
Response 200: {status:true, message:"Listando Produtos", result:[...]}
Response 401: token ausente/inválido · 403: {status:false, message:"Você não está autorizado a Visualizar Produtos"}
Response 422: {status:false, message:"Parâmetros de paginação inválidos.", erros:{perPage?:"Informe um número inteiro de 1 a 100.", page?:"Informe um número inteiro maior ou igual a 0."}}

POST /api/v1/produtos
{"codDeBarra":"789","descricao":"Tela","unidade":"UNID","precoCompra":"10.00","precoVenda":"15.00","estoque":"3","estoqueMinimo":"1"}
Response 201: {status:true, message:"Produto adicionado com sucesso!", result:{"idProdutos": <insert_id>, ...}}

DELETE /api/v1/produtos/{id}   (idem /servicos/{id})
Response 200: {status:true, message:"Produto excluído com sucesso!"}
Response 403 sem dProduto · 404 {status:false, message:"Produto não encontrado."} · 500 {status:false, message:"Não foi possível excluir. Nada foi alterado."}

DELETE /api/v1/usuarios/{id}
Response 200 · 400 regras atuais · 403 sem cUsuario | "Você não pode excluir o próprio usuário." | grupo fora do alcance · 404 · 500 "Não foi possível excluir. Nada foi alterado."
```

**Contrato com camadas adjacentes:**

```
Consome de: Produtos_model::excluirComDependentes(int): bool / Servicos_model::excluirComDependentes(int): bool (TASK-017)
  - false → HTTP 500 "Não foi possível excluir. Nada foi alterado." (o model já logou)
  - inexistência detectada antes por getById() → HTTP 404
```
