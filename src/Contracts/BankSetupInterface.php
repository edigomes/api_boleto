<?php

namespace ApiBoleto\Contracts;

/**
 * Interface para configuracao/setup inicial de um banco.
 *
 * Cada banco tem seu fluxo de setup. Exemplos:
 * - Santander: criar Workspace, configurar webhook, definir convenios
 * - Outros bancos: podem ter fluxos diferentes (registrar conta, ativar servico, etc.)
 *
 * Gateways que implementam esta interface indicam que possuem um fluxo de setup
 * que deve ser executado antes de operar boletos.
 */
interface BankSetupInterface
{
    /**
     * Executa a configuracao inicial no banco.
     *
     * @param array $params Parametros especificos do banco para o setup.
     *   Santander: ['covenantCode' => '...', 'webhookUrl' => '...', 'description' => '...']
     * @return array Dados retornados pelo banco (ex: ID do workspace, status, etc.)
     */
    public function setup(array $params): array;

    /**
     * Consulta a configuracao atual.
     *
     * @param string|null $id ID da configuracao (ex: workspaceId). Se null, lista todas.
     * @return array Dados da configuracao.
     */
    public function consultarSetup(?string $id = null): array;

    /**
     * Atualiza a configuracao existente.
     *
     * @param string $id ID da configuracao
     * @param array $params Parametros a atualizar
     * @return array Dados atualizados.
     */
    public function atualizarSetup(string $id, array $params): array;

    /**
     * Verifica se o gateway ja esta configurado e pronto para operar.
     *
     * @return bool
     */
    public function isConfigurado(): bool;
}
