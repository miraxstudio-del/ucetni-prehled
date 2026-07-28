<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Systémový datový klíč zabalený hlavním klíčem z .env.
 * Bez hlavního klíče je hodnota bezcenná.
 */
class EncryptionKey extends Model
{
    protected $fillable = ['name', 'wrapped_key'];

    protected $hidden = ['wrapped_key'];
}
