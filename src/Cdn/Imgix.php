<?php

namespace Detail\Laravel\Cdn;

use GuzzleHttp\Client as HttpClient;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Throwable;
use function implode;
use function ltrim;
use function parse_url;
use function rtrim;

class Imgix implements ImageCdn
{
    protected HttpClient $httpClient;

    public function __construct(
        protected string $apiKey,
        protected string $baseUrl
    ) {
    }

    /**
     * @param array<string, string> $options
     */
    public static function create(array $options): Imgix
    {
        $validator = Validator::make($options, [
            'api_key' => 'required|min:20,max:128', // Normally 67 chars
            'base_url' => 'required|url',
        ]);

        if ($validator->fails()) {
            throw new RuntimeException('Invalid ImgIX options provided: ' . implode(' - ', $validator->errors()->all()));
        }

        return new self(
            $options['api_key'],
            $options['base_url'],
        );
    }

    public function purgeImage(ImageAsset $asset): bool
    {
        $url = $this->buildImageUrl($asset);

        try {
            $response = $this->getHttpClient()->post(
                'https://api.imgix.com/api/v1/purge',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->getApiKey(),
                        'Accept' => 'application/vnd.api+json',
                        'Content-Type' => 'application/vnd.api+json',
                    ],
                    'json' => [
                        'data' => [
                            'type' => 'purges',
                            'attributes' => ['url' => $url],
                        ],
                    ],
                ]
            );
//        } catch (HttpException\BadResponseException $e) { // Server (5xx) and client exceptions (4xx)
//            $response = $e->getResponse();
        } catch (Throwable $e) { // All other exceptions (state, request, client, tooManyRedirects, ...)
            throw new RuntimeException(
                sprintf('Could not purge image "%s": %s', $url, $e->getMessage()),
                0,
                $e
            );
        }

        // There is no way to check if something has been really purged:
        //  - We have a "Bad Request" (400) when the base URL is not correct or owned by that account.
        //    (message: "Purge URL is either invalid or not owned by your account.")
        //  - Otherwise the response is always "OK" (200), also if the URL path is wrong (pointing to a nonexistent image)

        // Only return image URL if return code was a 2xx
        return $response->getStatusCode() >= 200 && $response->getStatusCode() < 300;
    }

    public function buildImageUrl(ImageAsset $asset): ?string
    {
        $previewUrl = $asset->getPreviewUrl(false);

        if ($previewUrl === null) {
            return null;
        }

        return sprintf(
            '%s/%s',
            rtrim($this->baseUrl, '/'),
            ltrim(parse_url($previewUrl, PHP_URL_PATH) ?: '', '/')
        );
    }

    public function getHttpClient(): HttpClient
    {
        if (!isset($this->httpClient)) {
            $this->httpClient = new HttpClient();
        }

        return $this->httpClient;
    }

    public function getApiKey(): string
    {
        return $this->apiKey;
    }
}
