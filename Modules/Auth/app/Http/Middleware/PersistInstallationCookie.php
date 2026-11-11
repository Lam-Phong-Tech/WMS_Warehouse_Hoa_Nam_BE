<?php

namespace Modules\Auth\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

class PersistInstallationCookie
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $cookie = $request->attributes->get('wms.installation_cookie')
            ?? request()->attributes->get('wms.installation_cookie');

        if ($cookie instanceof Cookie) {
            $response->headers->setCookie($cookie);
        }

        $trustCookie = $request->attributes->get('wms.trusted_device_cookie')
            ?? request()->attributes->get('wms.trusted_device_cookie');

        if ($trustCookie instanceof Cookie) {
            $response->headers->setCookie($trustCookie);
        }

        return $response;
    }
}
