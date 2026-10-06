<?php

namespace App\Services\Sso;

use RuntimeException;

/** A sign-in that must stop; the message is shown to the user on the login page. */
class SsoException extends RuntimeException
{
}
