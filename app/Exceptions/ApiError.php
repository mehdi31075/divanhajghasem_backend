<?php

namespace App\Exceptions;

use Exception;

class ApiError extends Exception
{
    public $status;

    public $errorCode;

    public function __construct($status, $code, $message)
    {
        parent::__construct($message);
        $this->status = $status;
        $this->errorCode = $code;
    }
}
