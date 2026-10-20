<?php

return [

    /*
    | First Super Admin, created by `php artisan db:seed --class=SuperAdminSeeder`.
    | Values live only in backend/.env.
    */
    'super_admin' => [
        'name' => env('SUPER_ADMIN_NAME', 'Super Admin'),
        'phone' => env('SUPER_ADMIN_PHONE'),
        'email' => env('SUPER_ADMIN_EMAIL'),
        'password' => env('SUPER_ADMIN_PASSWORD'),
    ],

];
