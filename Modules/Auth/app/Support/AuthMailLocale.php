<?php

namespace Modules\Auth\Support;

use Illuminate\Http\Request;

final class AuthMailLocale
{
    public static function fromRequest(Request $request): string
    {
        return $request->getPreferredLanguage(['vi', 'en', 'zh']) ?? 'vi';
    }
}
