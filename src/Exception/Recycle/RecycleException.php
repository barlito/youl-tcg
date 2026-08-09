<?php

declare(strict_types=1);

namespace App\Exception\Recycle;

abstract class RecycleException extends \RuntimeException
{
    /**
     * @param string $message     technical message (logs, tests)
     * @param string $userMessage player-facing French message, safe to render as-is
     */
    public function __construct(string $message, private readonly string $userMessage)
    {
        parent::__construct($message);
    }

    public function getUserMessage(): string
    {
        return $this->userMessage;
    }
}
