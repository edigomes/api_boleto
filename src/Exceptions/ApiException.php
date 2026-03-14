<?php

namespace ApiBoleto\Exceptions;

class ApiException extends BoletoException
{
    /** @var int */
    private $statusCode;

    /** @var array */
    private $responseBody;

    public function __construct(string $message, int $statusCode, array $responseBody = [], ?\Throwable $previous = null)
    {
        $this->statusCode = $statusCode;
        $this->responseBody = $responseBody;
        parent::__construct($message, $statusCode, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getResponseBody(): array
    {
        return $this->responseBody;
    }
}
