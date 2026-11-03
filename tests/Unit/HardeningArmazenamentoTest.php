<?php

/**
 * Endurecimento das pastas de arquivos, de save_queries e do seed de
 * usuários: falha de gravação vira log (sem @) e o .htaccess antigo é regravado.
 */
final class HardeningArmazenamentoTest extends MaposTestCase
{
    private string $raiz;

    private ?string $envAnterior;

    protected function setUp(): void
    {
        parent::setUp();
        $this->raiz = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mapos-' . bin2hex(random_bytes(4));
        mkdir($this->raiz, 0750, true);
        $this->envAnterior = $_ENV['STORAGE_PATH'] ?? null;
        $_ENV['STORAGE_PATH'] = $this->raiz;
    }

    protected function tearDown(): void
    {
        if ($this->envAnterior === null) {
            unset($_ENV['STORAGE_PATH']);
        } else {
            $_ENV['STORAGE_PATH'] = $this->envAnterior;
        }
        if (is_dir($this->raiz)) {
            chmod($this->raiz, 0750);
        }
        $this->apagar($this->raiz);
        parent::tearDown();
    }

    private function apagar(string $caminho): void
    {
        if (is_file($caminho) || is_link($caminho)) {
            unlink($caminho);

            return;
        }
        foreach (is_dir($caminho) ? scandir($caminho) : [] as $item) {
            if ($item !== '.' && $item !== '..') {
                $this->apagar($caminho . DIRECTORY_SEPARATOR . $item);
            }
        }
        if (is_dir($caminho)) {
            rmdir($caminho);
        }
    }

