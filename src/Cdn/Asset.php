<?php

namespace Detail\Laravel\Cdn;

interface Asset
{
    public function getPreviewUrl(
        bool $appendVersion = true, // Append version information to the url (might be used as cache breaker)
        string $versionParam = 'version' // Name of the version parameter
    ): ?string;
}
