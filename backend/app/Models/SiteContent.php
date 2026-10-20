<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One customised public-page text (key → Bangla / English). Empty value = use the translation-file default. */
class SiteContent extends Model
{
    protected $fillable = ['key', 'value_bn', 'value_en', 'updated_by'];
}
