<?php

namespace Modules\Warehouse\Http\Requests;

use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StorePhysicalCodeRequest extends FormRequest
{
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
            'assigned_object_type' => ['nullable', Rule::in(['ITEM', 'CONTAINER'])],
            'assigned_object_id' => ['nullable', 'required_with:assigned_object_type', 'uuid'],
            'encoded_payload_version' => ['nullable', 'string', 'max:30'],
        ];
    }

    public function withValidator(ValidatorContract $validator): void
    {
        $validator->after(function (ValidatorContract $validator): void {
            $codeType = strtoupper((string) $this->input('code_type'));
            $carrier = strtoupper((string) $this->input('carrier_type'));
            $targetType = strtoupper((string) $this->input('assigned_object_type'));
            $targetId = $this->input('assigned_object_id');

            if ($codeType === 'ITEM' && ! in_array($carrier, self::CHIP_CARRIERS, true)) {
                $validator->errors()->add('carrier_type', 'Mã vật lý loại ITEM bắt buộc dùng QR/BARCODE/RFID/NFC.');
            }
            if ($carrier === 'RFID' && trim((string) $this->input('hardware_uid')) === '') {
                $validator->errors()->add('hardware_uid', 'RFID bắt buộc có hardware_uid duy nhất.');
            }
            if ($targetType === 'ITEM') {
                if ($codeType !== 'ITEM') {
                    $validator->errors()->add('code_type', 'Physical code map ITEM phải có code_type=ITEM.');
                }
                if ($targetId && ! DB::table('item_instances')->where('item_id', $targetId)->exists()) {
                    $validator->errors()->add('assigned_object_id', 'ITEM target không tồn tại.');
                }
            }
            if ($targetType === 'CONTAINER' && $targetId && ! DB::table('containers')->where('container_id', $targetId)->exists()) {
                $validator->errors()->add('assigned_object_id', 'CONTAINER target không tồn tại.');
            }
        });
    }
}
