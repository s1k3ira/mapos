---
created_at: "2026-11-03 14:47:08"
layers:
    - items:
        architecture: 'MVC do CodeIgniter: Controller (MY_Controller) -> Model (Query Builder) -> MySQL; regras reutilizáveis em helpers e libraries'
        dependency_manager: Composer (vendor-dir application/vendor)
        formatter: composer format (php-cs-fixer, @PSR2, array curto)
        framework: CodeIgniter 3.1 (codeigniter/framework ^3.1.13), controllers em application/controllers, models em application/models
        linter: composer analyse (PHPStan nível 5 com baseline)
        payments: Efí, Mercado Pago e Asaas por gateways em application/libraries/Gateways
        pdf: mPDF 8
      name: backend
      technology: PHP 8.5
    - items:
        build: npm run build (Tailwind CLI 4.3.3, saída commitada em assets/dist)
        fonts: Rubik e Space Grotesk servidas localmente (assets/vendor/fonts)
        icons: Lucide por sprite SVG (icon() e criarIcone())
        language: PHP nas views, JavaScript ES modules sem bundler (assets/js/modules)
        node: Node 22 ou superior, só para alterar views, CSS ou bibliotecas JS
        requests: get() e post() em assets/js/lib/http.js (fetch com CSRF e X-Requested-With)
        ui: Biblioteca de componentes própria (component() em application/views/components) seguindo o DESIGN.md
      name: frontend
      technology: Views PHP com Tailwind CSS 4
    - items:
        conventions: tabelas e colunas em português, snake_case ou camelCase herdado (idClientes, clientes_id); novas tabelas em snake_case
        migrations: CodeIgniter Migration em modo timestamp (application/database/migrations)
        orm: CodeIgniter Query Builder (sem ORM)
        sessions: tabela ci_sessions (driver database)
      name: database
      technology: MySQL 8.4 / MariaDB
    - items:
        ci_cd: GitHub Actions (ci.yml, release.yml, release-assets.yml)
        hosting: Apache ou Nginx com PHP-FPM; só index.php e assets/ públicos
        jobs: cron do SO chamando php index.php email/process e email/retry
        orchestration: docker/docker-compose.yml com nginx, php-fpm 8.5, mysql, phpmyadmin e composer
      name: infra
      technology: Docker Compose
    - items:
        backend_cmd: composer test
        frontend_cmd: npm run test:js
        guards: php scripts/check-escape.php e php scripts/check-inline-script.php (rodam no CI)
        static_cmd: composer analyse
      name: tests
      technology: PHPUnit 12 + node:test
    - items:
        base: application/libraries/REST_Controller.php
        docs: docs/api-autenticacao.md
        versioning: /api/v1/ (só existe com API_ENABLED=true)
      name: api
      technology: REST JSON
    - items:
        authorization: RBAC por grupo de permissões (tabela permissoes) e mapa por rota em application/config/permissions_map.php
        protocol: TOTP opcional como segundo fator (robthree/twofactorauth)
        strategy: Painel e área do cliente por sessão no banco; API por JWT HS256 de curta duração e refresh token rotativo
      name: auth
      technology: Sessão CodeIgniter + JWT
n45_version: 1.1.0
updated_at: "2026-11-03 14:47:08"
---

## Notas

- A lista de bibliotecas é renderizada do grafo do projeto, com quem importa cada uma.
- `alpinejs`, `tom-select`, `flatpickr`, `imask` e `sweetalert2` estão no `package.json` e são copiadas para `assets/vendor` por `npm run build:vendor`, mas nenhum arquivo de `assets/js` nem de `application/views` as referencia hoje; não as use em tela nova sem antes ligá-las a um módulo.
- O projeto não mede cobertura de testes: nenhum pacote de cobertura está instalado.
- Ferramentas de build (Tailwind, svgo, lightningcss) vêm do `package.json` e não fazem parte do runtime.
