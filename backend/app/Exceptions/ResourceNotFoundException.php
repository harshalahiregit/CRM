<?php

namespace App\Exceptions;

class ResourceNotFoundException extends BusinessException
{
    public function __construct(string $resource = 'Resource')
    {
        parent::__construct("{$resource} not found.", 404);
    }
}
