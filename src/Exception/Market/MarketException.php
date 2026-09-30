<?php

declare(strict_types=1);

namespace App\Exception\Market;

abstract class MarketException extends \RuntimeException
{
    public function __construct(string $message, private readonly string $userMessage)
    {
        parent::__construct($message);
    }

    public function getUserMessage(): string
    {
        return $this->userMessage;
    }
}
