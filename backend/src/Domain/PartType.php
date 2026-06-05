<?php

declare(strict_types=1);

namespace RadioSaaS\Domain;

enum PartType: string
{
    case News = 'news';
    case Sports = 'sports';
    case Economy = 'economy';
    case Weather = 'weather';
    case Road = 'road';
}
