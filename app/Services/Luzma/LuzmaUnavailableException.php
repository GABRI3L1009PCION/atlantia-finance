<?php

declare(strict_types=1);

namespace App\Services\Luzma;

use RuntimeException;

/** The local model (gateway or Ollama) did not answer in time or answered with an error. */
class LuzmaUnavailableException extends RuntimeException {}
