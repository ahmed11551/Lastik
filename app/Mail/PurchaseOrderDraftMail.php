<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Mail;

use Illuminate\Mail\Mailable;

/**
 * Печатная форма заказа поставщику (черновик) с CSV-вложением.
 */
final class PurchaseOrderDraftMail extends Mailable
{
    public function __construct(
        public string $htmlBody,
        public string $csvData,
        public string $csvFilename,
    ) {}

    public function build(): self
    {
        return $this
            ->subject('Заказ поставщику (черновик)')
            ->html($this->htmlBody)
            ->attachData($this->csvData, $this->csvFilename, ['mime' => 'text/csv']);
    }
}
