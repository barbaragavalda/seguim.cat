<?php

$config = array(

    'wallaby' => array(
        'app'       => 'Appacman',
        'folders'   => array('freimguork-appacman'),
        'languages' => array('ca')
    ),

    'api' => array(
        'app'        => 'Api',
        'folders'    => array('api'),
        'languages'  => array('ca', 'es', 'en'),
        'vendorApps' => array('Webservice')
    ),

    '{lang}' => array(
        'app'       => 'Web',
        'folders'   => array('web'),
        'languages' => array('ca', 'es', 'en')
    )

);
