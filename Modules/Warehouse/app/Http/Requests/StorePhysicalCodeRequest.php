<?php

namespace Modules\Warehouse\Http\Requests;

use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePhysicalCodeRequest extends FormRequest
{
    /** carrier_type bắt buộc phải là chip khi code_type=ITEM (ERD §5.2 physical_code). */
    private const CHIP_CARRIERS = ['QR', 'BARCODE', 'RFID', 'NFC'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code_value' => ['required', 'string', 'max:100', 'unique:physical_codes,code_value'],
            'code_type' => ['required', Rule::in(['ITEM', 'CARTON', 'COMPONENT_SERIAL', 'BOX', 'TRAY', 'TEMP_WARRANTY', 'UNKNOWN'])],
            'carrier_type' => ['required', Rule::in(['QR', 'BARCODE', 'RFID', 'NFC', 'PLAIN_LABEL', 'MANUAL_CODE', 'TEMP_CODE'])],
            'hardware_uid' => ['nullable', 'string', 'max:150', 'unique:physical_codes,hardware_uid'],
            'encoded_payload_version' => ['nullable', 'string', 'max:30'],
        ];
    }

    public function withValidator(ValidatorContract $validator): void
    {
        $validator->after(function (ValidatorContract $validator) {
            if ($this->input('code_type') === 'ITEM' && ! in_array($this->input('carrier_type'), self::CHIP_CARRIERS, true)) {
                $validator->errors()->add('carrier_type', 'Mã vật lý loại ITEM bắt buộc dùng carrier chip (QR/BARCODE/RFID/NFC).');
            }
        });
    }
}
