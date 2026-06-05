<?php

declare(strict_types=1);

namespace RadioSaaS\Domain;

enum RenderPlacement: string
{
    case PreRoll = 'pre_roll';
    case PostRoll = 'post_roll';
}
