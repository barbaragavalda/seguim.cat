<?php

// DIR_ROOT and IS_DEV are always defined by public/index.php before any
// app code runs - declared here purely so PHPStan knows they exist, this
// file is never included at runtime.
define('DIR_ROOT', __DIR__ . '/');
define('IS_DEV', true);
