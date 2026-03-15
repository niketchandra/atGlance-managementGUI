# API Gateway Documentation

**Base URL**: `http://localhost:8002`  
**API Gateway**: Kong (Proxy)  
**Backend API**: Laravel (Port 8000)

---

## Table of Contents

1. [Authentication](#authentication)
2. [User Management](#user-management)
3. [PAT Token Management](#pat-token-management)
4. [Token Validation](#token-validation)
5. [System Registration](#system-registration)
6. [System Deregistration](#system-deregistration)
7. [System Reactivation](#system-reactivation)
8. [Admin APIs](#admin-apis)
9. [Services Management](#services-management)
10. [Configuration File Management](#configuration-file-management)
  - [Upload](#upload-configuration-file)
  - [List](#list-configuration-files)
  - [Filter by System & Hash](#list-configuration-files-by-system-and-validation-hash)
  - [Download](#download-configuration-file)
  - [Download by ID](#download-configuration-file-by-id)
  - [Raw Data](#get-configuration-file-raw-data)
  - [Delete](#delete-configuration-file)
11. [File Operations](#file-operations)
12. [Error Responses](#error-responses)
13. [Authentication Notes](#authentication-notes)
14. [Rate Limiting](#rate-limiting)
15. [Data Storage](#data-storage)
16. [Changelog](#changelog)

---

## Authentication

### Register User

**Endpoint**: `POST /auth/register`

**Description**: Create a new user account

**Headers**:
```
Content-Type: application/json
```

**Request Body**:
```json
{
  "name": "John Doe",
  "email": "john@example.com",
  "password": "SecurePassword@123",
  "dob": "1990-05-15"
}
```

**Response** (201):
```json
{
  "name": "John Doe",
  "email": "john@example.com",
  "dob": "1990-05-15T00:00:00.000000Z",
  "created_at": "2026-02-27T20:01:05.000000Z",
  "updated_at": "2026-02-27T20:01:05.000000Z",
  "id": 1
}
```

**Example**:
```bash
curl -X POST http://localhost:8002/auth/register \
  -H "Content-Type: application/json" \
  -d '{
    "name": "John Doe",
    "email": "john@example.com",
    "password": "SecurePassword@123",
    "dob": "1990-05-15"
  }'
```

---

### Login User

**Endpoint**: `POST /auth/login`

**Description**: Login with email and password to get session token

**Headers**:
```
Content-Type: application/json
```

**Request Body**:
```json
{
  "email": "john@example.com",
  "password": "SecurePassword@123"
}
```

**Response** (200):
```json
{
  "message": "Login successful",
  "access_token": "bearer_token_value",
  "user": {
    "id": 1,
    "name": "John Doe",
    "email": "john@example.com",
    "org_id": 200,
    "rbac_id": 102,
    "status": "active"
  }
}
```

**Response Fields**:
- `access_token`: Session bearer token for authentication
- `user.id`: User ID
- `user.name`: User's full name
- `user.email`: User's email address
- `user.org_id`: Organization ID the user belongs to
- `user.rbac_id`: Role-Based Access Control ID (100=Super Admin, 101=Admin, 102=User)
- `user.status`: Account status (active/inactive)

**Example**:
```bash
curl -X POST http://localhost:8002/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "john@example.com",
    "password": "SecurePassword@123"
  }'
```

---

### Logout User

**Endpoint**: `POST /auth/logout`

**Description**: Logout and invalidate session token

**Headers**:
```
Authorization: Bearer {session_token}
```

**Response** (200):
```json
{
  "message": "Logged out successfully"
}
```

**Example**:
```bash
curl -X POST http://localhost:8002/auth/logout \
  -H "Authorization: Bearer {session_token}"
```

---

## User Management

### Get All Users

**Endpoint**: `GET /users`

**Description**: Retrieve all users (requires session token)

**Headers**:
```
Authorization: Bearer {session_token}
```

**Response** (200):
```json
[
  {
    "id": 1,
    "name": "John Doe",
    "email": "john@example.com",
    "dob": "1990-05-15T00:00:00.000000Z",
    "created_at": "2026-02-27T20:01:05.000000Z",
    "updated_at": "2026-02-27T20:01:05.000000Z"
  }
]
```

**Example**:
```bash
curl -X GET http://localhost:8002/users \
  -H "Authorization: Bearer {session_token}"
```

---

### Get Single User

**Endpoint**: `GET /users/{userId}`

**Description**: Retrieve a specific user by ID

**Headers**:
```
Authorization: Bearer {session_token}
```

**Response** (200):
```json
{
  "id": 1,
  "name": "John Doe",
  "email": "john@example.com",
  "dob": "1990-05-15T00:00:00.000000Z",
  "created_at": "2026-02-27T20:01:05.000000Z",
  "updated_at": "2026-02-27T20:01:05.000000Z"
}
```

**Example**:
```bash
curl -X GET http://localhost:8002/users/1 \
  -H "Authorization: Bearer {session_token}"
```

---

### Create User

**Endpoint**: `POST /users`

**Description**: Create a new user (with session token)

**Headers**:
```
Authorization: Bearer {session_token}
Content-Type: application/json
```

**Request Body**:
```json
{
  "name": "Jane Smith",
  "email": "jane@example.com",
  "password": "SecurePassword@456",
  "dob": "1992-08-20"
}
```

**Response** (201):
```json
{
  "id": 2,
  "name": "Jane Smith",
  "email": "jane@example.com",
  "dob": "1992-08-20T00:00:00.000000Z",
  "created_at": "2026-02-27T21:00:00.000000Z",
  "updated_at": "2026-02-27T21:00:00.000000Z"
}
```

**Example**:
```bash
curl -X POST http://localhost:8002/users \
  -H "Authorization: Bearer {session_token}" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Jane Smith",
    "email": "jane@example.com",
    "password": "SecurePassword@456",
    "dob": "1992-08-20"
  }'
```

---

### Update User

**Endpoint**: `PUT /users/{userId}`

**Description**: Update user information

**Headers**:
```
Authorization: Bearer {session_token}
Content-Type: application/json
```

**Request Body**:
```json
{
  "name": "John Updated",
  "email": "john.updated@example.com",
  "dob": "1990-05-15"
}
```

**Response** (200):
```json
{
  "message": "User updated successfully",
  "user": {
    "id": 1,
    "name": "John Updated",
    "email": "john.updated@example.com",
    "dob": "1990-05-15T00:00:00.000000Z"
  }
}
```

**Example**:
```bash
curl -X PUT http://localhost:8002/users/1 \
  -H "Authorization: Bearer {session_token}" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "John Updated",
    "email": "john.updated@example.com",
    "dob": "1990-05-15"
  }'
```

---

### Delete User

**Endpoint**: `DELETE /users/{userId}`

**Description**: Delete a user account

**Headers**:
```
Authorization: Bearer {session_token}
```

**Response** (204):
```
No content
```

**Example**:
```bash
curl -X DELETE http://localhost:8002/users/1 \
  -H "Authorization: Bearer {session_token}"
```

<!-- ---

## Product Management

### Get All Products

**Endpoint**: `GET /products`

**Description**: Retrieve all products

**Headers**:
```
Authorization: Bearer {session_token}
```

**Response** (200):
```json
[
  {
    "id": 1,
    "name": "Product A",
    "description": "Description of Product A",
    "price": 99.99,
    "created_at": "2026-02-27T20:05:00.000000Z",
    "updated_at": "2026-02-27T20:05:00.000000Z"
  }
]
```

**Example**:
```bash
curl -X GET http://localhost:8002/products \
  -H "Authorization: Bearer {session_token}"
```

---

### Get Single Product

**Endpoint**: `GET /products/{productId}`

**Description**: Retrieve a specific product by ID

**Headers**:
```
Authorization: Bearer {session_token}
```

**Response** (200):
```json
{
  "id": 1,
  "name": "Product A",
  "description": "Description of Product A",
  "price": 99.99,
  "created_at": "2026-02-27T20:05:00.000000Z",
  "updated_at": "2026-02-27T20:05:00.000000Z"
}
```

**Example**:
```bash
curl -X GET http://localhost:8002/products/1 \
  -H "Authorization: Bearer {session_token}"
```

---

### Create Product

**Endpoint**: `POST /products`

**Description**: Create a new product

**Headers**:
```
Authorization: Bearer {session_token}
Content-Type: application/json
```

**Request Body**:
```json
{
  "name": "New Product",
  "description": "Product description",
  "price": 149.99
}
```

**Response** (201):
```json
{
  "id": 2,
  "name": "New Product",
  "description": "Product description",
  "price": 149.99,
  "created_at": "2026-02-27T21:10:00.000000Z",
  "updated_at": "2026-02-27T21:10:00.000000Z"
}
```

**Example**:
```bash
curl -X POST http://localhost:8002/products \
  -H "Authorization: Bearer {session_token}" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "New Product",
    "description": "Product description",
    "price": 149.99
  }'
```

 ---

### Update Product

**Endpoint**: `PUT /products/{productId}`

**Description**: Update product information

**Headers**:
```
Authorization: Bearer {session_token}
Content-Type: application/json
```

**Request Body**:
```json
{
  "name": "Updated Product",
  "description": "Updated description",
  "price": 199.99
}
```

**Response** (200):
```json
{
  "id": 1,
  "name": "Updated Product",
  "description": "Updated description",
  "price": 199.99,
  "created_at": "2026-02-27T20:05:00.000000Z",
  "updated_at": "2026-02-27T21:15:00.000000Z"
}
```

**Example**:
```bash
curl -X PUT http://localhost:8002/products/1 \
  -H "Authorization: Bearer {session_token}" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Updated Product",
    "description": "Updated description",
    "price": 199.99
  }'
```

---

### Delete Product

**Endpoint**: `DELETE /products/{productId}`

**Description**: Delete a product

**Headers**:
```
Authorization: Bearer {session_token}
```

**Response** (204):
```
No content
```

**Example**:
```bash
curl -X DELETE http://localhost:8002/products/1 \
  -H "Authorization: Bearer {session_token}"
``` -->

---

## PAT Token Management

### Create PAT Token

**Endpoint**: `POST /auth/pat-tokens`

**Description**: Generate a new Personal Access Token (requires session token)

**Headers**:
```
Authorization: Bearer {session_token}
Content-Type: application/json
```

**Request Body**:
```json
{
  "name": "API Token",
  "abilities": ["*"],
  "expires_at": "2026-12-31"
}
```

**Response** (201):
```json
{
  "message": "PAT token created successfully",
  "token": "atgla-xPyt2TeLn3TbbalkBMN",
  "token_details": {
    "id": 1,
    "name": "API Token",
    "abilities": ["*"],
    "expires_at": "2026-12-31",
    "created_at": "2026-02-27T21:20:00.000000Z"
  }
}
```

**Example**:
```bash
curl -X POST http://localhost:8002/auth/pat-tokens \
  -H "Authorization: Bearer {session_token}" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "API Token",
    "abilities": ["*"],
    "expires_at": "2026-12-31"
  }'
```

---

### List PAT Tokens

**Endpoint**: `GET /auth/pat-tokens`

**Description**: Retrieve all PAT tokens for authenticated user

**Headers**:
```
Authorization: Bearer {session_token}
```

**Response** (200):
```json
{
  "total": 1,
  "tokens": [
    {
      "id": 1,
      "name": "API Token",
      "abilities": ["*"],
      "last_used_at": null,
      "expires_at": "2026-12-31",
      "created_at": "2026-02-27T21:20:00.000000Z"
    }
  ]
}
```

**Example**:
```bash
curl -X GET http://localhost:8002/auth/pat-tokens \
  -H "Authorization: Bearer {session_token}"
```

---

## Token Validation

Validate Personal Access Tokens to verify authentication and user status.

### Validate PAT Token (GET)

**Endpoint**: `GET /auth/validate-token`

**Description**: Validate a PAT token by extracting it from Authorization header. Returns user information if token is valid and user status is 'active'

**Headers**:
```
Authorization: Bearer {token}
```

**Response** (200 - Valid Token):
```json
{
  "message": "Token is valid",
  "is_valid": true,
  "user": {
    "id": 3,
    "name": "Nitin",
    "email": "nitin@gmail.com",
    "dob": "1992-01-01T00:00:00.000000Z",
    "org_id": 200,
    "status": "active",
    "created_at": "2026-02-27T20:04:38.000000Z",
    "updated_at": "2026-02-27T20:04:38.000000Z"
  },
  "token_info": {
    "id": 1,
    "name": "my_pat1",
    "abilities": ["*"],
    "expires_at": "2099-12-31T23:59:59.000000Z",
    "last_used_at": "2026-02-27T22:17:00.000000Z",
    "created_at": "2026-02-27T20:04:45.000000Z"
  }
}
```

**Response** (401 - Invalid/Expired Token):
```json
{
  "message": "Invalid or expired token",
  "is_valid": false,
  "error": "Token not found or has expired"
}
```

**Response** (401 - Revoked/Inactive Token):
```json
{
  "message": "Token is revoked or inactive",
  "is_valid": false,
  "token_status": "revoked"
}
```

**Response** (403 - Inactive User):
```json
{
  "message": "User account is not active",
  "is_valid": false,
  "error": "User status is not active"
}
```

**Example**:
```bash
curl -X GET http://localhost:8002/auth/validate-token \
  -H "Authorization: Bearer atgla-xPyt2TeLn3TbbalkBMN"
```

---

### Validate PAT Token (POST)

**Endpoint**: `POST /auth/validate-token`

**Description**: Validate a PAT token by passing it in request body. Returns user information if token is valid and user status is 'active'

**Headers**:
```
Content-Type: application/json
```

**Request Body**:
```json
{
  "token": "atgla-xPyt2TeLn3TbbalkBMN"
}
```

**Response** (200 - Valid Token):
```json
{
  "message": "Token is valid",
  "is_valid": true,
  "user": {
    "id": 3,
    "name": "Nitin",
    "email": "nitin@gmail.com",
    "dob": "1992-01-01T00:00:00.000000Z",
    "org_id": 200,
    "status": "active",
    "created_at": "2026-02-27T20:04:38.000000Z",
    "updated_at": "2026-02-27T20:04:38.000000Z"
  },
  "token_info": {
    "id": 1,
    "name": "my_pat1",
    "abilities": ["*"],
    "expires_at": "2099-12-31T23:59:59.000000Z",
    "last_used_at": "2026-02-27T22:17:06.000000Z",
    "created_at": "2026-02-27T20:04:45.000000Z"
  }
}
```

**Response** (400 - Missing Token):
```json
{
  "message": "Token is required",
  "is_valid": false,
  "error": "Token field is missing from request body"
}
```

**Response** (401 - Invalid/Expired Token):
```json
{
  "message": "Invalid or expired token",
  "is_valid": false,
  "error": "Token not found or has expired"
}
```

**Response** (401 - Revoked/Inactive Token):
```json
{
  "message": "Token is revoked or inactive",
  "is_valid": false,
  "token_status": "revoked"
}
```

**Response** (403 - Inactive User):
```json
{
  "message": "User account is not active",
  "is_valid": false,
  "error": "User status is not active"
}
```

**Example**:
```bash
curl -X POST http://localhost:8002/auth/validate-token \
  -H "Content-Type: application/json" \
  -d '{"token": "atgla-xPyt2TeLn3TbbalkBMN"}'
```

---

## System Registration

### Register System

**Endpoint**: `POST /system-register`

**Description**: Register a system/device with PAT token. Accepts optional `validation_hash` for system validation.

**Headers**:
```
Authorization: Bearer {pat_token}
Content-Type: application/json
```

**Request Body** (JSON) or **Query Parameters**:
```json
{
  "system_name": "Production Server",
  "os_type": "Linux",
  "ip_address": "192.168.1.100",
  "tags": "prod, critical, backend",
  "metadata": "{\"cpu\": \"x86_64\", \"hostname\": \"prod-server\"}",
  "validation_hash": "11111111111111111111111111111111111111111111111111"
}
```

**Note**: The `org_id` field is automatically populated on the server-side from the authenticated user's organization. It is not accepted from the request body for security reasons.

**Response** (201):
```json
{
  "message": "System registered successfully",
  "system": {
    "id": 1,
    "pat_token_id": 1,
    "user_id": 1,
    "system_name": "Production Server",
    "os_type": "Linux",
    "ip_address": "192.168.1.100",
    "org_id": 1,
    "tags": "prod, critical, backend",
    "metadata": "{\"cpu\": \"x86_64\", \"hostname\": \"prod-server\"}",
    "validation_hash": "11111111111111111111111111111111111111111111111111",
    "status": "active",
    "created_at": "2026-02-27T21:25:00.000000Z"
  }
}
```

**Example** (with validation_hash via query params):
```bash
curl -X POST "http://localhost:8002/system-register?system_name=Production%20Server&os_type=Linux&ip_address=192.168.1.100&validation_hash=11111111111111111111111111111111111111111111111111" \
  -H "Authorization: Bearer atgla-xPyt2TeLn3TbbalkBMN" \
  -H "Content-Type: application/json"
```

**Example** (with validation_hash in JSON body):
```bash
curl -X POST http://localhost:8002/system-register \
  -H "Authorization: Bearer atgla-xPyt2TeLn3TbbalkBMN" \
  -H "Content-Type: application/json" \
  -d '{
    "system_name": "Production Server",
    "os_type": "Linux",
    "ip_address": "192.168.1.100",
    "org_id": 1,
    "tags": "prod, critical, backend",
    "metadata": "{\"cpu\": \"x86_64\", \"hostname\": \"prod-server\"}",
    "validation_hash": "11111111111111111111111111111111111111111111111111"
  }'
```

---

### List All Registered Systems

**Endpoint**: `GET /system-register`

**Description**: Get all systems registered by authenticated user

**Headers**:
```
Authorization: Bearer {pat_token}
```

**Response** (200):
```json
{
  "total": 2,
  "systems": [
    {
      "id": 2,
      "pat_token_id": 1,
      "user_id": 1,
      "system_name": "Server 2",
      "os_type": "Linux",
      "ip_address": "192.168.1.200",
      "tags": "master, ubuntu",
      "created_at": "2026-02-27T21:25:30.000000Z"
    },
    {
      "id": 1,
      "pat_token_id": 1,
      "user_id": 1,
      "system_name": "Server 1",
      "os_type": "Windows",
      "ip_address": "192.168.1.100",
      "tags": "prod, critical",
      "created_at": "2026-02-27T21:25:00.000000Z"
    }
  ]
}
```

**Example**:
```bash
curl -X GET http://localhost:8002/system-register \
  -H "Authorization: Bearer atgla-xPyt2TeLn3TbbalkBMN"
```

---

### Get Systems by PAT Token

**Endpoint**: `GET /system-register/pat/{patTokenId}`

**Description**: Get all systems registered with a specific PAT token

**Headers**:
```
Authorization: Bearer {pat_token}
```

**Response** (200):
```json
{
  "pat_token_id": 1,
  "pat_token_name": "API Token",
  "total_systems": 2,
  "systems": [
    {
      "id": 2,
      "pat_token_id": 1,
      "user_id": 1,
      "system_name": "Server 2",
      "os_type": "Linux",
      "ip_address": "192.168.1.200"
    },
    {
      "id": 1,
      "pat_token_id": 1,
      "user_id": 1,
      "system_name": "Server 1",
      "os_type": "Windows",
      "ip_address": "192.168.1.100"
    }
  ]
}
```

**Example**:
```bash
curl -X GET http://localhost:8002/system-register/pat/1 \
  -H "Authorization: Bearer atgla-xPyt2TeLn3TbbalkBMN"
```

---

### Get Systems by User

**Endpoint**: `GET /system-register/user/{userId}`

**Description**: Get all systems registered by a specific user (with breakdown by PAT token)

**Headers**:
```
Authorization: Bearer {pat_token}
```

**Response** (200):
```json
{
  "user_id": 1,
  "total_systems": 2,
  "systems_by_pat_token": [
    {
      "pat_token_id": 1,
      "pat_token_name": "API Token",
      "count": 2
    }
  ],
  "systems": [
    {
      "id": 2,
      "pat_token_id": 1,
      "user_id": 1,
      "system_name": "Server 2",
      "os_type": "Linux",
      "ip_address": "192.168.1.200"
    }
  ]
}
```

**Example**:
```bash
curl -X GET http://localhost:8002/system-register/user/1 \
  -H "Authorization: Bearer atgla-xPyt2TeLn3TbbalkBMN"
```

---

## System Deregistration

Deregister systems and change their status from active to inactive.

### Deregister System

**Endpoint**: `POST /system-deregister`

**Description**: Deregister a system by changing its status from `active` to `inactive`. Only the system owner (authenticated user) can deregister their own systems.

**Headers**:
```
Authorization: Bearer {pat_token}
Content-Type: application/json
```

**Query Parameters** or **Request Body**:
- `systemId` (required, integer): The system ID to deregister

**Response** (200):
```json
{
  "message": "System deregistered successfully",
  "system_id": 1828058512,
  "status": "inactive"
}
```

**Error Responses**:
- `400`: systemId is required
- `401`: Unauthorized (invalid or missing PAT token)
- `403`: Unauthorized (system belongs to another user)
- `404`: System not found

**Example** (via query parameter):
```bash
curl -X POST "http://localhost:8002/system-deregister?systemId=1828058512" \
  -H "Authorization: Bearer atgla-xPyt2TeLn3TbbalkBMN" \
  -H "Content-Type: application/json"
```

**Example** (via request body):
```bash
curl -X POST "http://localhost:8002/system-deregister" \
  -H "Authorization: Bearer atgla-xPyt2TeLn3TbbalkBMN" \
  -H "Content-Type: application/json" \
  -d '{
    "systemId": 1828058512
  }'
```

---

### Force Deregister System (Admin)

**Endpoint**: `POST /system-deregister-force`

**Description**: Force deregister any system by changing its status from `active` to `inactive`. This endpoint requires session token authentication and does not validate system ownership. Intended for administrative use.

**Headers**:
```
Authorization: Bearer {session_token}
Content-Type: application/json
```

**Query Parameters** or **Request Body**:
- `systemId` (required, integer): The system ID to force deregister

**Response** (200):
```json
{
  "message": "System deregistered successfully",
  "system_id": 4057010410,
  "status": "inactive",
  "deregistered_by_user_id": 1010
}
```

**Error Responses**:
- `400`: systemId is required
- `401`: Unauthorized (invalid or missing session token)
- `404`: System not found

**Example** (via query parameter):
```bash
curl -X POST "http://localhost:8002/system-deregister-force?systemId=4057010410" \
  -H "Authorization: Bearer {session_token}" \
  -H "Content-Type: application/json"
```

**Example** (via request body):
```bash
curl -X POST "http://localhost:8002/system-deregister-force" \
  -H "Authorization: Bearer {session_token}" \
  -H "Content-Type: application/json" \
  -d '{
    "systemId": 4057010410
  }'
```

**Security Notes**:
- Requires session bearer token (not PAT token)
- Does not validate system ownership - can deregister any system
- Records the user ID who performed the force deregistration
- Intended for administrative/support use cases

---

## System Reactivation

Reactivate systems and change their status from inactive to active.

### Reactive System (User Level)

**Endpoint**: `POST /system-reactive`

**Description**: Reactivate a system by changing its status from `inactive` to `active`. Only the system owner (authenticated user) can reactivate their own systems.

**Headers**:
```
Authorization: Bearer {pat_token}
Content-Type: application/json
```

**Query Parameters** or **Request Body**:
- `systemId` (required, integer): The system ID to reactivate

**Response** (200):
```json
{
  "message": "System reactivated successfully",
  "system_id": 1828058512,
  "status": "active"
}
```

**Error Responses**:
- `400`: systemId is required
- `401`: Unauthorized (invalid or missing PAT token)
- `403`: Unauthorized (system belongs to another user)
- `404`: System not found

**Example** (via query parameter):
```bash
curl -X POST "http://localhost:8002/system-reactive?systemId=1828058512" \
  -H "Authorization: Bearer atgla-xPyt2TeLn3TbbalkBMN" \
  -H "Content-Type: application/json"
```

**Example** (via request body):
```bash
curl -X POST "http://localhost:8002/system-reactive" \
  -H "Authorization: Bearer atgla-xPyt2TeLn3TbbalkBMN" \
  -H "Content-Type: application/json" \
  -d '{
    "systemId": 1828058512
  }'
```

---

## Admin APIs

Admin-level endpoints that require session token authentication and operate with elevated privileges. These endpoints do not validate resource ownership and are intended for administrative/support operations.

### Force Reactivate System (Admin)

**Endpoint**: `POST /system-reactivate-force` or `GET /system-reactivate-force`

**Description**: Force reactivate any system by changing its status from `inactive` to `active`. This endpoint requires session token authentication and does not validate system ownership. Intended for administrative use.

**Headers**:
```
Authorization: Bearer {session_token}
Content-Type: application/json
```

**Query Parameters** or **Request Body**:
- `systemId` (required, integer): The system ID to force reactivate

**Response** (200): When system was successfully reactivated
```json
{
  "message": "System force reactivated successfully",
  "reactivated_by_user_id": 1010,
  "system_id": 4057010410,
  "system_user_id": 42,
  "status": "active"
}
```

**Response** (200): When system is already active
```json
{
  "message": "System is already active",
  "reactivated_by_user_id": 1010,
  "system_id": 4057010410,
  "system_user_id": 42,
  "status": "active"
}
```

**Error Responses**:
- `400`: systemId is required
- `401`: Unauthorized (invalid or missing session token)
- `404`: System not found

**Example** (via query parameter with POST):
```bash
curl -X POST "http://localhost:8002/system-reactivate-force?systemId=4057010410" \
  -H "Authorization: Bearer {session_token}" \
  -H "Content-Type: application/json"
```

**Example** (via query parameter with GET):
```bash
curl -X GET "http://localhost:8002/system-reactivate-force?systemId=4057010410" \
  -H "Authorization: Bearer {session_token}"
```

**Example** (via request body):
```bash
curl -X POST "http://localhost:8002/system-reactivate-force" \
  -H "Authorization: Bearer {session_token}" \
  -H "Content-Type: application/json" \
  -d '{
    "systemId": 4057010410
  }'
```

**Security Notes**:
- Requires session bearer token (not PAT token)
- Does not validate system ownership - can reactivate any system
- Records the admin user ID who performed the force reactivation
- Intended for administrative/support use cases
- Supports both POST and GET HTTP methods

---

### Force Deregister System (Admin)

**Endpoint**: `POST /system-deregister-force` or `GET /system-deregister-force`

**Description**: Force deregister any system by changing its status from `active` to `inactive`. This endpoint requires session token authentication and does not validate system ownership. Intended for administrative use.

**Headers**:
```
Authorization: Bearer {session_token}
Content-Type: application/json
```

**Query Parameters** or **Request Body**:
- `systemId` (required, integer): The system ID to force deregister

**Response** (200):
```json
{
  "message": "System force deregistered successfully",
  "deregistered_by_user_id": 1010,
  "system_id": 4057010410,
  "system_user_id": 42,
  "status": "inactive"
}
```

**Error Responses**:
- `400`: systemId is required
- `401`: Unauthorized (invalid or missing session token)
- `404`: System not found

**Example** (via query parameter with POST):
```bash
curl -X POST "http://localhost:8002/system-deregister-force?systemId=4057010410" \
  -H "Authorization: Bearer {session_token}" \
  -H "Content-Type: application/json"
```

**Example** (via query parameter with GET):
```bash
curl -X GET "http://localhost:8002/system-deregister-force?systemId=4057010410" \
  -H "Authorization: Bearer {session_token}"
```

**Example** (via request body):
```bash
curl -X POST "http://localhost:8002/system-deregister-force" \
  -H "Authorization: Bearer {session_token}" \
  -H "Content-Type: application/json" \
  -d '{
    "systemId": 4057010410
  }'
```

**Security Notes**:
- Requires session bearer token (not PAT token)
- Does not validate system ownership - can deregister any system
- Records the user ID who performed the force deregistration
- Intended for administrative/support use cases
- Supports both POST and GET HTTP methods

---

## Services Management

### Create Service

**Endpoint**: `POST /services`

**Description**: Create (or reuse) a service for the authenticated PAT user. `service_id` starts from 100.

**Headers**:
```
Authorization: Bearer {pat_token}
Content-Type: application/json
```

**Request Body**:
```json
{
  "service_name": "testService",
  "system_id": 1093719686,
  "system_hash": "abc123def456",
  "org_id": 200,
  "share_with": "team-a"
}
```

### List Services

**Endpoint**: `GET /services`

**Description**: List active services for authenticated PAT user. Optional query: `system_id`.

### Get Service By ID

**Endpoint**: `GET /services/{serviceId}`

**Description**: Get one active service belonging to authenticated PAT user.

---

## Configuration File Management

### Upload Configuration File

**Endpoint**: `POST /config-files/upload`

**Description**: Save configuration using service-first flow and upload a configuration file (text, .config, .conf, .cfg)

**Config-Save Flow**:
1. Resolve service:
  - Use `service_id` if provided, or
  - Create/reuse service from `service_name + system_id/system_register_id + user_id`
2. Save metadata to `configuration_files` with `service_id`
3. Save content to `raw_data` with same `service_id`

Flow order: **Service -> configuration_files -> raw_data**

**Headers**:
```
Authorization: Bearer {pat_token}
Content-Type: multipart/form-data
```

**Request Body**:
- Form field: `file` (multipart file, max 10MB) **[Required]**
- Form field: `service_id` (integer) **[Optional]** - Use existing service directly
- Form field: `system_register_id` OR `system_id` (integer) **[Required when service_id is not provided]**
- Form field: `service_name` (string) **[Required when service_id is not provided]**
- Form field: `system_hash` (string, max 255) **[Optional]** - Used while creating service
- Form field: `org_id` (integer) **[Optional]** - Used while creating service
- Form field: `share_with` (string, max 255) **[Optional]** - Used while creating service
- Form field: `validation_hash` (string, max 255) **[Optional]** - Hash for validation purposes
- Form field: `version` (string, max 50) **[Optional]** - Version identifier for the configuration file

**Validation Rules**:
- If `service_id` is provided, it must belong to the authenticated PAT user.
- If `service_id` is not provided, both `system_register_id/system_id` and `service_name` are required.

**Response** (201):
```json
{
  "message": "Configuration file uploaded successfully",
  "file": {
    "id": 1,
    "file_name": "app.config",
    "original_name": "app.config",
    "file_location": "config_files/1/uuid-app.config",
    "file_size": 512,
    "system_register_id": "1093719686",
    "service_id": 100,
    "service_name": "testService",
    "validation_hash": "abc123def456",
    "version": "1.0.0",
    "created_at": "2026-02-27T21:30:00.000000Z"
  }
}
```

**Example**:
```bash
curl -X POST http://localhost:8002/config-files/upload \
  -H "Authorization: Bearer atgla-xPyt2TeLn3TbbalkBMN" \
  -F "file=@app.config" \
  -F "system_register_id=1093719686" \
  -F "service_name=testService" \
  -F "org_id=200" \
  -F "system_hash=abc123def456" \
  -F "validation_hash=abc123def456" \
  -F "version=1.0.0"
```

**Example (Using Existing service_id)**:
```bash
curl -X POST http://localhost:8002/config-files/upload \
  -H "Authorization: Bearer atgla-xPyt2TeLn3TbbalkBMN" \
  -F "file=@app.config" \
  -F "service_id=100" \
  -F "validation_hash=abc123def456" \
  -F "version=1.0.0"
```

---

### List Configuration Files by System and Validation Hash

**Endpoint**: `GET /config-files/filter`

**Description**: List active configuration files for authenticated PAT user filtered by `system_id` and `validation_hash`

**Headers**:
```
Authorization: Bearer {pat_token}
```

**Query Parameters**:
- `system_id` (required, integer): Registered system ID
- `validation_hash` (required, string, max 255): Validation hash to match

**Response** (200):
```json
{
  "system_id": 1093719686,
  "validation_hash": "abc123def456",
  "total": 1,
  "files": [
    {
      "id": 12,
      "file_name": "app.config",
      "service_id": 100,
      "service_name": "testService",
      "system_register_id": 1093719686,
      "validation_hash": "abc123def456",
      "version": "1.0.0",
      "file_location": "config_files/3/uuid-app.config",
      "status": "active",
      "created_at": "2026-02-28T00:15:00.000000Z",
      "updated_at": "2026-02-28T00:15:00.000000Z"
    }
  ]
}
```

**Example**:
```bash
curl -X GET "http://localhost:8002/config-files/filter?system_id=1093719686&validation_hash=abc123def456" \
  -H "Authorization: Bearer atgla-xPyt2TeLn3TbbalkBMN"
```

---

### List Configuration Files

**Endpoint**: `GET /config-files`

**Description**: List all active configuration files for authenticated user

**Headers**:
```
Authorization: Bearer {pat_token}
```

**Response** (200):
```json
{
  "total": 1,
  "files": [
    {
      "id": 1,
      "file_name": "app.config",
      "file_location": "config_files/1/uuid-app.config",
      "version": "1.0.0",
      "status": "active",
      "created_at": "2026-02-27T21:30:00.000000Z",
      "updated_at": "2026-02-27T21:30:00.000000Z"
    }
  ]
}
```

**Example**:
```bash
curl -X GET http://localhost:8002/config-files \
  -H "Authorization: Bearer atgla-xPyt2TeLn3TbbalkBMN"
```

---

### Download Configuration File

**Endpoint**: `GET /config-files/{fileId}`

**Description**: Download a configuration file from file system

**Headers**:
```
Authorization: Bearer {pat_token}
```

**Response** (200):
- Returns the file with appropriate Content-Type header
- File is downloaded with original filename

**Example**:
```bash
curl -X GET http://localhost:8002/config-files/1 \
  -H "Authorization: Bearer atgla-xPyt2TeLn3TbbalkBMN" \
  -o downloaded-app.config
```

---

### Download Configuration File by ID

**Endpoint**: `GET /config-files/download/{id}`

**Description**: Download an active configuration file using configuration file `id` and required `system_id`.
Access is validated with PAT token and ownership checks.

**Headers**:
```
Authorization: Bearer {pat_token}
```

**Path Parameter**:
- `id` (required, integer): Configuration file ID

**Query Parameters**:
- `system_id` (required, integer): System register ID associated with the configuration file

**Response** (200):
- Returns the file with appropriate Content-Type header
- File is downloaded with original filename

**Error Responses**:
- `401`: Unauthorized (invalid or missing PAT token)
- `404`: Configuration file not found for provided `id` and `system_id`

**Example**:
```bash
curl -X GET "http://localhost:8002/config-files/download/12?system_id=1093719686" \
  -H "Authorization: Bearer atgla-xPyt2TeLn3TbbalkBMN" \
  -o downloaded-app.config
```

---

### Get Configuration File Raw Data

**Endpoint**: `GET /config-files/{fileId}/raw-data`

**Description**: Get the raw file data stored in database for a configuration file

**Headers**:
```
Authorization: Bearer {pat_token}
```

**Response** (200):
```json
{
  "file_id": 1,
  "file_name": "app.config",
  "raw_data": {
    "id": 1,
    "file_data": "[database]\nhost=localhost\nport=3306\n...",
    "status": "active",
    "created_at": "2026-02-27T21:30:00.000000Z",
    "updated_at": "2026-02-27T21:30:00.000000Z"
  }
}
```

**Example**:
```bash
curl -X GET http://localhost:8002/config-files/1/raw-data \
  -H "Authorization: Bearer atgla-xPyt2TeLn3TbbalkBMN"
```

---

### Delete Configuration File

**Endpoint**: `DELETE /config-files/{fileId}`

**Description**: Soft delete a configuration file (marks as inactive, data preserved)

**Headers**:
```
Authorization: Bearer {pat_token}
```

**Response** (200):
```json
{
  "message": "Configuration file marked as inactive successfully",
  "file": {
    "id": 1,
    "file_name": "app.config",
    "status": "inactive",
    "updated_at": "2026-02-27T21:35:00.000000Z"
  }
}
```

**Behavior**:
- Marks both `configuration_files` and `raw_data` records as "inactive"
- Updates `updated_at` timestamp on both records
- File remains in file system and database
- File is no longer visible in list/read operations
- Returns 404 when trying to access deleted files

**Example**:
```bash
curl -X DELETE http://localhost:8002/config-files/1 \
  -H "Authorization: Bearer atgla-xPyt2TeLn3TbbalkBMN"
```

---

## File Operations

### Upload File (Generic)

**Endpoint**: `POST /files/upload`

**Description**: Upload a generic file with base64 or raw text data

**Headers**:
```
Authorization: Bearer {pat_token}
Content-Type: application/json
```

**Request Body**:
```json
{
  "file_name": "data.txt",
  "file_data": "Base64 encoded content or raw text"
}
```

**Response** (201):
```json
{
  "message": "File uploaded successfully",
  "file": {
    "id": 1,
    "file_name": "data.txt",
    "file_location": "uploads/1/uuid_data.txt",
    "created_at": "2026-02-27T21:40:00.000000Z"
  }
}
```

**Example**:
```bash
curl -X POST http://localhost:8002/files/upload \
  -H "Authorization: Bearer atgla-xPyt2TeLn3TbbalkBMN" \
  -H "Content-Type: application/json" \
  -d '{
    "file_name": "data.txt",
    "file_data": "Sample file content"
  }'
```

---

### Download File (Generic)

**Endpoint**: `GET /files/{fileId}`

**Description**: Retrieve file data by ID

**Headers**:
```
Authorization: Bearer {pat_token}
```

**Response** (200):
```json
{
  "file": {
    "id": 1,
    "file_name": "data.txt",
    "file_location": "uploads/1/uuid_data.txt",
    "file_data": "Sample file content",
    "created_at": "2026-02-27T21:40:00.000000Z",
    "updated_at": "2026-02-27T21:40:00.000000Z"
  }
}
```

**Example**:
```bash
curl -X GET http://localhost:8002/files/1 \
  -H "Authorization: Bearer atgla-xPyt2TeLn3TbbalkBMN"
```

---

## Error Responses

### 400 Bad Request
```json
{
  "message": "Validation error",
  "errors": {
    "field_name": ["Error message"]
  }
}
```

### 401 Unauthorized
```json
{
  "message": "Unauthenticated"
}
```

### 403 Forbidden
```json
{
  "message": "Unauthorized to perform this action"
}
```

### 404 Not Found
```json
{
  "message": "Resource not found"
}
```

### 429 Too Many Requests
```json
{
  "message": "Rate limit exceeded",
  "retry_after": 60
}
```

### 500 Internal Server Error
```json
{
  "error": "Internal server error",
  "message": "Error message"
}
```

---

## Authentication Notes

### Session Token (Temporary)
- Used for user authentication (login)
- Obtained via `POST /auth/login`
- Used with session-based endpoints
- Example: `Authorization: Bearer {session_token}`

### PAT Token (Permanent)
- Personal Access Token created via `POST /auth/pat-tokens`
- Prefixed with `atgla-` (e.g., `atgla-xPyt2TeLn3TbbalkBMN`)
- Used for API operations (files, system registration, etc.)
- Can have expiration date
- Example: `Authorization: Bearer atgla-xPyt2TeLn3TbbalkBMN`

---

## Rate Limiting

- **Default Limit**: 4 requests per minute
- **Applied To**: User and Product endpoints
- **Exceeded**: Returns 429 status code
- **Reset**: After 1 minute

---

## Data Storage

### Configuration Files
- **File System**: `storage/app/config_files/{user_id}/`
- **Service Master**: `services` table (service_id, service_name, system_id, system_hash, user_id, org_id, status, share_with)
- **Database**: `configuration_files` table (metadata + service_id + version)
- **Raw Data**: `raw_data` table (file content + service_id + version)
- **Save Order**: `services` -> `configuration_files` -> `raw_data`
- **Default Status**: `active`
- **Soft Delete**: Marked as `inactive` instead of permanent deletion

---

## Changelog

**Version 1.0** - February 28, 2026
- Initial API release
- All endpoints documented and tested
- Soft delete functionality for configuration files
- PAT token support
- System registration
- Configuration file management

**Version 1.1** - March 1, 2026
- Added validation_hash field to system_register table
- Added validation_hash field to configuration_files table
- Added validation_hash field to raw_data table
- New endpoint: GET /config-files/filter - Filter configs by system_id + validation_hash
- New endpoint: GET /config-files/download/{id} - Download with system_id validation
- Updated system registration to accept and persist validation_hash
- Updated config file upload to accept and persist validation_hash
- Enhanced security: download by ID now requires system_id validation
- Comprehensive API documentation reorganized by feature
- File storage location and database tables documentation

**Version 1.2** - March 7, 2026
- **Enhanced Login Response**: POST /auth/login now includes `org_id`, `rbac_id`, and `status` in user object
- **Token Validation Security**: Both GET and POST /auth/validate-token now check token status
  - Revoked or inactive tokens return 401 error
  - Added `org_id` field to user object in validation response
  - Middleware now validates token status before processing requests
- **System Registration Security**: POST /system-register now auto-resolves `org_id` server-side
  - Removed `org_id` from accepted request parameters
  - Organization ID populated from authenticated user context
  - Prevents client manipulation of organization assignment
- **New Admin Endpoint**: POST /system-deregister-force
  - Force deregister any system regardless of ownership
  - Requires session token authentication (not PAT)
  - Records deregistering user ID for audit trail
  - Intended for administrative/support operations

**Version 1.3** - March 7, 2026
- **New User Endpoint**: POST/GET /system-reactive
  - User-level endpoint to reactivate their own inactive systems
  - Requires PAT token authentication
  - Validates system ownership before reactivation
  - Returns 400 if system already active
- **New Admin Endpoints**: POST/GET /system-reactivate-force
  - Force reactivate any system regardless of ownership
  - Requires session token authentication (not PAT)
  - Records admin user ID who performed the reactivation
  - Intended for administrative/support use cases
- **New Admin API Section**: Dedicated section documenting all admin-level endpoints
  - Consolidated documentation for `/system-deregister-force` and `/system-reactivate-force`
  - Clear separation between user-level and admin-level APIs
  - Security notes for each admin endpoint
- **API Organization**: Reorganized documentation with explicit Admin APIs section
  - User operations: /system-reactive (PAT token)
  - Admin operations: /system-deregister-force, /system-reactivate-force (session token)

**Version 1.4** - March 8, 2026
- **Configuration Files Version Support**: Added `version` column to configuration_files table
  - New optional field: `version` (string, max 50 characters)
  - POST /config-files/upload now accepts version parameter
  - All configuration file list/filter endpoints return version in responses
  - Enables version tracking for configuration file management
  - Supports versioning strategies (semantic versioning, timestamps, etc.)

**Version 1.5** - March 10, 2026
- **Service-First Config Save Flow**: Added `services` table and linked flow for config persistence
  - New service APIs: `POST /services`, `GET /services`, `GET /services/{serviceId}`
  - `service_id` starts from 100
  - Added `service_id` column to `configuration_files` and `raw_data`
  - `POST /config-files/upload` now saves in sequence: `services` -> `configuration_files` -> `raw_data`
  - Upload accepts `org_id`, `system_hash`, and `share_with` when creating/reusing service
