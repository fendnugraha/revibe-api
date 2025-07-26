<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Contact extends Model
{
    protected $guarded = ['id'];

    public static function boot()
    {
        parent::boot();

        static::deleting(function ($contact) {
            if ($contact->id === 1) {
                throw new \Exception("Default contact cannot be deleted.");
            }
        });
    }


    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function finances()
    {
        return $this->hasMany(Finance::class);
    }
}
