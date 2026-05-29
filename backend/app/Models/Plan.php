<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

class Plan extends Model
{
    use CentralConnection, HasFactory;

    protected $fillable = ['name', 'price_monthly', 'features'];

    protected $casts = ['features' => 'array'];
}
