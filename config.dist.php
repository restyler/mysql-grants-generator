<?php

// MySQL connection info to inspect metadata. User can be read-only.
const DB_HOST = '127.0.0.1';
const DB_NAME = 'information_schema'; // DB_NAME to avoid PDO wrapper exception during startup.
const DB_USER = 'dbuser';
const DB_PASS = 'dbpass';

return [
    // main config

    // mysql format of user is single quoted username + '@' + single quoted host
    'user_to_restrict' => "'metabase'@'localhost'",
    // databases which get GRANT. Whitelist approach is used here.
    'databases_to_allow' => ['q3-dev5', 'q3-eu'],
    // nested 3 level assoc array with db => table => column structure. Blacklist approach is used here.
    // So basically   'dbname' => ['user' => ['phone']] config will generate GRANTS SELECT, SHOW VIEW(firstname, lastname, ... all non-blacklisted columns) ON dbname.user  grant.);
    'tables_to_protect' => [
        'q3-dev5' => [ // database name
            'user' => [
                'phone', 'email', 'authKey', 'passwordHash', 'passwordResetToken', 'authToken', 'authenticatorSecret'
            ],
            'secret_table' => true, // pass true instead of columns to forbid access to whole table
            'address' => [
                'phone', 'email'
            ]
        ]
    ]
];

