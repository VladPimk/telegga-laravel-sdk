<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class CustomUser extends Model
{
    protected $table = 'custom_users';

    protected $guarded = [];
}
