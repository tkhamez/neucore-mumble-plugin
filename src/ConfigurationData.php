<?php

declare(strict_types=1);

namespace Neucore\Plugin\Mumble;
class ConfigurationData
{
    public function __construct(
        public array $groupsToTags,
    ) {
    }
}
