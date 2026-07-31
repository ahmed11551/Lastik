<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\DTOs\CreateOrderDTO;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Запрет подмены контекста из HTTP body
            'tenant_id' => ['prohibited'],
            'location_id' => ['prohibited'],
            'created_by' => ['prohibited'],
            'updated_by' => ['prohibited'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'vehicle_id' => ['nullable', 'integer', 'exists:vehicles,id'],
            'scenario' => ['nullable', 'string', 'in:with_installation,without_installation,standard'],
            'assigned_seller_id' => ['required', 'integer', 'exists:users,id'],
            'master_id' => ['nullable', 'integer', 'exists:users,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.type' => ['required', 'string', 'in:product,service'],
            'items.*.product_id' => ['required', 'integer', 'exists:products_services,id'],
            'items.*.qty' => ['required', 'numeric', 'min:0.001'],
            'items.*.price' => ['required', 'numeric', 'min:0'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0'],
            'items.*.warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'items.*.worker_id' => ['required_if:items.*.type,service', 'nullable', 'integer', 'exists:users,id'],
            'items.*.radius' => ['nullable', 'string', 'max:10'],
            'items.*.commission_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function createOrderDTO(): CreateOrderDTO
    {
        $tenantId = (int) ($this->user()?->tenant_id ?? tenant_id() ?? 0);
        $locationId = (int) (location_id() ?? $this->user()?->location_id ?? 0);

        return CreateOrderDTO::fromRequest(
            [
                'customer_id' => $this->filled('customer_id') ? (int) $this->input('customer_id') : null,
                'vehicle_id' => $this->filled('vehicle_id') ? (int) $this->input('vehicle_id') : null,
                'scenario' => $this->input('scenario', 'without_installation'),
                'assigned_seller_id' => (int) $this->input('assigned_seller_id'),
                'master_id' => (int) ($this->input('master_id') ?? 0),
                'items' => $this->input('items', []),
                'note' => $this->input('note'),
            ],
            $tenantId,
            $locationId,
        );
    }

    protected function failedValidation(Validator $validator): void
    {
        throw ValidationException::withMessages(
            $validator->errors()->toArray()
        );
    }
}
