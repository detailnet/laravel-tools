<?php

namespace Detail\Laravel\Cdn;

use RuntimeException;

/**
 * Using stram data based on video status of Clodflare
 * ref: https://developers.cloudflare.com/stream/uploading-videos/upload-via-link/#check-video-status
 *
 * @phpstan-type StreamData array{uid: string, thumbnail: string, playback: array{hls: string, dash:string}}
 */
interface StreamCdn extends CdnInterface
{
    /**
     * @return StreamData
     * @throws RuntimeException
     */
    public function initializeStream(string $id, string $fileName, string $sourceUrl): array;

    /**
     * @throws RuntimeException
     */
    public function isProcessed(string $uid): bool;
}
