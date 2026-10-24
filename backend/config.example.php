<?php
// Copy to config.php outside the public dist/ directory. Never commit config.php.
return [
    'db_host' => '127.0.0.1',
    'db_port' => 3306,
    'db_name' => 'rimbo_notes',
    'db_user' => 'rimbo_app',
    'db_password' => 'REPLACE_WITH_YOUR_DATABASE_PASSWORD',
    'secure_cookies' => true, // false ONLY for http://localhost development
    'registration_open' => true,
];
