<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GedcomTree extends Model
{
    protected $table = 'gedcom_tree';
    protected $primaryKey = 'atreeid';
    public $incrementing = false;
    public $timestamps = false;
}
