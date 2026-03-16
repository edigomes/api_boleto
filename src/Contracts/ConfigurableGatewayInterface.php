<?php

namespace ApiBoleto\Contracts;

use ApiBoleto\Config\ConfigSchema;

/**
 * Interface para gateways que expoe seu schema de configuracao.
 *
 * Permite validar e inspecionar a configuracao necessaria para cada banco
 * antes de instanciar o gateway.
 */
interface ConfigurableGatewayInterface
{
    /**
     * Retorna o schema de configuracao do banco.
     *
     * @return ConfigSchema
     */
    public static function configSchema(): ConfigSchema;
}
