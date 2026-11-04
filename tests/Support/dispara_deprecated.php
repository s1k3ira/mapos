<?php

/**
 * Apoio de SuporteDeTesteTest: dispara um E_USER_DEPRECATED a partir de um
 * arquivo conhecido, para provar como semDeprecated() trata a origem.
 */
function mapos_teste_dispara_deprecated(): void
{
    trigger_error('teste', E_USER_DEPRECATED);
}
