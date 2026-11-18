<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Assistant;

use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\MaxTokens;

#[MaxTokens(4096)]
#[MaxSteps(6)]
final class StudioAgent extends AnonymousAgent {}
