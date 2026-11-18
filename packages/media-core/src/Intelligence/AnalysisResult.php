<?php

declare(strict_types=1);

namespace UseFlex\MediaCore\Intelligence;

/** Separate AI concepts, never human/Spatie tags. Scores retain provider semantics. */
final readonly class AnalysisResult
{
    public array $labels;

    public function __construct(array $labels, public string $description = '')
    {
        if (count($labels) > 64 || mb_strlen($description) > 2000) {
            throw new \UnexpectedValueException('Analysis exceeds output limits.');
        }
        $normalized = [];
        foreach ($labels as $label) {
            $name = $label['name'] ?? null;
            $score = $label['score'] ?? null;
            if (! is_string($name) || trim($name) === '' || mb_strlen($name) > 120 || preg_match('/[\x00-\x1F\x7F]/u', $name) || ($score !== null && (! is_numeric($score) || ! is_finite((float) $score) || $score < 0 || $score > 1))) {
                throw new \UnexpectedValueException('Invalid analysis concept.');
            }
            $name = trim($name);
            $key = mb_strtolower($name, 'UTF-8');
            $normalized[$key] = ['name' => $name, 'score' => $score === null ? null : (float) $score];
        }
        $this->labels = array_values($normalized);
    }
}