    private function pularSeRoot(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root escreve mesmo sem permissão.');
        }
    }

    public function testHtaccessDaRaizNovoTemAsDuasSintaxes(): void
    {
        armazenamentoPasta('os/12');

        $conteudo = (string) file_get_contents($this->raiz . '/.htaccess');
        $this->assertStringContainsString('<IfModule mod_authz_core.c>', $conteudo);
        $this->assertStringContainsString('Require all denied', $conteudo);
        $this->assertStringContainsString('<IfModule !mod_authz_core.c>', $conteudo);
        $this->assertStringContainsString('Deny from all', $conteudo);
    }

    public function testHtaccessAntigoSemModAuthzCoreERegravado(): void
    {
        file_put_contents($this->raiz . '/.htaccess', "Order allow,deny\nDeny from all\n");

        armazenamentoPasta('os/12');

        $conteudo = (string) file_get_contents($this->raiz . '/.htaccess');
        $this->assertStringContainsString('<IfModule mod_authz_core.c>', $conteudo);
        $this->assertStringContainsString('Require all denied', $conteudo);
    }

    public function testHtaccessComModAuthzCoreNaoETocado(): void
    {
        $original = "# do admin\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n";
        file_put_contents($this->raiz . '/.htaccess', $original);

        armazenamentoPasta('os/12');

        $this->assertSame($original, file_get_contents($this->raiz . '/.htaccess'));
    }

    public function testPastaPublicaNovaTemOsDoisBlocos(): void
    {
        armazenamentoProtegerPastaPublica($this->raiz);

        $conteudo = (string) file_get_contents($this->raiz . '/.htaccess');
        $this->assertStringContainsString('<FilesMatch', $conteudo);
        $this->assertStringContainsString("<IfModule mod_authz_core.c>\n        Require all denied", $conteudo);
        $this->assertStringContainsString("<IfModule !mod_authz_core.c>\n        Order allow,deny\n        Deny from all", $conteudo);
    }

    public function testPastaPublicaAntigaSemModAuthzCoreERegravada(): void
    {
        file_put_contents($this->raiz . '/.htaccess', "<FilesMatch \"\\.php\$\">\n    Order allow,deny\n    Deny from all\n</FilesMatch>\n");

        armazenamentoProtegerPastaPublica($this->raiz);

        $conteudo = (string) file_get_contents($this->raiz . '/.htaccess');
        $this->assertStringContainsString('Require all denied', $conteudo);
        $this->assertStringContainsString('<IfModule !mod_authz_core.c>', $conteudo);
    }

    public function testRaizSemEscritaDevolveNullELogaSemAviso(): void
    {
        $this->pularSeRoot();
        chmod($this->raiz, 0555);

        $this->assertNull(armazenamentoPasta('nova/pasta'));
        $this->assertLogContem('error', 'armazenamento falha operacao=mkdir');
        $this->assertLogContem('error', $this->raiz);
    }

    public function testHtaccessSemEscritaLogaGravar(): void
    {
        $this->pularSeRoot();
        chmod($this->raiz, 0555);

        armazenamentoProtegerPastaPublica($this->raiz);

        $this->assertLogContem('error', 'armazenamento falha operacao=gravar caminho=' . $this->raiz . DIRECTORY_SEPARATOR . '.htaccess');
    }

    public function testIndexHtmlSemEscritaLogaGravar(): void
    {
        $this->pularSeRoot();
        chmod($this->raiz, 0555);

        protegerDiretorioUpload([$this->raiz]);

        $this->assertLogContem('error', 'armazenamento falha operacao=gravar caminho=' . $this->raiz . DIRECTORY_SEPARATOR . 'index.html');
    }

    public function testIndexHtmlGravadoQuandoPossivel(): void
    {
        protegerDiretorioUpload([$this->raiz]);

        $this->assertStringContainsString('403 Forbidden', (string) file_get_contents($this->raiz . '/index.html'));
        $this->assertSame([], $this->logs('error'));
    }

    public function testErrorHandlerERestauradoDepoisDoAviso(): void
    {
        $this->pularSeRoot();
        chmod($this->raiz, 0555);

        $antes = set_error_handler(static fn (): bool => false);
        restore_error_handler();

        armazenamentoPasta('x');

        $depois = set_error_handler(static fn (): bool => false);
        restore_error_handler();
        $this->assertSame($antes, $depois);
    }

    public function testUnlinkQueFalhaLogaApagar(): void
    {
        $this->pularSeRoot();
        $arquivo = $this->raiz . '/falso.txt';
        file_put_contents($arquivo, "\x00\x01binario");
        chmod($this->raiz, 0555);

        $mensagem = armazenamentoValidarEnvio(['file_ext' => '.txt', 'full_path' => $arquivo]);

        $this->assertSame('O conteúdo do arquivo não confere com a extensão.', $mensagem);
        $this->assertLogContem('error', 'operacao=apagar');
    }

    public function testSeedSoInsereEmDevelopment(): void
    {
        require_once APPPATH . 'libraries/Seeder.php';
        require_once APPPATH . 'database/seeds/Usuarios.php';

        $this->db->query('CREATE TABLE usuarios (idUsuarios INTEGER, nome TEXT, rg TEXT, cpf TEXT, cep TEXT, rua TEXT, numero TEXT, bairro TEXT, cidade TEXT, estado TEXT, email TEXT, senha TEXT, telefone TEXT, celular TEXT, situacao INTEGER, dataCadastro TEXT, permissoes_id INTEGER, dataExpiracao TEXT)');
        $seed = $this->makeInstance(Usuarios::class);
        $this->setPrivateProperty($seed, 'db', $this->db);

        foreach (['production', 'testing'] as $ambiente) {
            ob_start();
            $this->assertFalse($seed->executar($ambiente));
            $saida = (string) ob_get_clean();
            $this->assertStringContainsString('Seed Usuarios ignorado: só roda com ENVIRONMENT = development.', $saida);
            $this->assertSame(0, $this->db->count_all('usuarios'));
        }

        ob_start();
        $this->assertTrue($seed->executar('development'));
        ob_end_clean();
        $linhas = $this->db->get('usuarios')->result();
        $this->assertCount(1, $linhas);
        $this->assertSame('admin@admin.com', $linhas[0]->email);
    }
}
