<?php

use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Base dos testes do Map-OS.
 *
 * Cada teste recebe um SQLite em memória novo e acesso a reflexão, que é como
 * as classes do CodeIgniter são testadas: elas foram escritas para viver dentro
 * do framework, então não dá para construí-las do jeito normal.
 */
abstract class MaposTestCase extends PHPUnitTestCase
{
    /** @var object Conexão do Query Builder em SQLite em memória */
    protected $db;

    protected function setUp(): void
    {
        $this->db = maposTestDatabase();
        // Depois de abrir o banco: o driver do CI loga a própria inicialização,
        // e o teste deve começar com o log vazio.
        $GLOBALS['__mapos_logs'] = [];
    }

    /**
     * Mensagens registradas por log_message() neste teste, filtradas pelo nível
     * (sem diferenciar maiúsculas) quando informado.
     *
     * @return list<string>
     */
    protected function logs(?string $nivel = null): array
    {
        $nivel = $nivel === null ? null : strtolower($nivel);
        $mensagens = [];

        foreach ($GLOBALS['__mapos_logs'] ?? [] as $registro) {
            if ($nivel === null || $registro['nivel'] === $nivel) {
                $mensagens[] = $registro['mensagem'];
            }
        }

        return $mensagens;
    }

    protected function assertLogContem(string $nivel, string $trecho): void
    {
        foreach ($this->logs($nivel) as $mensagem) {
            if (str_contains($mensagem, $trecho)) {
                $this->addToAssertionCount(1);

                return;
            }
        }

        $this->fail($this->falhaDeLog("Nenhum log '{$nivel}' contém '{$trecho}'."));
    }

    protected function assertLogNaoContem(string $trecho): void
    {
        foreach ($GLOBALS['__mapos_logs'] ?? [] as $registro) {
            if (str_contains($registro['mensagem'], $trecho)) {
                $this->fail($this->falhaDeLog("O log '{$registro['nivel']}' contém '{$trecho}'."));
            }
        }

        $this->addToAssertionCount(1);
    }

    private function falhaDeLog(string $mensagem): string
    {
        return $mensagem . ' Logs capturados: ' . json_encode($GLOBALS['__mapos_logs'] ?? [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Executa a ação e reprova o teste se ela disparar Deprecated vindo de
     * arquivo sob $raiz (padrão: APPPATH), ignorando application/vendor/.
     *
     * @return mixed O retorno da ação
     */
    protected function semDeprecated(callable $acao, ?string $raiz = null): mixed
    {
        $raiz ??= APPPATH;
        $vendor = APPPATH . 'vendor' . DIRECTORY_SEPARATOR;
        $ocorrencia = null;

        set_error_handler(
            static function (int $errno, string $errstr, string $errfile, int $errline) use ($raiz, $vendor, &$ocorrencia): bool {
                if (str_starts_with($errfile, $raiz) && ! str_starts_with($errfile, $vendor)) {
                    $ocorrencia ??= [$errfile, $errline, $errstr];

                    return true;
                }

                return false;
            },
            E_DEPRECATED | E_USER_DEPRECATED
        );

        try {
            $retorno = $acao();
        } finally {
            restore_error_handler();
        }

        if ($ocorrencia !== null) {
            $this->fail("Deprecated em {$ocorrencia[0]}:{$ocorrencia[1]}: {$ocorrencia[2]}");
        }

        return $retorno;
    }

    /**
     * Cria a instância de uma classe sem passar pelo construtor.
     *
     * O construtor das classes do CodeIgniter chama get_instance() e carrega o
     * banco; nos testes só o método é de interesse.
     */
    protected function makeInstance(string $className)
    {
        return (new ReflectionClass($className))->newInstanceWithoutConstructor();
    }

    /**
     * Chama um método, inclusive privado, sem depender da visibilidade.
     */
    protected function invokeMethod(object $object, string $method, array $arguments = [])
    {
        // Desde o PHP 8.1 a reflexão ignora a visibilidade, então não é preciso
        // chamar setAccessible(), que virou deprecado no 8.5.
        return (new ReflectionMethod($object, $method))->invokeArgs($object, $arguments);
    }

    /**
     * Escreve em uma propriedade privada, inclusive para injetar dependências
     * que o construtor normalmente montaria.
     */
    protected function setPrivateProperty(object $object, string $property, $value): void
    {
        (new ReflectionProperty($object, $property))->setValue($object, $value);
    }

    /**
     * Lê uma propriedade privada, inclusive para conferir o estado depois de
     * uma chamada.
     */
    protected function readPrivateProperty(object $object, string $property)
    {
        return (new ReflectionProperty($object, $property))->getValue($object);
    }

    /**
     * Objeto mínimo no formato que as classes esperam do $this->CI.
     */
    protected function fakeCiInstance($db)
    {
        return (object) ['db' => $db];
    }
}
