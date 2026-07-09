<?php

namespace App\Exceptions;

class UnauthorizedTenantException extends BusinessException
{
    public function __construct(string $message = 'You do not have access to this resource.')
    {
        parent::__construct($message, 403);
    }
}
