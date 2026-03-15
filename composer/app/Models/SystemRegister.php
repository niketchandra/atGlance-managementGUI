<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SystemRegister extends Model
{
    use HasFactory;

    protected $table = 'system_register';
    
    // Disable auto-incrementing since we're using custom IDs
    public $incrementing = false;
    
    // Set the key type to integer for our random ID
    protected $keyType = 'int';

    protected $fillable = [
        'id',
        'pat_token_id',
        'user_id',
        'org_id',
        'system_name',
        'os_type',
        'ip_address',
        'tags',
        'metadata',
        'status',
        'validation_hash',
    ];
    
    protected $attributes = [
        'status' => 'active',
    ];
    
    /**
     * Generate a unique random ID between 5-10 digits
     */
    public static function generateUniqueId(): int
    {
        $attempts = 0;
        $maxAttempts = 100;
        
        do {
            // Generate random number between 10000 (5 digits) and 9999999999 (10 digits)
            $randomId = rand(10000, 9999999999);
            
            // Check if ID already exists
            $exists = self::where('id', $randomId)->exists();
            
            $attempts++;
            
            if ($attempts >= $maxAttempts) {
                throw new \RuntimeException('Unable to generate unique system register ID after ' . $maxAttempts . ' attempts');
            }
            
        } while ($exists);
        
        return $randomId;
    }
    
    /**
     * Boot method to auto-generate ID on creation
     */
    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = self::generateUniqueId();
            }
        });
    }
}
