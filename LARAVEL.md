# Laravel API guide

This document explains how the Laravel API is structured, how CRUD flows work end-to-end, and how to add new APIs (products and file upload/download examples included).

## Project layout (Laravel app lives in composer/)
- composer/app/Models: Eloquent models
- composer/app/Http/Controllers/Api: API controllers
- composer/routes/api.php: API routes (no /api prefix)
- composer/database/migrations: database schema
- composer/config: framework configuration (auth, database, sanctum)

## Request flow (CRUD)
1. Route defined in composer/routes/api.php receives a request.
2. Controller validates the input using Laravel validation rules.
3. Controller calls Eloquent model methods (create, update, delete).
4. Database writes are committed automatically by Eloquent.
5. Controller returns JSON response with a status code.

## Authentication flow (Sanctum)
- Login validates email/password and issues a token.
- The token is returned as access_token and used in Authorization header.
- Logout deletes the current access token.

Routes:
- POST /auth/login
- POST /auth/logout (requires Authorization: Bearer <token>)

Controller: composer/app/Http/Controllers/Api/AuthController.php

## User CRUD (complete process)

### Routes
Defined in composer/routes/api.php:
- Route::apiResource('users', UserController::class);

### Controller
File: composer/app/Http/Controllers/Api/UserController.php

What each action does:
- index: list all users
- store: validate, hash password, create user
- show: return one user by id
- update: validate optional fields, hash password if provided
- destroy: delete user

### Model
File: composer/app/Models/User.php
- Uses password_hash column
- Hides password_hash in JSON responses
- Uses Sanctum via HasApiTokens

### Migration
File: composer/database/migrations/0001_01_01_000000_create_users_table.php
- Creates users table with password_hash

### Example requests
Create:
```bash
curl -X POST http://localhost:8002/users \
  -H "Content-Type: application/json" \
  -d "{\"name\":\"User001\",\"email\":\"user001@example.com\",\"password\":\"Secret123!\"}"
```

List:
```bash
curl http://localhost:8002/users
```

Get:
```bash
curl http://localhost:8002/users/1
```

Update:
```bash
curl -X PUT http://localhost:8002/users/1 \
  -H "Content-Type: application/json" \
  -d "{\"name\":\"User001 Updated\"}"
```

Delete:
```bash
curl -X DELETE http://localhost:8002/users/1
```

## Products CRUD (complete process)

### Routes
Defined in composer/routes/api.php:
- Route::apiResource('products', ProductController::class);

Code to add in composer/routes/api.php:

```php
use App\Http\Controllers\Api\ProductController;

Route::apiResource('products', ProductController::class);
```

### Controller
File: composer/app/Http/Controllers/Api/ProductController.php

Code to add in composer/app/Http/Controllers/Api/ProductController.php:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
  public function index()
  {
    return Product::query()->get();
  }

  public function store(Request $request)
  {
    $data = $request->validate([
      'name' => ['required', 'string', 'max:200'],
      'sku' => ['required', 'string', 'max:100', 'unique:products,sku'],
      'price_cents' => ['required', 'integer', 'min:0'],
    ]);

    $product = Product::create($data);

    return response()->json($product, 201);
  }

  public function show(Product $product)
  {
    return $product;
  }

  public function update(Request $request, Product $product)
  {
    $data = $request->validate([
      'name' => ['sometimes', 'string', 'max:200'],
      'sku' => ['sometimes', 'string', 'max:100', Rule::unique('products', 'sku')->ignore($product->id)],
      'price_cents' => ['sometimes', 'integer', 'min:0'],
    ]);

    $product->fill($data);
    $product->save();

    return $product;
  }

  public function destroy(Product $product)
  {
    $product->delete();

    return response()->noContent();
  }
}
```

Actions:
- index: list all products
- store: validate and create product
- show: return one product
- update: validate optional fields and update
- destroy: delete product

### Model
File: composer/app/Models/Product.php

Code to add in composer/app/Models/Product.php:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
  use HasFactory;

  protected $fillable = [
    'name',
    'sku',
    'price_cents',
  ];
}
```

### Migration
File: composer/database/migrations/2026_02_14_000002_create_products_table.php

Code to add in composer/database/migrations/2026_02_14_000002_create_products_table.php:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up(): void
  {
    Schema::create('products', function (Blueprint $table) {
      $table->id();
      $table->string('name', 200);
      $table->string('sku', 100)->unique();
      $table->integer('price_cents');
      $table->timestamps();
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('products');
  }
};
```

### Example requests
Create:
```bash
curl -X POST http://localhost:8002/products \
  -H "Content-Type: application/json" \
  -d "{\"name\":\"Widget\",\"sku\":\"WID-001\",\"price_cents\":1200}"
```

List:
```bash
curl http://localhost:8002/products
```

Get:
```bash
curl http://localhost:8002/products/1
```

Update:
```bash
curl -X PUT http://localhost:8002/products/1 \
  -H "Content-Type: application/json" \
  -d "{\"price_cents\":1500}"
```

Delete:
```bash
curl -X DELETE http://localhost:8002/products/1
```

## File upload and download API

### Routes
Defined in composer/routes/api.php:
- POST /files/upload
- GET /files/{fileId}

Code to add in composer/routes/api.php:

```php
use App\Http\Controllers\Api\FileController;

Route::post('/files/upload', [FileController::class, 'upload']);
Route::get('/files/{fileId}', [FileController::class, 'download']);
```

### Controller
File: composer/app/Http/Controllers/Api/FileController.php

Code to add in composer/app/Http/Controllers/Api/FileController.php:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FileController extends Controller
{
  public function upload(Request $request)
  {
    $data = $request->validate([
      'file' => ['required', 'file', 'max:10240'],
    ]);

    $file = $data['file'];
    $fileId = Str::uuid()->toString();
    $safeName = $file->getClientOriginalName() ?: 'upload.bin';
    $path = 'uploads/' . $fileId . '_' . $safeName;

    Storage::disk('local')->put($path, file_get_contents($file->getRealPath()));

    return response()->json([
      'file_id' => $fileId,
      'filename' => $safeName,
    ]);
  }

  public function download(string $fileId)
  {
    $files = Storage::disk('local')->files('uploads');
    $match = null;

    foreach ($files as $file) {
      if (str_starts_with(basename($file), $fileId . '_')) {
        $match = $file;
        break;
      }
    }

    if (!$match) {
      return response()->json(['detail' => 'File not found'], 404);
    }

    return Storage::disk('local')->download($match);
  }
}
```

Behavior:
- upload: validates a file (max 10 MB), stores under storage/app/uploads
- download: finds the file by id and returns it as a download

### Example requests
Upload:
```bash
curl -X POST http://localhost:8002/files/upload \
  -F "file=@./path/to/your/file.txt"
```

Download (use file_id from upload response):
```bash
curl -O http://localhost:8002/files/<file_id>
```

## How to add a new API (step by step)
1. Create a migration in composer/database/migrations.
2. Create an Eloquent model in composer/app/Models.
3. Create a controller in composer/app/Http/Controllers/Api.
4. Add routes in composer/routes/api.php.
5. Run migrations:

```bash
docker compose exec api php artisan migrate --force
```

6. Update Kong (add route in kong/kong.yml) and restart Kong:

```bash
docker compose -f docker-compose.yml -f docker-compose-kong.yml restart kong
```
