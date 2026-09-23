<?php
namespace App\Http\Controllers;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

class S3TestController {
    public function testS3Upload() {
        try {
            // Get S3 settings from env
            $s3Enabled = filter_var((string) env('S3_ENABLED', 'false'), FILTER_VALIDATE_BOOL);
            echo "S3 Enabled: " . ($s3Enabled ? 'YES' : 'NO') . "\n";
            
            if ($s3Enabled) {
                $key = trim((string) env('AWS_ACCESS_KEY_ID', ''));
                $secret = trim((string) env('AWS_SECRET_ACCESS_KEY', ''));
                if (str_starts_with($secret, 'ENC:')) {
                    try {
                        $secret = trim(Crypt::decryptString(substr($secret, 4)));
                    } catch (\Throwable) {
                        $secret = '';
                    }
                }
                $region = trim((string) env('AWS_DEFAULT_REGION', ''));
                $bucket = trim((string) env('AWS_BUCKET', ''));
                
                echo "S3 Credentials loaded: key={$key}, region={$region}, bucket={$bucket}\n";
                
                // Set S3 config dynamically
                Config::set('filesystems.disks.s3.key', $key);
                Config::set('filesystems.disks.s3.secret', $secret);
                Config::set('filesystems.disks.s3.region', $region);
                Config::set('filesystems.disks.s3.bucket', $bucket);
                
                // Try to get S3 disk
                echo "Attempting to access S3 disk...\n";
                $disk = Storage::disk('s3');
                echo "S3 disk accessed successfully!\n";
                echo "Disk class: " . get_class($disk) . "\n";
                
                // Try to put a text file (not upload yet)
                echo "Attempting to put test file...\n";
                $result = $disk->put('test/test.txt', 'Hello World');
                echo "Test file put result: " . ($result ? 'SUCCESS' : 'FAILED') . "\n";
                
            }
            
            return ['status' => 'success'];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ];
        }
    }
}
