<?php

namespace Modules\WhatsAppVendorConcierge\app\Exceptions;

use Exception;

class AllAiProvidersFailedException extends Exception
{
    public function __construct(string $message = 'All configured AI providers and models failed or were unavailable.', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
