<?php

declare(strict_types=1);

namespace Plugins\Monitors\Domain;

enum Severity: string
{
    case Info     = 'info';
    case Warning  = 'warning';
    case Critical = 'critical';
}
