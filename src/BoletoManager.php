<?php

namespace ApiBoleto;

use ApiBoleto\Banks\Santander\SantanderGateway;
use ApiBoleto\Contracts\BoletoGatewayInterface;
use ApiBoleto\Exceptions\BoletoException;

class BoletoManager
{
    /** @var array<string, string> Mapa de nome do banco => classe do gateway */
    private array $bancos = [];

    /** @var array<string, BoletoGatewayInterface> Instancias em cache */
    private array $instancias = [];

    public function __construct()
    {
        $this->registrarBancosNativos();
    }

    /**
     * Registra um gateway de banco.
     *
     * @param string $nome Identificador do banco (ex: 'santander', 'bradesco')
     * @param string $gatewayClass FQCN da classe que implementa BoletoGatewayInterface
     */
    public function registrarBanco(string $nome, string $gatewayClass): void
    {
        $this->bancos[strtolower($nome)] = $gatewayClass;
    }

    /**
     * Retorna o gateway de um banco, instanciando se necessario.
     *
     * @param string $nome Identificador do banco
     * @param array $config Configuracao especifica do banco
     * @return BoletoGatewayInterface
     */
    public function banco(string $nome, array $config = []): BoletoGatewayInterface
    {
        $nome = strtolower($nome);

        if (!empty($config) || !isset($this->instancias[$nome])) {
            $this->instancias[$nome] = $this->criarGateway($nome, $config);
        }

        return $this->instancias[$nome];
    }

    /**
     * Retorna a lista de bancos registrados.
     *
     * @return string[]
     */
    public function bancosDisponiveis(): array
    {
        return array_keys($this->bancos);
    }

    private function criarGateway(string $nome, array $config): BoletoGatewayInterface
    {
        if (!isset($this->bancos[$nome])) {
            throw new BoletoException(
                "Banco '{$nome}' nao registrado. Bancos disponiveis: "
                . implode(', ', $this->bancosDisponiveis())
            );
        }

        $gatewayClass = $this->bancos[$nome];

        if (!class_exists($gatewayClass)) {
            throw new BoletoException("Classe do gateway nao encontrada: {$gatewayClass}");
        }

        $gateway = new $gatewayClass($config);

        if (!$gateway instanceof BoletoGatewayInterface) {
            throw new BoletoException(
                "A classe {$gatewayClass} deve implementar BoletoGatewayInterface."
            );
        }

        return $gateway;
    }

    private function registrarBancosNativos(): void
    {
        $this->registrarBanco('santander', SantanderGateway::class);
    }
}
