<?php

namespace Detail\Laravel\Cdn;

interface CdnInterface
{
    public static function create(array $options): CdnInterface;

    public function buildImageUrl(Asset $asset): ?string;

    public function purgeImage(Asset $asset): bool;
}
