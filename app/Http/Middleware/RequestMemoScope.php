<?php

namespace App\Http\Middleware;

use App\Support\RequestMemo;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** v2.141.0 -- opens and closes App\Support\RequestMemo around each request. */
class RequestMemoScope
{
    public function handle(Request $request, Closure $next): Response
    {
        RequestMemo::begin();

        try {
            return $next($request);
        } finally {
            RequestMemo::end();
        }
    }
}
