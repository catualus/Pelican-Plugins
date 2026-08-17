<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Secret detection
    |--------------------------------------------------------------------------
    |
    | .env keys containing any of these start hidden behind a reveal toggle.
    |
    | This is cover against someone reading over your shoulder or a screenshot,
    | not a security boundary: anyone who can open this page already has file.read
    | and can open the raw file in the file manager.
    |
    */

    'secret_patterns' => [
        'TOKEN', 'SECRET', 'PASSWORD', 'PASSWD', 'KEY', 'AUTH', 'CREDENTIAL',
        'PRIVATE', 'DSN', 'WEBHOOK', 'SALT', 'SIGNATURE', 'ACCESS',
    ],

    /*
    |--------------------------------------------------------------------------
    | Python requirements files
    |--------------------------------------------------------------------------
    |
    | Checked in order; the first that exists is shown. package.json always wins
    | when present, since a Node app cannot also be a Python one.
    |
    */

    'requirements_files' => ['requirements.txt', 'requirements-prod.txt', 'pyproject.toml'],

    /*
    |--------------------------------------------------------------------------
    | Caching and limits
    |--------------------------------------------------------------------------
    */

    'cache_ttl' => 30,

    'max_read_bytes' => 2097152,

];
