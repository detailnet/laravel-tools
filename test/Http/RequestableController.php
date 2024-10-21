<?php

namespace DetailTest\Laravel\Http;

use Detail\Laravel\Http\Controller;
use Detail\Laravel\Http\Traits\Requestable;
use Illuminate\Http\JsonResponse;

/**
 * This controller exists only to let phpstan analyse the Requestable trait.
 */
class RequestableController extends Controller
{
    use Requestable;

    public function logout(): JsonResponse
    {
        return $this->APIServiceRequest('POST', $this->APIURL('users') . '/logout');
    }
}

