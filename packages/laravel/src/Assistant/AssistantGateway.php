<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Assistant;

interface AssistantGateway
{
    /** Yields normalized text deltas and final usage, never provider secrets or reasoning. */
    public function stream(array $model, array $messages, string $prompt, array $context): iterable;
}
