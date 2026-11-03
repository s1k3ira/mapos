<?php

// A view usa os helpers de URL do CodeIgniter, que o harness não carrega.
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
 * Tela "Defina sua nova senha" da área do cliente (conecte/definir_senha.php),
 * renderizada fora do framework com $this falso.
 */
final class DefinirSenhaViewTest extends MaposTestCase
{
    private function renderizar(array $dados): string
    {
        $carregador = new class($dados) {
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

            public function view(string $nome): string
            {
                $arquivo = APPPATH . 'views' . DIRECTORY_SEPARATOR . $nome . '.php';

                return (function () use ($arquivo) {
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
            }
        };

        return $carregador->view('conecte/definir_senha');
    }

    public function testTelaTemTituloFraseEAvisoNaSuperficieDeEntrada(): void
    {
        $html = $this->renderizar([]);

        $this->assertMatchesRegularExpression('#<h1[^>]*>Defina sua nova senha</h1>#', $html);
        $this->assertStringContainsString('Por segurança, troque a senha que você recebeu antes de continuar.', $html);
        $this->assertMatchesRegularExpression('/<div[^>]*role="status"[^>]*>(?:(?!<\/div>).)*Você entrou com uma senha provisória\. Crie a sua para continuar\./s', $html);
        $this->assertStringContainsString('scheme-dark', $html);
        $this->assertStringContainsString('superficie-entrada', $html);
        $this->assertStringNotContainsString('role="alert"', $html);
    }

    public function testFormularioPostaParaDefinirSenhaComCsrf(): void
    {
        $html = $this->renderizar([]);

        $this->assertMatchesRegularExpression('#<form[^>]*method="post"[^>]*action="http://mapos\.test/index\.php/mine/definirSenha"[^>]*novalidate[^>]*data-module="formulario/padrao"#', $html);
        $this->assertStringContainsString('name="MAPOS_TOKEN" value="hash-csrf"', $html);
    }

    public function testCamposDeSenhaVoltamLimposComAutocomplete(): void
    {
        $html = $this->renderizar(['erros' => ['senha_atual' => 'x']]);

        $this->assertMatchesRegularExpression('/<input[^>]*id="campo-senha_atual"[^>]*type="password"|<input[^>]*type="password"[^>]*id="campo-senha_atual"/', $html);
        $this->assertSame(3, preg_match_all('/<input[^>]*type="password"/', $html));
        $this->assertSame(0, preg_match('/<input[^>]*type="password"[^>]*\svalue=/', $html));
        $this->assertMatchesRegularExpression('/<input[^>]*id="campo-senha_atual"[^>]*autocomplete="current-password"/', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*id="campo-senha"[^>]*autocomplete="new-password"/', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*id="campo-senha_confirmacao"[^>]*autocomplete="new-password"/', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*id="campo-senha_atual"[^>]*autofocus/', $html);
        $this->assertSame(1, substr_count($html, 'autofocus'));
        $this->assertMatchesRegularExpression('/<input[^>]*id="campo-senha"[^>]*minlength="8"[^>]*maxlength="72"/', $html);
    }

    public function testNovaSenhaTemAjudaLigadaPorAriaDescribedby(): void
    {
        $html = $this->renderizar([]);

        $this->assertMatchesRegularExpression('/<input[^>]*id="campo-senha"[^>]*aria-describedby="campo-senha-ajuda"/', $html);
        $this->assertMatchesRegularExpression('#<p id="campo-senha-ajuda"[^>]*>Mínimo de 8 caracteres\.</p>#', $html);
    }

    public function testErroDaSenhaAtualMarcaOCampo(): void
    {
        $html = $this->renderizar(['erros' => ['senha_atual' => 'A senha atual não confere.']]);

        $this->assertMatchesRegularExpression('/<input[^>]*id="campo-senha_atual"[^>]*aria-invalid="true"[^>]*aria-describedby="campo-senha_atual-erro"/', $html);
        $this->assertStringContainsString('A senha atual não confere.', $html);
    }

    public function testErroGeralViraAlertDanger(): void
    {
        $com = $this->renderizar(['erros' => ['_geral' => 'Muitas tentativas. Tente de novo em alguns minutos.']]);
        $sem = $this->renderizar([]);

        $this->assertSame(1, substr_count($com, 'role="alert"'));
        $this->assertMatchesRegularExpression('/role="alert"(?:(?!<\/div>).)*Muitas tentativas\. Tente de novo em alguns minutos\./s', $com);
        $this->assertSame(0, substr_count($sem, 'role="alert"'));
    }

    public function testMostrarSenhaTemRotuloAcessivelNaoVisivel(): void
    {
        $html = $this->renderizar([]);

        foreach (['campo-senha_atual', 'campo-senha'] as $campo) {
            $this->assertMatchesRegularExpression('/<button[^>]*data-mostrar-senha="' . $campo . '"[^>]*aria-controls="' . $campo . '"[^>]*aria-pressed="false"|<button[^>]*aria-controls="' . $campo . '"[^>]*aria-pressed="false"[^>]*data-mostrar-senha="' . $campo . '"/', $html);
        }
        $this->assertSame(2, substr_count($html, 'data-mostrar-senha='));
        $this->assertSame(2, preg_match_all('#<span class="sr-only">Mostrar senha</span>#', $html));
    }

    public function testBotaoDeLogoutEFormularioPost(): void
    {
        $html = $this->renderizar([]);

        $this->assertMatchesRegularExpression('#<form method="post" action="http://mapos\.test/index\.php/mine/sair"[^>]*>\s*<input type="hidden" name="MAPOS_TOKEN" value="hash-csrf">#', $html);
        $this->assertStringContainsString('Sair', $html);
        $this->assertStringNotContainsString('href="http://mapos.test/index.php/mine/sair"', $html);
        $this->assertStringContainsString('Salvar senha', $html);
    }

    public function testEscapaErrosESemScriptInline(): void
    {
        $html = $this->renderizar(['erros' => ['senha' => '<b>x</b>', '_geral' => '<b>y</b>']]);

        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $html);
        $this->assertStringContainsString('&lt;b&gt;y&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>x</b>', $html);
        $this->assertSame(0, preg_match('/<script(?![^>]*\bsrc=)[^>]*>/', $html));
    }
}
