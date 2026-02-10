<?php

declare(strict_types=1);

namespace FoleyBridgeSolutions\KotapayCashier\Exceptions;

use Exception;
use Throwable;

class KotapayException extends Exception
{
    /**
     * The response from the API.
     *
     * @var array
     */
    protected $response;

    /**
     * Create a new exception instance.
     *
     * @param  string  $message
     * @param  array  $response
     * @param  int  $code
     * @param  \Throwable|null  $previous
     * @return void
     */
    public function __construct(string $message = '', array $response = [], int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);

        $this->response = $response;
    }

    /**
     * Get the API response.
     *
     * @return array
     */
    public function getResponse(): array
    {
        return $this->response;
    }
}
