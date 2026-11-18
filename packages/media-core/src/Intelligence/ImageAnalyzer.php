<?php

declare(strict_types=1);

namespace UseFlex\MediaCore\Intelligence;

interface ImageAnalyzer
{
    /** Receives only an already bounded JPEG/PNG derivative, never a public original URL. */
    public function analyze(string $bytes, string $mime, array $profile): AnalysisResult;
}
