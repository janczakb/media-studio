<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Intelligence;

use UseFlex\MediaCore\Intelligence\AnalysisResult;
use UseFlex\MediaCore\Intelligence\ImageAnalyzer;

final class RekognitionAnalyzer implements ImageAnalyzer
{
    public function analyze(string $bytes, string $mime, array $profile): AnalysisResult
    {
        $class = 'Aws\\Rekognition\\RekognitionClient';
        if (! class_exists($class)) {
            throw new \LogicException('Install the optional AWS SDK to use Rekognition.');
        }
        // Standard AWS credential chain; keys are not serialized in jobs or stored in results.
        $client = new $class(['version' => '2016-06-27', 'region' => $profile['region'], 'retries' => 0, 'http' => ['connect_timeout' => 3, 'timeout' => 30]]);
        $data = $client->detectLabels(['Image' => ['Bytes' => $bytes], 'MaxLabels' => $profile['max_labels'], 'Features' => ['GENERAL_LABELS']]);
        $labels = [];
        foreach ($data['Labels'] ?? [] as $label) {
            if (! isset($label['Name'], $label['Confidence']) || ! is_numeric($label['Confidence'])) {
                throw new \UnexpectedValueException('Invalid Rekognition label.');
            }
            $labels[] = ['name' => $label['Name'], 'score' => $label['Confidence'] / 100];
        }

        return new AnalysisResult($labels);
    }
}
