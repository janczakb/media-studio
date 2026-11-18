<?php

declare(strict_types=1);

namespace UseFlex\MediaCore\Encryption;

use RuntimeException;

/** Authentication failures are never represented as an empty stream or EOF. */
final class EncryptionFailure extends RuntimeException {}
