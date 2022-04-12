<?php

namespace Detail\Laravel\Drive;

use DateTimeInterface;
use Defuse\Crypto\Crypto;
use Defuse\Crypto\Key;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FileAttributes;
use RuntimeException;
use function array_merge;
use function env;
use function get_class;
use function sprintf;
use function strtoupper;
use function trim;

class Drive
{
    private array $options;
    private Key $processorKey;
    private Filesystem $filesystem;

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
        $adapter = $this->filesystem->getAdapter();

        if ($adapter instanceof AwsS3V3Adapter) {
            return $adapter->getAdapter()->fileSize($path); // Could use any other return ing FileAttributes
        }

        throw new RuntimeException(
            sprintf(
                'Unsupported filesystem adapter "%s" on "%s"',
                get_class($adapter),
                __FUNCTION__
            )
        );
    }

    public function extractHash(FileAttributes $attributes): string
    {
        return trim($attributes->extraMetadata()['etag'] ?? '', '"');
    }

    public function getUrl(string $path): string
    {
        $adapter = $this->filesystem->getAdapter();

        if ($adapter instanceof AwsS3V3Adapter) {
            return $adapter->getClient()->getObjectUrl(
                $adapter->getConfig()['bucket'],
                $adapter->path($path)
            );
        }

        throw new RuntimeException(
            sprintf(
                'Unsupported filesystem adapter "%s" on "%s"',
                get_class($adapter),
                __FUNCTION__
            )
        );
    }

    public function getUploadUrl(string $path, DateTimeInterface $expire): string
    {
        return $this->getSignedUrl('upload', $path, $expire);
    }

    public function getSignedUrl(string $type, string $path, DateTimeInterface $expire): string
    {
        $adapter = $this->filesystem->getAdapter();

        if ($adapter instanceof AwsS3V3Adapter) {
            $client = $adapter->getClient();
            $command = $client->getCommand(
                $type === 'upload' ? 'PutObject' : 'GetObject',
                [
                    'Bucket' => $adapter->getConfig()['bucket'],
                    'Key' => $adapter->path($path),
                    'ACL' => 'public-read',
                    'ResponseContentDisposition' => 'attachment'
                    /** @todo Make configurable */
                ]
            );

            $request = $client->createPresignedRequest($command, $expire);

            return (string) $request->getUri();
        }

        throw new RuntimeException(
            sprintf(
                'Unsupported filesystem adapter "%s" on "%s"',
                get_class($adapter),
                __FUNCTION__
            )
        );
    }

    public function getDownloadUrl(string $path, DateTimeInterface $expire): string
    {
        return $this->getSignedUrl('download', $path, $expire);
    }

    public function deleteDir(string $id): void
    {
        $this->filesystem->getDriver()->deleteDirectory($id);
    }

    public function copyFile(string $sourcePath, string $destinationPath): void
    {
        $filesystem = $this->filesystem->getDriver();

        if ($filesystem->has($destinationPath)) {
            $filesystem->delete($destinationPath);
        }

        $filesystem->copy($sourcePath, $destinationPath);
    }
}
