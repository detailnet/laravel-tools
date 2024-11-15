<?php

namespace Detail\Laravel\Cdn;

interface ImageCdn extends CdnInterface
{
    public function buildImageUrl(ImageAsset $asset): ?string;

    public function purgeImage(ImageAsset $asset): bool;
}
