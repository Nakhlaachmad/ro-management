<?php

declare(strict_types=1);

namespace App\Domain\FP;

/** A persistence failure is not proof that COMMIT did not occur. Never delete its uploads. */
final class FpPersistenceException extends \RuntimeException {}
