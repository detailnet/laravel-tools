<?php

namespace Detail\Laravel\Drive;

use DateTime;
use DateTimeInterface;
use Defuse\Crypto\Crypto;
use Defuse\Crypto\Key;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\AwsS3V3\PortableVisibilityConverter;
use League\Flysystem\FileAttributes;
use League\Flysystem\Visibility;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use function array_merge;
use function assert;
use function env;
use function get_class;
use function hash_final;
use function hash_init;
use function hash_update_stream;
use function is_resource;
use function json_encode;
use function preg_replace;
use function sprintf;
use function strlen;
use function strtoupper;
use function trim;

class Drive
{
    /** @var array<string, string> */
    private array $options;
    private Key $processorKey;
    private Filesystem $filesystem;

    /**
     * @param array<string, string> $options
     */
    public function __construct(array $options = [])
    {
        if ($options['driver'] === 's3') {
            // Set defaults using env variables that normally are set for Laravel 8 in config/filesystems.php
            $options = array_merge(
                [
                    'key' => env('AWS_ACCESS_KEY_ID'),
                    'secret' => env('AWS_SECRET_ACCESS_KEY'),
                    'region' => env('AWS_DEFAULT_REGION'),
                    'bucket' => env('AWS_BUCKET'),
                    'url' => env('AWS_URL'),
                    'endpoint' => env('AWS_ENDPOINT'),
                    'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
                    'root' => env('S3_' . strtoupper($options['id']) . '_PREFIX') ?? '/',
                ],
                $options
            );
        }

        $this->options = $options;
        $this->filesystem = Storage::build($options);
    }

    public function getFilesystem(): Filesystem
    {
        return $this->filesystem;
    }

    public function getConfiguration(): string
    {
        $string = json_encode($this->options);

        if ($string === false) {
            throw new RuntimeException('Invalid options provided');
        }

        return Crypto::encrypt($string, $this->getKey());
    }

    private function getKey(): Key
    {
        if (!isset($this->processorKey)) {
            $this->processorKey = Key::loadFromAsciiSafeString(env('PROCESSOR_KEY'));
        }

        return $this->processorKey;
    }

    public function getAttributes(string $path): FileAttributes
    {
        if ($this->filesystem instanceof AwsS3V3Adapter) {
            return $this->filesystem->getAdapter()->fileSize($path); // Could use any other return ing FileAttributes
        }

        throw new RuntimeException(
            sprintf(
                'Unsupported filesystem "%s" on "%s"',
                get_class($this->filesystem),
                __FUNCTION__
            )
        );
    }

    public function extractHash(FileAttributes $attributes): string
    {
        $meta = $attributes->extraMetadata();
        $etag = trim($meta['ETag'] ?? $meta['etag'] ?? '', '"');

        if (strlen($etag) === 32) { // $etag !== '' && !str_contains($etag, '-')
            return $etag;
        }

        // If the etag contains a dash, is because the upload was performed as multipart
        // Ref:https://stackoverflow.com/questions/12186993/what-is-the-algorithm-to-compute-the-amazon-s3-etag-for-a-file-larger-than-5gb

        $hashContext = hash_init('md5');
        $stream = $this->getFilesystem()->readStream($attributes->path());

        assert(is_resource($stream));
        hash_update_stream($hashContext, $stream);

        return hash_final($hashContext);
    }

    public function getUrl(string $path): string
    {
        if ($this->filesystem instanceof AwsS3V3Adapter) {
            return $this->filesystem->getClient()->getObjectUrl(
                $this->filesystem->getConfig()['bucket'],
                $this->filesystem->path($path)
            );
        }

        throw new RuntimeException(
            sprintf(
                'Unsupported filesystem "%s" on "%s"',
                get_class($this->filesystem),
                __FUNCTION__
            )
        );
    }

    public function getUploadUrl(string $path, DateTimeInterface $expire): string
    {
        /**
         * @todo Deprecate $this->getSignedUrl and use $this->filesystem->temporaryUploadUrl($path, $expire)
         *       This should also return the needed headers for $this->getUploadData
         */
        return $this->getSignedUrl('upload', $path, $expire);
    }

    public function getDownloadUrl(string $path, DateTimeInterface $expire): string
    {
        /**
         * @todo Deprecate $this->getSignedUrl and use $this->filesystem->temporaryUrl($path, $expire)
         */
        return $this->getSignedUrl('download', $path, $expire);
    }

    public function getSignedUrl(string $type, string $path, DateTimeInterface $expire): string
    {
        /**
         * @todo Deprecate this method in favor of
         *        - for upload: $this->filesystem->temporaryUploadUrl($path, $expire);
         *        - for download: $this->filesystem->temporaryUrl($path, $expire);
         */

        if ($this->filesystem instanceof AwsS3V3Adapter) {
            $visibilityConverter = new PortableVisibilityConverter();
            $client = $this->filesystem->getClient();
            $args = [
                'Bucket' => $this->filesystem->getConfig()['bucket'],
                'Key' => $this->filesystem->path($path),
            ];

            if ($type === 'upload') {
                $command = 'PutObject';
                $args['ACL'] = $visibilityConverter->visibilityToAcl($this->getVisibility());

                if ($this->getEncryption() !== null) {
                    $args['ServerSideEncryption'] = $this->getEncryption();
                }
            } else {
                $command = 'GetObject';
                $args['ResponseContentDisposition'] = 'attachment';
            }

            return (string)$client->createPresignedRequest($client->getCommand($command, $args), $expire)->getUri();
        }

        throw new RuntimeException(
            sprintf(
                'Unsupported filesystem "%s" on "%s"',
                get_class($this->filesystem),
                __FUNCTION__
            )
        );
    }

    public function getVisibility(): string
    {
        return $this->options['visibility'] ?? Visibility::PUBLIC;
    }

    public function getEncryption(): ?string
    {
        return $this->options['encryption'] ?? null;
    }

    /**
     * @return array{id: string, name: string, headers: array<string, string>, upload_url: string, expires_on: string}
     */
    public function getUploadData(string $file, DateTimeInterface|string|null $expire = null): array
    {
        $id = Uuid::uuid4()->toString();
        $file = $this->sanitizeFilename($file);
        $expire = $expire ?? $this->options['expire'] ?? '+10 minutes';

        if (!$expire instanceof DateTimeInterface) {
            $expire = new DateTime($expire);
        }

        $headers = [];

        if ($this->filesystem instanceof AwsS3V3Adapter && $this->getEncryption() !== null) {
            $headers['x-amz-server-side-encryption'] = $this->getEncryption();
        }

        return [
            'id' => $id,
            'name' => $file,
            'headers' => $headers,
            'upload_url' => $this->getUploadUrl(sprintf('%s/%s', $id, $file), $expire),
            'expires_on' => $expire->format(DATE_ATOM),
        ];
    }

    public function deleteDir(string $id): void
    {
        $this->filesystem->getDriver()->deleteDirectory($id);
    }

    public function copyFile(string $sourcePath, string $destinationPath): void
    {
        $driver = $this->filesystem->getDriver();

        if ($driver->has($destinationPath)) {
            $driver->delete($destinationPath);
        }

        $driver->copy($sourcePath, $destinationPath);
    }

    private function sanitizeFilename(string $filename): string
    {
        // Remove unprintable characters and invalid unicode characters (ref: \League\Flysystem\Util::removeFunkyWhiteSpace)
        return preg_replace('#\p{C}+#u', '', $filename) ?? '';
    }
}
