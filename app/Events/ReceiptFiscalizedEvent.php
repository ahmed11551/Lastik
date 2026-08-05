<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast when a fiscal receipt reaches FISCALIZED (POS UI update).
 */
class ReceiptFiscalizedEvent implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public int $tenantId,
        public int $fiscalReceiptId,
        public ?int $orderId = null,
        public ?string $fdNumber = null,
        public ?string $fnNumber = null,
    ) {}

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('tenant.'.$this->tenantId.'.fiscal'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'receipt.fiscalized';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'fiscal_receipt_id' => $this->fiscalReceiptId,
            'order_id' => $this->orderId,
            'fd_number' => $this->fdNumber,
            'fn_number' => $this->fnNumber,
        ];
    }
}
