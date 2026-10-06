<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    protected $table = 'tbl_user';

    protected $primaryKey = 'ID';

    public $timestamps = false;

    protected $fillable = ['Username', 'Email'];

    protected $hidden = ['Password'];

    public function getAuthPasswordName(): string
    {
        return 'Password';
    }
}
