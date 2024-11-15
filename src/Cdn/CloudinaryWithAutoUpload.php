<?php

namespace Detail\Laravel\Cdn;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use function assert;
use function count;
use function implode;
use function is_string;
use function parse_url;
use function pathinfo;
use function sprintf;
use function str_starts_with;
use function strlen;
use function substr;

class CloudinaryWithAutoUpload implements StreamCdn
{
    public function __construct(
        protected string $cloudName,
        protected string $autoFolder,
        protected string $autoUrlPrefix
    ) {
    }

    /**
     * @param array<string, string> $options
     */
    public static function create(array $options): CloudinaryWithAutoUpload
    {
        $validator = Validator::make($options, [
            'cloud_name' => 'required|min:2',
            'auto_folder' => 'required|min:2',
            'auto_url_prefix' => 'required|url',
        ]);

        if ($validator->fails()) {
            throw new RuntimeException('Invalid Clodinary-With-Auto-Upload options provided: ' . implode(' - ', $validator->errors()->all()));
        }

        return new self(
            $options['cloud_name'],
            $options['auto_folder'],
            $options['auto_url_prefix']
        );
    }

    public function initializeStream(string $id, string $name, string $sourceUrl): array
    {
        if (($uploadUrl = $this->getAutoUploadUrl($sourceUrl)) === null) {
            throw new RuntimeException('Invalid source url or does not matches auto upload prefix');
        }

        // Trigger auto upload
        try {
            $response = (new HttpClient())->request('GET', $uploadUrl);
        } catch (RequestException $requestException) {
            if ($requestException->hasResponse()){
                if ($requestException->getResponse()?->getStatusCode() == '400') {
                    $infoHeader = $requestException->getResponse()?->getHeader('x-cld-error') ?? [];

                    if (count($infoHeader) > 0) {
                        throw new RuntimeException('Auto upload returned error: ' . implode('; ', $infoHeader));
                    }
                }
            }

            throw new RuntimeException('Auto upload returned error: ' . $requestException->getMessage());
        }

        if ($response->getStatusCode() !== 200) { // Shold never come here
            throw new RuntimeException('Auto uplopload HTTP status code was ' . $response->getStatusCode());
        }

        return [
            'uid' => $id, // Using same as input for this CDN
            'thumbnail' => $this->getPreviewImageUrl($sourceUrl, '{{offset}}'),
            'playback' => [
                'hls' => $this->getStreamUrl($sourceUrl, extension: 'm3u8'),
                'dash' => $this->getStreamUrl($sourceUrl, extension: 'mpd'),
            ],
        ];
    }

    public function isProcessed(string $uid): bool
    {
        return true; // This is an auto updload CDN, therefore is always processed (if no error is raisen on init)
    }

    protected function getAutoUploadUrl(string $sourceUrl): ?string
    {
        $path = parse_url($sourceUrl, PHP_URL_PATH);

        if (!is_string($path) || !str_starts_with($sourceUrl, $this->autoUrlPrefix)) {
            return null;
        }

        return sprintf(
            'https://res.cloudinary.com/%s/video/upload/%s/%s',
            $this->cloudName,
            $this->autoFolder,
            substr($sourceUrl, strlen($this->autoUrlPrefix))
        );
    }

    /**
     * @param string[] $params
     */
    protected function getStreamUrl(string $sourceUrl, array $params = ['sp_auto'], ?string $extension = null): string
    {
        $path = parse_url($sourceUrl, PHP_URL_PATH);
        assert(is_string($path)); // Cant fail because we come here only after auto-upload has been performed

        $extensionLength = strlen(pathinfo($path, PATHINFO_EXTENSION));
        $path = substr(
            $sourceUrl,
            strlen($this->autoUrlPrefix),
            $extensionLength === 0 ? null : (-1 * ($extensionLength + 1))
        );

        return sprintf(
            'https://res.cloudinary.com/%s/video/upload/%s/%s/%s%s',
            $this->cloudName,
            implode('/', $params),
            $this->autoFolder,
            $path,
            $extension === null ? '' : ('.' . $extension)
        );
    }

    protected function getPreviewImageUrl(string $sourceUrl, float|string $offset = 0.0): string
    {
        //https://res.cloudinary.com/djdsgliqn/video/upload/so_5.0/assets/<....>.png
        return $this->getStreamUrl(
            $sourceUrl,
            [
                'so_' . (is_string($offset) ? $offset :  sprintf('%.1f', $offset)), // Start offset
            ],
            'png'
        );
    }
}
