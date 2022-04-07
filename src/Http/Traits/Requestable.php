<?php

namespace Detail\Laravel\Http\Traits;

use GuzzleHttp\Client;
use Illuminate\Http\JsonResponse;
use function env;

trait Requestable
{
    public function APIURL(string $group): string
    {
        $host = config('gateway.' . $group . '.' . env('APP_SERVICE_MODE') . '_service_host');
        $root = config('gateway.' . $group . '.service_root_url');
        $mode = config('gateway.' . $group . '.' . env('APP_SERVICE_MODE') . '_version');

        // Example of versioned API Service:
        // $url = config('gateway.base_protocol') . $host . '/api/v' . $mode . '/' . $root;

        // Unversioned API routes as used in the previous laminas application (e.g. lw-inside)
        $url = config('gateway.base_protocol') . $host . '/api/' . $root;

        return $url;
    }

    public function APIServiceRequest(string $method, string $url): JsonResponse
    {

        $client = new Client(
            [
                'verify' => (env('APP_SERVICE_MODE', 'PRODUCTION') == 'PRODUCTION'),
                'headers' =>
                    [
                        'GS-TOKEN' => config('gateway.service_token'),
                        'Authorization' => 'Bearer ' . request()->bearerToken(),
                        'UID' => request()->header('UID', ''),
                        'App-ID' => env('API_APP_ID'),
                        'App-Key' => env('API_APP_KEY'),
                    ],
                'json' => request()->all(),
                'http_errors' => false,
            ]);

        $response = $client->request($method, $url);
        $string = $response->getBody();
        $data = json_decode($string);

        return response()->json($data, $response->getStatusCode());
    }
}
