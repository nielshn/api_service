<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Web extends Model
{
    use SoftDeletes;

    protected $primaryKey = 'web_id';

    protected $fillable = [
        'web_nama',
        'web_logo',
        'web_deskripsi',
        'user_id'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
