<?php

namespace App\Support\Licensing;

use RuntimeException;

/**
 * A licence file failed a check. The code (E_*) and message come from
 * LICENSE_FORMAT.md; neither ever contains key material or payload content.
 */
class LicenseException extends RuntimeException
{
    public const FORMAT = 'E_FORMAT';
    public const MAGIC = 'E_MAGIC';
    public const VERSION = 'E_VERSION';
    public const KEY_ID = 'E_KEY_ID';
    public const DECRYPT = 'E_DECRYPT';
    public const SIGNATURE = 'E_SIGNATURE';
    public const PAYLOAD = 'E_PAYLOAD';
    public const PRODUCT = 'E_PRODUCT';
    public const ISSUED_FUTURE = 'E_ISSUED_FUTURE';
    public const ACTIVATION_WINDOW = 'E_ACTIVATION_WINDOW';
    public const EXPIRED = 'E_EXPIRED';
    public const MIN_VERSION = 'E_MIN_VERSION';
    public const INSTALL_ID = 'E_INSTALL_ID';
    public const CLOCK = 'E_CLOCK';
    public const RECORD = 'E_RECORD';

    public const MESSAGES = [
        self::FORMAT => 'This is not a valid AtGlance licence file.',
        self::MAGIC => 'This is not an AtGlance licence file (.atgl).',
        self::VERSION => 'This licence file was made for a newer AtGlance version. Update the console.',
        self::KEY_ID => 'This licence file was signed with a key this console does not know. Update the console, or generate a new file.',
        self::DECRYPT => 'The licence file is damaged or was changed. Download it again.',
        self::SIGNATURE => 'The licence file signature is not valid.',
        self::PAYLOAD => 'The licence file content is not valid.',
        self::PRODUCT => 'This licence is not for the AtGlance Management Console.',
        self::ISSUED_FUTURE => 'The licence file is dated in the future. Check this server\'s date and time.',
        self::ACTIVATION_WINDOW => 'This license file has expired. Licence files must be installed within 3 hours of download. Please generate a new one at atglance.live.',
        self::EXPIRED => 'This licence has expired. Renew it at atglance.live.',
        self::MIN_VERSION => 'This licence needs a newer console version. Update the console.',
        self::INSTALL_ID => 'This licence file was generated for another installation.',
        self::CLOCK => 'This server\'s clock is behind the time it last recorded. Correct the date and time, then try again.',
        self::RECORD => 'The stored licence was changed outside AtGlance. Upload the licence file again.',
    ];

    public function __construct(public readonly string $errorCode)
    {
        parent::__construct(self::MESSAGES[$errorCode] ?? self::MESSAGES[self::FORMAT]);
    }

    /** "E_EXPIRED: This licence has expired. ..." for user-facing errors. */
    public function display(): string
    {
        return $this->errorCode . ': ' . $this->getMessage();
    }
}
