<?php

// note: expand this to use full names, aswell as address, phone, etc.
// also when logged in save the fdavourites and cart to the user data.


return [
    [
        'id' => 1,
        'name' => 'Mayie',
        'email' => 'mayie@example.com',
        'password_hash' => password_hash('password123', PASSWORD_DEFAULT),
        'role' => 'admin' 
    ],
    [
        'id' => 2,
        'name' => 'Jeffe',
        'email' => 'jeffe@example.com',
        'password_hash' => password_hash('admin', PASSWORD_DEFAULT),
        'role' => 'customer'
    ],
    [
        'id' => 3,
        'name' => 'SysAdmin',
        'email' => 'super@example.com',
        'password_hash' => password_hash('superpass', PASSWORD_DEFAULT),
        'role' => 'superadmin' 
    ]
];