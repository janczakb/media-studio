<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Intelligence;

use UseFlex\MediaCore\Intelligence\AnalysisResult;
use UseFlex\MediaCore\Intelligence\ImageAnalyzer;

final class GoogleVisionAnalyzer implements ImageAnalyzer
{
    public function analyze(string $bytes, string $mime, array $profile): AnalysisResult
    {
        $data = app(ProviderHttp::class)->post('https://vision.googleapis.com/v1/images:annotate', ['X-Goog-Api-Key' => $profile['key']], ['requests' => [[
            'image' => ['content' => base64_encode($bytes)], 'features' => [['type' => 'LABEL_DETECTION', 'maxResults' => $profile['max_labels']]],
        ]]]);
        $response = $data['responses'][0] ?? null;
        if (! is_array($response) || isset($response['error']) || ! is_array($response['labelAnnotations'] ?? null)) {
            throw new \UnexpectedValueException('Invalid Vision label response.');
        }
        $labels = [];
        foreach ($response['labelAnnotations'] as $label) {
            if (! is_array($label) || ! isset($label['description'], $label['score'])) {
                throw new \UnexpectedValueException('Invalid Vision label.');
            }
            $labels[] = ['name' => $label['description'], 'score' => $label['score']];
        }

        return new AnalysisResult($labels);
    }
}
