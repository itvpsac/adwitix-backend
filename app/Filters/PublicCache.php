<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

// D-135: short shared caching for public, anonymous GET responses (the
// TradeSphereX list, marketplace browse), so the browser and Hostinger's
// CDN can answer repeats without a round trip to the origin, which costs
// ~0.35 s per request. Only a 200 to a request with no Authorization header
// is marked cacheable; anything signed in, any error and every other route
// keeps CodeIgniter's default no-store. Vary keeps per-origin CORS headers
// and signed-in responses from being served from the anonymous copy.
class PublicCache implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        if (strtolower($request->getMethod()) !== 'get'
            || $request->getHeaderLine('Authorization') !== ''
            || $response->getStatusCode() !== 200) {
            return;
        }
        $seconds = max(1, (int) ($arguments[0] ?? 30));
        // CodeIgniter's default no-store is an array header that setHeader()
        // would append to, so it is removed first.
        $response->removeHeader('Cache-Control');
        $response->setHeader('Cache-Control', "public, max-age={$seconds}, s-maxage=" . ($seconds * 2) . ', stale-while-revalidate=' . ($seconds * 2));
        $response->removeHeader('Pragma');
        $vary = array_filter(array_map('trim', explode(',', $response->getHeaderLine('Vary'))));
        $response->removeHeader('Vary');
        $response->setHeader('Vary', implode(', ', array_unique(array_merge($vary, ['Origin', 'Authorization']))));
    }
}
