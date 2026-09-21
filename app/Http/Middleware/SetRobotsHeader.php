<?php

namespace App\Http\Middleware;

use App\Services\SearchIdentity;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * v2.75.0 -- `noindex` BY DEFAULT.
 *
 * Adds `X-Robots-Tag: noindex, nofollow` to every response that is not
 * one of the public pages in config/seo.php, served on the canonical host,
 * in production. See App\Services\SearchIdentity for the three conditions.
 *
 * A HEADER, not only the meta tag the layout also prints, because a header
 * covers what a meta tag cannot: PDFs, invoice downloads, JSON, redirects
 * and error pages. And global rather than on a route group, for the reason
 * docs/CONVENTIONS.md keeps recording -- group membership is exactly what
 * this repository has repeatedly got wrong. A route written tomorrow is
 * private until somebody lists it in config/seo.php.
 *
 * It only ADDS a header. It reads no session, changes no status and touches
 * no authentication, authorization or tenant state.
 */
class SetRobotsHeader
{
    public function __construct(private readonly SearchIdentity $search) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $this->search->isIndexable($request)) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }
}
