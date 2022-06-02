<?php

namespace Detail\Laravel\Cdn;

interface CdnInterface
{
    /**
     * @param array<string, string> $options
     */
    public static function create(array $options): CdnInterface;

    public function buildImageUrl(Asset $asset): ?string;

    public function purgeImage(Asset $asset): bool;
}
