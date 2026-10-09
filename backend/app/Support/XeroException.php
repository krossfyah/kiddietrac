<?php

declare(strict_types=1);

namespace App\Support;

/** A Xero failure with a message fit to show an admin; $oauthError holds Xero's OAuth error key. */
final class XeroException extends \RuntimeException
{
    public ?string $oauthError;

    public function __construct(string $message, int $status = 0, ?string $oauthError = null)
    {
        parent::__construct($message, $status);
        $this->oauthError = $oauthError;
    }
}
