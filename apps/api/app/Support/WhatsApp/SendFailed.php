<?php

namespace App\Support\WhatsApp;

class SendFailed extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $final = false, public readonly ?string $metaCode = null)
    {
        parent::__construct($message);
    }
}
