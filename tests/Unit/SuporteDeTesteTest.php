<?php

use PHPUnit\Framework\AssertionFailedError;

require_once MAPOS_ROOT . '/tests/Support/dispara_deprecated.php';

final class SuporteDeTesteTest extends MaposTestCase
{
    public function testLogDeErroFicaRegistradoComONivelEmMinusculas(): void
    {
        log_message('ERROR', 'gateway op=criar cobranca=7');
        log_message('info', 'outra coisa');

        $this->assertSame(['gateway op=criar cobranca=7'], $this->logs('error'));
    }

    public function testLogsSemFiltroDevolveTodasAsMensagensNaOrdem(): void
    {
        log_message('error', 'a');
        log_message('INFO', 'b');

        $this->assertSame(['a', 'b'], $this->logs());
    }

    public function testLogsDoTesteAnteriorNaoVazamParaOProximo(): void
    {
        $this->assertSame([], $this->logs());

        log_message('error', 'sujeira');
    }

    public function testLogsContinuamZeradosNoTesteSeguinte(): void
    {
        $this->assertSame([], $this->logs());
    }

    public function testAssertLogContemPassaQuandoOTrechoExisteNoNivel(): void
    {
        log_message('error', 'gateway op=criar cobranca=7');

        $this->assertLogContem('error', 'op=criar');
        $this->assertSame(['gateway op=criar cobranca=7'], $this->logs('error'));
    }

    public function testAssertLogContemFalhaSemNenhumLog(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->assertLogContem('error', 'op=criar');
    }

    public function testAssertLogContemFalhaQuandoOTrechoEstaEmOutroNivel(): void
    {
        log_message('info', 'op=criar');

        $this->expectException(AssertionFailedError::class);

        $this->assertLogContem('error', 'op=criar');
    }

    public function testAssertLogNaoContemPassaQuandoNenhumLogTemOTrecho(): void
    {
        log_message('error', 'cpf mascarado');

        $this->assertLogNaoContem('529.982.247-25');
        $this->assertSame(['cpf mascarado'], $this->logs());
    }

    public function testAssertLogNaoContemFalhaQuandoAlgumLogTemOTrecho(): void
    {
        log_message('info', 'cpf=529.982.247-25');

        $this->expectException(AssertionFailedError::class);

        $this->assertLogNaoContem('529.982.247-25');
    }

    public function testSemDeprecatedDevolveORetornoDaAcao(): void
    {
        $this->assertSame(42, $this->semDeprecated(static fn () => 42));
    }

    public function testDeprecatedDentroDaRaizReprovaOTeste(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('dispara_deprecated.php');

        $this->semDeprecated(
            static fn () => mapos_teste_dispara_deprecated(),
            MAPOS_ROOT . '/tests/Support/'
        );
    }

    public function testDeprecatedForaDaRaizEIgnorado(): void
    {
        $resultado = $this->semDeprecated(static function (): string {
            mapos_teste_dispara_deprecated();

            return 'seguiu';
        });

        $this->assertSame('seguiu', $resultado);
    }

    public function testDeprecatedEmApplicationVendorEIgnoradoMesmoComRaizApplication(): void
    {
        $raiz = APPPATH . 'vendor' . DIRECTORY_SEPARATOR;
        // A raiz padrão é APPPATH; o arquivo de apoio está em tests/, logo fora dela.
        $this->assertSame(
            'ok',
            $this->semDeprecated(static function (): string {
                mapos_teste_dispara_deprecated();

                return 'ok';
            }, APPPATH)
        );
        $this->assertStringStartsWith(APPPATH, $raiz);
    }

    public function testSemDeprecatedRestauraOHandlerAnteriorQuandoAAcaoLanca(): void
    {
        $handlerAnterior = static fn () => false;
        set_error_handler($handlerAnterior);

        try {
            $this->semDeprecated(static function (): void {
                throw new RuntimeException('falhou');
            });
            $this->fail('A exceção deveria se propagar');
        } catch (RuntimeException $e) {
            $this->assertSame('falhou', $e->getMessage());
        }

        $ativo = set_error_handler(static fn () => false);
        restore_error_handler();
        restore_error_handler();

        $this->assertSame($handlerAnterior, $ativo);
    }
}
