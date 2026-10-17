<?php

namespace App\Services;

use RuntimeException;

/**
 * A refused service account or key operation. The message is safe to show to the actor.
 */
class ServiceAccountException extends RuntimeException
{
}
