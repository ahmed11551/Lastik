<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

class CommerceMLImportException extends \RuntimeException
{
    public static function invalidXml(string $detail): self
    {
        return new self('CommerceML Import Error: '.$detail);
    }

    public static function emptyBatch(string $detail): self
    {
        return new self('CommerceML Empty Batch: '.$detail);
    }
}
