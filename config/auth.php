<?php

use App\Models\User;

return [
    'defaults' => ['guard' => 'divan'],
    'guards' => ['divan' => ['driver' => 'divan-token', 'provider' => 'users']],
    'providers' => ['users' => ['driver' => 'eloquent', 'model' => User::class]],
];
