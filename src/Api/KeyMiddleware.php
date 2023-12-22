<?php

namespace Detail\Laravel\Api;

use Closure;
use Detail\Laravel\Http\Traits\ErrorResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use function is_string;
use function request;

class KeyMiddleware
{
    use ErrorResponse;

    protected const HEADER_APP_KEY = 'AppKey';

    protected const CACHE_KEY = 'api_user'; // Set null to disable cache

    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = $request->header(static::HEADER_APP_KEY);

        if (!is_string($apiKey)) {
            return $this->errorResponse('Unauthorized', Response::HTTP_UNAUTHORIZED);
        }

        $cacheMatch = false;
        $apiUser = null;

        if (is_string(static::CACHE_KEY)) {
            $apiUser = Cache::store('api_user')->get($apiKey);
            $cacheMatch = $apiUser !== null;
        }

        if (!$cacheMatch) {
            $apiUser = UserModel::query()->where(['active' => true, 'key' => $apiKey])->sole();
        }

        if (!$apiUser instanceof UserModel
            || !$apiUser->allowsResource($request->method(), $request->path())
        ) {
            return $this->errorResponse('Unauthorized', Response::HTTP_UNAUTHORIZED);
        }

        if (is_string(static::CACHE_KEY) && !$cacheMatch) { // Do not refresh cache if previously taken from there
            Cache::store('api_user')->set($apiKey, $apiUser);
        }

        return $next($request);
    }
}
