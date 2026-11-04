<?php

if (! function_exists('site_url')) {
    function site_url($uri = '')
    {
        return 'http://mapos.test/index.php/' . ltrim((string) $uri, '/');
    }
}

if (! function_exists('base_url')) {
    function base_url($uri = '')
    {
        return 'http://mapos.test/' . ltrim((string) $uri, '/');
    }
}

/**
 * "Sair" do painel por formulário POST com CSRF (RF-27): GET login/sair
 * responde 405, então nenhuma tela do painel pode manter o link.
 */
final class LogoutPorPostTest extends MaposTestCase
{
    private function renderizar(string $view, array $variaveis): string
    {
        $carregador = new class($variaveis) {
            public object $load;

            public object $security;

            public object $config;

            public function __construct(private array $variaveis)
            {
                $this->load = $this;
                $this->security = new class() {
                    public function get_csrf_token_name()
                    {
                        return 'MAPOS_TOKEN';
                    }

                    public function get_csrf_hash()
                    {
                        return 'hash-csrf';
                    }
                };
                $this->config = new class() {
                    public function item($nome)
                    {
                        return ['app_name' => 'Map-OS'][$nome] ?? null;
                    }
                };
            }

            public function view(string $nome, array $mais = [], bool $retornar = false)
            {
                $this->variaveis = array_merge($this->variaveis, $mais);
                $arquivo = APPPATH . 'views' . DIRECTORY_SEPARATOR . $nome . '.php';
                $html = (function () use ($arquivo) {
                    extract($this->variaveis);
                    ob_start();

                    try {
                        include $arquivo;
                    } catch (Throwable $erro) {
                        ob_end_clean();

                        throw $erro;
                    }

                    return (string) ob_get_clean();
                })();

                if ($retornar) {
                    return $html;
                }

                echo $html;
            }
        };

        return $carregador->view($view, [], true);
    }

    private function dadosDoLayout(): array
    {
        return [
            'configuration' => ['app_name' => 'Map-OS'],
            'layout' => [
                'usuario' => 'Ana',
                'iniciais' => 'A',
                'avatar' => '',
                'tem_foto' => false,
                'grupos' => [],
                'breadcrumb' => [['label' => 'Início', 'url' => null]],
                'acao' => null,
                'saudacao' => 'Boa tarde',
                'versao' => '5.0',
                'nome_sistema' => 'Map-OS',
            ],
        ];
    }

    private function dadosDoBanco(bool $podeAtualizar): array
    {
        return [
            'titulo' => 'Banco desatualizado',
            'erro' => null,
            'podeAtualizar' => $podeAtualizar,
            'quantidade' => 2,
            'acaoAtualizar' => 'http://mapos.test/index.php/mapos/atualizarBanco',
            'acaoSair' => 'http://mapos.test/index.php/login/sair',
            'csrfNome' => 'MAPOS_TOKEN',
            'csrfValor' => 'hash-csrf',
            'configuration' => ['app_name' => 'Map-OS'],
        ];
    }

    public function testMenuDoUsuarioSaiPorFormularioPost(): void
    {
        $html = $this->renderizar('tema/conteudo', $this->dadosDoLayout());

        $this->assertMatchesRegularExpression('#<form method="post" action="http://mapos\.test/index\.php/login/sair"[^>]*data-module="layout/sair"#', $html);
        $this->assertStringContainsString('<input type="hidden" name="MAPOS_TOKEN" value="hash-csrf">', $html);
        $this->assertMatchesRegularExpression('#<button type="submit"[^>]*>.*Sair do Sistema</button>#s', $html);
        $this->assertDoesNotMatchRegularExpression('#href="[^"]*login/sair"#', $html);
    }

    public function testSidebarSaiPorFormularioPost(): void
    {
        $html = $this->renderizar('tema/menu', $this->dadosDoLayout());

        $this->assertMatchesRegularExpression('#<form method="post" action="http://mapos\.test/index\.php/login/sair"[^>]*data-module="layout/sair"#', $html);
        $this->assertStringContainsString('<input type="hidden" name="MAPOS_TOKEN" value="hash-csrf">', $html);
        $this->assertMatchesRegularExpression('#<button type="submit"[^>]*title="Sair"[^>]*>.*<span class="sr-only">Sair</span>.*</button>#s', $html);
        $this->assertDoesNotMatchRegularExpression('#href="[^"]*login/sair"#', $html);
    }

    public function testBancoDesatualizadoSaiPorFormularioPost(): void
    {
        foreach ([true, false] as $podeAtualizar) {
            $html = $this->renderizar('mapos/banco-desatualizado', $this->dadosDoBanco($podeAtualizar));

            $this->assertMatchesRegularExpression('#<form method="post" action="http://mapos\.test/index\.php/login/sair">\s*<input type="hidden" name="MAPOS_TOKEN" value="hash-csrf">\s*<button[^>]*type="submit"[^>]*>.*?<span[^>]*>Sair</span>\s*</button>#s', $html, $podeAtualizar ? 'com cSistema' : 'sem cSistema');
            $this->assertDoesNotMatchRegularExpression('#href="[^"]*login/sair"#', $html);
        }
    }

    public function testVoltarAoLoginDoDoisFatoresVaiParaOLogin(): void
    {
        $html = $this->renderizar('mapos/login_dois_fatores', ['erro' => null]);

        $this->assertMatchesRegularExpression('#<a [^>]*href="http://mapos\.test/index\.php/login"[^>]*>.*?Voltar ao login</span>#s', $html);
        $this->assertStringNotContainsString('login/sair', $html);
    }
}
