<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Http;

interface ApiBaseUrlResolver
{
    public function resolve(): string;
}
