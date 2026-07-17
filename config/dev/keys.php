<?php

// Copy this file to keys.php (gitignored) and fill in a real value.
// Generate with: php -r "echo bin2hex(random_bytes(32));"
// See Core\Model\Encryptor\Secret for how this is used (derives per-purpose
// subkeys for TwoWay/OneWay/BlindIndex via HKDF).
$config = array(

    'encryption' => array(
        'secret' => '81c5ab1fc666350b5acb49947915ccf534f275ac2308c9b0a071425922092385'
    )

);
