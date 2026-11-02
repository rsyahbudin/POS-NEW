<?php

namespace App\Http\Middleware;

use App\Domain\Printing\PrintBridge;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The print bridge in the cafe has no login: it proves itself with the token made on the Printer page. */
class AuthenticatePrintBridge
{
    public function __construct(private PrintBridge $bridge) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->bridge->accepts((string) $request->bearerToken()), 401, 'Token jembatan cetak tidak valid.');
        $this->bridge->seen();

        return $next($request);
    }
}
