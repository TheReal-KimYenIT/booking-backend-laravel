<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Media extends Model
{
    public $timestamps = false;
    protected $fillable = ['model_type', 'model_id', 'file_url', 'is_primary', 'sort_order'];
}