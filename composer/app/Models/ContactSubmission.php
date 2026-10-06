<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A message sent through the public Contact page.
 */
class ContactSubmission extends Model
{
    protected $fillable = [
        'name',
        'email',
        'subject',
        'message',
        'ip_address',
        'user_agent',
    ];
}
