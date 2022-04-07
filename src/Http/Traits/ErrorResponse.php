<?php

namespace Detail\Laravel\Http\Traits;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

trait ErrorResponse
{
    public function errorResponse(Throwable|Validator|string $message, int $status = Response::HTTP_BAD_REQUEST): JsonResponse
    {
        $details = [];

        if ($message instanceof Validator) {
            $details = $message->errors()->all();
            $message = 'Validation failed';
        }

        if ($message instanceof Throwable) {
            $details = $message->getTrace(); // @todo Enable when on development
            $message = $message->getMessage();
        }

        return response()->json(
            [
                'code' => $status,
                'error' => $message,
                'details' => $details,
            ],
            $status
        );
    }
}
