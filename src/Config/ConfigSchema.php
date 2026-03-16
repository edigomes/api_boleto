<?php

namespace ApiBoleto\Config;

use ApiBoleto\Exceptions\BoletoException;

/**
 * Define e valida o schema de configuracao de um gateway bancario.
 *
 * Uso fluente:
 *   ConfigSchema::create('Santander')
 *       ->required('clientId', 'string', 'Client ID')
 *       ->requireOneOf('certificado', [['certFile', 'certKeyFile'], ['certContent', 'certKeyContent']])
 *       ->optional('ambiente', 'string', 'producao', 'Ambiente')
 *       ->validate($config);
 */
class ConfigSchema
{
    /** @var string Nome do banco (para mensagens de erro) */
    private string $banco;

    /** @var array<string, array> Campos obrigatorios: key => ['type', 'label'] */
    private array $required = [];

    /** @var array<string, array> Campos opcionais: key => ['type', 'default', 'label'] */
    private array $optional = [];

    /** @var array<string, array> Grupos "um de": name => ['sets' => [...], 'message' => string] */
    private array $oneOfGroups = [];

    private function __construct(string $banco)
    {
        $this->banco = $banco;
    }

    public static function create(string $banco): self
    {
        return new self($banco);
    }

    /**
     * Define um campo obrigatorio.
     *
     * @param string $field Nome do campo
     * @param string $type Tipo esperado: 'string', 'int', 'bool', 'array' ou FQCN de classe/interface
     * @param string $label Descricao legivel do campo
     */
    public function required(string $field, string $type, string $label): self
    {
        $this->required[$field] = [
            'type'  => $type,
            'label' => $label,
        ];
        return $this;
    }

    /**
     * Define um campo opcional com valor default.
     *
     * @param string $field Nome do campo
     * @param string $type Tipo esperado
     * @param mixed $default Valor padrao quando ausente
     * @param string $label Descricao legivel
     */
    public function optional(string $field, string $type, $default, string $label): self
    {
        $this->optional[$field] = [
            'type'    => $type,
            'default' => $default,
            'label'   => $label,
        ];
        return $this;
    }

    /**
     * Define um grupo "pelo menos um conjunto deve estar presente".
     *
     * @param string $name Nome do grupo (para referencia)
     * @param array $sets Array de sets, cada set e um array de nomes de campo
     * @param string $message Mensagem de erro quando nenhum set esta completo
     */
    public function requireOneOf(string $name, array $sets, string $message): self
    {
        $this->oneOfGroups[$name] = [
            'sets'    => $sets,
            'message' => $message,
        ];
        return $this;
    }

    /**
     * Valida um array de configuracao contra este schema.
     *
     * @throws BoletoException Se a configuracao for invalida
     */
    public function validate(array $config): void
    {
        $errors = [];

        $this->validateRequired($config, $errors);
        $this->validateOneOfGroups($config, $errors);
        $this->validateTypes($config, $errors);

        if (!empty($errors)) {
            $intro = "Configuracao invalida para o gateway {$this->banco}:";
            throw new BoletoException($intro . "\n- " . implode("\n- ", $errors));
        }
    }

    /**
     * Retorna a descricao estruturada de todos os campos do schema.
     *
     * @return array Lista de campos com tipo, obrigatoriedade, default e descricao
     */
    public function describe(): array
    {
        $fields = [];

        foreach ($this->required as $field => $meta) {
            $fields[$field] = [
                'required' => true,
                'type'     => $meta['type'],
                'default'  => null,
                'label'    => $meta['label'],
            ];
        }

        foreach ($this->optional as $field => $meta) {
            $fields[$field] = [
                'required' => false,
                'type'     => $meta['type'],
                'default'  => $meta['default'],
                'label'    => $meta['label'],
            ];
        }

        foreach ($this->oneOfGroups as $name => $group) {
            foreach ($group['sets'] as $set) {
                foreach ($set as $field) {
                    if (!isset($fields[$field])) {
                        $fields[$field] = [
                            'required' => false,
                            'type'     => 'string',
                            'default'  => null,
                            'label'    => "Parte do grupo '{$name}': {$group['message']}",
                            'group'    => $name,
                        ];
                    }
                }
            }
        }

        return $fields;
    }

    /**
     * Retorna o nome do banco.
     */
    public function getBanco(): string
    {
        return $this->banco;
    }

    /**
     * Retorna os nomes dos campos obrigatorios.
     *
     * @return string[]
     */
    public function getRequiredFields(): array
    {
        return array_keys($this->required);
    }

    /**
     * Retorna os nomes dos campos opcionais.
     *
     * @return string[]
     */
    public function getOptionalFields(): array
    {
        return array_keys($this->optional);
    }

    /**
     * Retorna os nomes dos grupos oneOf.
     *
     * @return string[]
     */
    public function getOneOfGroups(): array
    {
        return array_keys($this->oneOfGroups);
    }

    private function validateRequired(array $config, array &$errors): void
    {
        foreach ($this->required as $field => $meta) {
            if (!isset($config[$field]) || $config[$field] === '' || $config[$field] === null) {
                $errors[] = "Campo '{$field}' e obrigatorio ({$meta['label']}).";
            }
        }
    }

    private function validateOneOfGroups(array $config, array &$errors): void
    {
        foreach ($this->oneOfGroups as $name => $group) {
            $anySetComplete = false;

            foreach ($group['sets'] as $set) {
                $setComplete = true;
                foreach ($set as $field) {
                    if (empty($config[$field]) && !$this->isValidObject($config, $field)) {
                        $setComplete = false;
                        break;
                    }
                }
                if ($setComplete) {
                    $anySetComplete = true;
                    break;
                }
            }

            if (!$anySetComplete) {
                $errors[] = $group['message'];
            }
        }
    }

    private function validateTypes(array $config, array &$errors): void
    {
        $allFields = array_merge($this->required, $this->optional);

        foreach ($allFields as $field => $meta) {
            if (!isset($config[$field])) {
                continue;
            }

            $value = $config[$field];
            $expectedType = $meta['type'];

            if (!$this->checkType($value, $expectedType)) {
                $actual = is_object($value) ? get_class($value) : gettype($value);
                $errors[] = "Campo '{$field}' deve ser do tipo '{$expectedType}', recebeu '{$actual}'.";
            }
        }
    }

    private function checkType($value, string $type): bool
    {
        switch ($type) {
            case 'string':
                return is_string($value);
            case 'int':
            case 'integer':
                return is_int($value);
            case 'bool':
            case 'boolean':
                return is_bool($value);
            case 'array':
                return is_array($value);
            case 'mixed':
                return true;
            default:
                return $value instanceof $type;
        }
    }

    /**
     * Verifica se o campo e um objeto valido (para campos que aceitam instancias).
     */
    private function isValidObject(array $config, string $field): bool
    {
        return isset($config[$field]) && is_object($config[$field]);
    }
}
