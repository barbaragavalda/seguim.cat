<?php

// Google Sign-In OAuth client IDs (console.cloud.google.com > Google Auth
// Platform > Clients). Not secrets - these are the public identifiers
// Google issues per platform, matched against package name/bundle ID/SHA-1
// (Android/iOS) or authorized JS origins (Web) at Google's end, not a
// shared secret this app itself needs to protect. Same in dev and prod
// (the client, not this app, differs per environment), hence no dev/prod
// split - see Api\Controller\Login\Google.
$config = array(
    'google' => array(
        'client_ids' => array(
            'web'     => '896234443669-p5nedti40vlbclkh3973gdf3in5pjskj.apps.googleusercontent.com',
            'ios'     => '896234443669-2nv573a07nf4b702pg5evmbdu4bqabe8.apps.googleusercontent.com',
            'android' => '896234443669-pk7d0irr2vraodoki5e6km2cqgc1b8qs.apps.googleusercontent.com',
        ),
    ),
);
