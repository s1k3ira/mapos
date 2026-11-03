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
 * Tela da senha inicial do cliente (views/clientes/senha_inicial.php),
 * renderizada fora do framework com $this falso.
 */
final class SenhaInicialViewTest extends MaposTestCase
{
    private const SENHA = 'Ab3dEf6hJk8mNp2q';

    private function renderizar(array $dados = []): string
    {
        $dados += [
            'cliente' => (object) ['idClientes' => 7, 'nomeCliente' => 'Ana'],
            'senhaInicial' => self::SENHA,
            'emailEnviado' => true,
        ];

        $render = static function (array $__dados) {
            extract($__dados);
            ob_start();
            include APPPATH . 'views/clientes/senha_inicial.php';

            return ob_get_clean();
        };

        return $render($dados);
    }

    public function testSenhaApareceUmaUnicaVezSoNoCampoReadonly(): void
    {
        $html = $this->renderizar();

        $this->assertSame(1, substr_count($html, self::SENHA));
        $this->assertMatchesRegularExpression('/<input[^>]*readonly[^>]*value="' . self::SENHA . '"|<input[^>]*value="' . self::SENHA . '"[^>]*readonly/', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*type="password"/', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*autocomplete="off"/', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*class="[^"]*font-mono/', $html);
        $this->assertMatchesRegularExpression('/<label for="senha-inicial"[^>]*>\s*Senha inicial/', $html);
    }

    public function testEmailEnviadoMostraSoOTextoDeSucesso(): void
    {
        $html = $this->renderizar(['emailEnviado' => true]);

        $this->assertStringContainsString('Também enviada por e-mail ao cliente.', $html);
        $this->assertStringNotContainsString('Não foi possível enviar', $html);
    }

    public function testEmailNaoEnviadoMostraSoOTextoDeFalha(): void
    {
        $html = $this->renderizar(['emailEnviado' => false]);

        $this->assertStringContainsString('Não foi possível enviar por e-mail: repasse a senha ao cliente.', $html);
        $this->assertStringNotContainsString('Também enviada por e-mail', $html);
    }

    public function testNomeDoClienteSaiEscapado(): void
    {
        $html = $this->renderizar(['cliente' => (object) ['idClientes' => 7, 'nomeCliente' => 'Ana <b>&']]);

        $this->assertStringContainsString('Ana &lt;b&gt;&amp;', $html);
        $this->assertStringNotContainsString('Ana <b>', $html);
    }

    public function testTituloEWrapperSeguemAComposicaoDeTela(): void
    {
        $html = $this->renderizar();

        $this->assertMatchesRegularExpression('#<h1 class="font-display text-heading-xl text-text">Cliente cadastrado</h1>#', $html);
        $this->assertStringContainsString('max-w-4xl', $html);
        $this->assertStringContainsString('data-module="clientes/senha-inicial"', $html);
    }

    public function testBotoesECopiarEMostrarTemRotuloAcessivel(): void
    {
        $html = $this->renderizar();

        $this->assertMatchesRegularExpression('#<button(?=[^>]*data-copiar="senha-inicial")[^>]*>.*?<span class="">Copiar senha</span>#s', $html);
        $this->assertMatchesRegularExpression('#<button(?=[^>]*data-mostrar-senha="senha-inicial")(?=[^>]*aria-pressed="false")(?=[^>]*aria-controls="senha-inicial")[^>]*>.*?<span class="sr-only">Mostrar senha</span>#s', $html);
    }

    public function testAvisoEWarningComRoleStatus(): void
    {
        $html = $this->renderizar();

        $this->assertMatchesRegularExpression('#<div(?=[^>]*role="status")(?=[^>]*bg-warning-soft)[^>]*>.*?exibida apenas agora#s', $html);
        $this->assertStringNotContainsString('role="alert"', $html);
    }

    public function testLinksDeVerClienteEVoltarALista(): void
    {
        $html = $this->renderizar();

        $this->assertMatchesRegularExpression('#<a[^>]*href="http://mapos.test/index.php/clientes/visualizar/7"[^>]*>.*?Ver cliente#s', $html);
        $this->assertMatchesRegularExpression('#<a[^>]*href="http://mapos.test/index.php/clientes"[^>]*>.*?Voltar à lista#s', $html);
        $this->assertSame(0, preg_match('/<script(?![^>]*\bsrc=)[^>]*>/', $html), 'Nenhum <script> sem src.');
    }
}
