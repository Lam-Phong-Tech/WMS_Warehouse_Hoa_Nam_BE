<?php

namespace Modules\Warehouse\Http\Requests;

use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Warehouse\Models\PhysicalCode;

class UpdatePhysicalCodeRequest extends FormRequest
{
    private const CHIP_CARRIERS = ['QR', 'BARCODE', 'RFID', 'NFC'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code_value' => ['sometimes', 'string', 'max:100', Rule::unique('physical_codes', 'code_value')->ignore($this->route('id'), 'physical_code_id')],
            'code_type' => ['sometimes', Rule::in(['ITEM', 'CARTON', 'COMPONENT_SERIAL', 'BOX', 'TRAY', 'TEMP_WARRANTY', 'UNKNOWN'])],
            'carrier_type' => ['sometimes', Rule::in(['QR', 'BARCODE', 'RFID', 'NFC', 'PLAIN_LABEL', 'MANUAL_CODE', 'TEMP_CODE'])],
            'hardware_uid' => ['nullable', 'string', 'max:150', Rule::unique('physical_codes', 'hardware_uid')->ignore($this->route('id'), 'physical_code_id')],
            'encoded_payload_version' => ['nullable', 'string', 'max:30'],
            'status' => ['nullable', Rule::in(['ACTIVE', 'LOST', 'REPLACED', 'INACTIVE'])],
            'replaced_by_code_id' => ['nullable', 'uuid', 'exists:physical_codes,physical_code_id'],
        ];
    }

    public function withValidator(ValidatorContract $validator): void
    {
        $validator->after(function (ValidatorContract $validator) {
            $codeType = $this->input('code_type');
            $carrierType = $this->input('carrier_type');

            if ($codeType === null && $carrierType === null) {
                return;
            }

            $physicalCode = PhysicalCode::find($this->route('id'));
            $effectiveType = $codeType ?? $physicalCode?->code_type;
            $effectiveCarrier = $carrierType ?? $physicalCode?->carrier_type;

            if ($effectiveType === 'ITEM' && ! in_array($effectiveCarrier, self::CHIP_CARRIERS, true)) {
                $validator->errors()->add('carrier_type', 'Mã vật lý loại ITEM bắt buộc dùng carrier chip (QR/BARCODE/RFID/NFC).');
            }
        });
    }
}
