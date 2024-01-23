<?php

namespace Detail\Laravel\Api;

use Closure;
use Detail\Laravel\Http\Traits\ErrorResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use function is_string;

class KeyMiddleware
{
    use ErrorResponse;

    public function __construct(
        private ?string $cacheKey = null, // Set null to disable cache
        private int $cacheTtl = 60,
        private string $headerAppKey = 'AppKey'
    )
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = $request->header($this->headerAppKey);

        if (!is_string($apiKey)) {
            return $this->errorResponse('Unauthorized', Response::HTTP_UNAUTHORIZED);
        }

        $cacheMatch = false;
        $apiUser = null;

        if (is_string($this->cacheKey)) {
            $apiUser = Cache::store($this->cacheKey)->get($apiKey);
            $cacheMatch = $apiUser !== null;
        }

        if (!$cacheMatch) {
            $apiUser = UserModel::query()->where(['active' => true, 'key' => $apiKey])->first();
        }

        if (!$apiUser instanceof UserModel) {
            return $this->errorResponse('Unauthorized', Response::HTTP_UNAUTHORIZED);
        }

        if (is_string($this->cacheKey) && !$cacheMatch) { // Do not refresh cache if previously taken from there
            Cache::store($this->cacheKey)->set($apiKey, $apiUser, $this->cacheTtl);
        }

        if (!$apiUser->allowsResource($request->method(), $request->path())) {
            return $this->errorResponse('Unauthorized', Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }
}
