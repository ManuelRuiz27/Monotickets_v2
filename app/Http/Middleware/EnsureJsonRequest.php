<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ensure incoming API requests are JSON and responses do not include cookies.
 */
class EnsureJsonRequest
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->acceptsJson($request)) {
            return response()->json([
                'error' => [
                    'code' => 'NOT_ACCEPTABLE',
                    'message' => 'Requests must accept JSON responses.',
                ],
            ], Response::HTTP_NOT_ACCEPTABLE);
        }

        if ($request->getContentLength() > 0 && ! $request->isJson()) {
            return response()->json([
                'error' => [
                    'code' => 'UNSUPPORTED_MEDIA_TYPE',
                    'message' => 'Requests with a body must be encoded as JSON.',
                ],
            ], Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        /** @var Response $response */
        $response = $next($request);

        if ($response instanceof JsonResponse) {
            $response->headers->set('Content-Type', 'application/json');
        }

        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie instanceof Cookie) {
                $response->headers->removeCookie(
                    $cookie->getName(),
                    $cookie->getPath(),
                    $cookie->getDomain()
                );
            }
        }

        if ($response->headers->has('Set-Cookie')) {
            $response->headers->remove('Set-Cookie');
        }

        return $response;
    }

    /**
     * Determine if the request accepts JSON responses.
     */
    private function acceptsJson(Request $request): bool
    {
        if ($request->expectsJson() || $request->isMethod('OPTIONS')) {
            return true;
        }

        $accept = $request->headers->get('Accept', '');

        return $accept === ''
            || trim($accept) === '*/*'
            || str_contains($accept, 'application/json');
    }
}
