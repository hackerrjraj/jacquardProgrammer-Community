<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'phone', 'email', 'subject', 'message', 'ip_address'])]
class ContactMessage extends Model
{
    protected function casts(): array
    {
        return [
            'is_read' => 'boolean',
        ];
    }
}
