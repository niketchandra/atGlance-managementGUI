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
12. [CLI API v1 (/api/v1)](#cli-api-v1-apiv1)
13. [Error Responses](#error-responses)
14. [Authentication Notes](#authentication-notes)
15. [Rate Limiting](#rate-limiting)
16. [Data Storage](#data-storage)
17. [Changelog](#changelog)

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

**Current behaviour**: this endpoint takes no bearer token (the session-token text below is out of date); the caller sends `email` + `password` or `pin`. Since October 2026: a deactivated account gets `401 Invalid credentials`; a non-admin gets `403`; an admin of another organisation gets the same `404 System not found` as an unknown id (a super admin may act on any organisation); and the route uses the `v1-force` rate limit (10/min per IP + email). See [Security fixes in the 1.x routes](#security-fixes-in-the-1x-routes-october-2026).

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

**Current behaviour**: this endpoint takes no bearer token (the session-token text below is out of date); the caller sends `email` + `password` or `pin`. Since October 2026: a deactivated account gets `401 Invalid credentials`; a non-admin gets `403`; an admin of another organisation gets the same `404 System not found` as an unknown id (a super admin may act on any organisation); and the route uses the `v1-force` rate limit (10/min per IP + email). See [Security fixes in the 1.x routes](#security-fixes-in-the-1x-routes-october-2026).

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

**Current behaviour**: this endpoint takes no bearer token (the session-token text below is out of date); the caller sends `email` + `password` or `pin`. Since October 2026: a deactivated account gets `401 Invalid credentials`; a non-admin gets `403`; an admin of another organisation gets the same `404 System not found` as an unknown id (a super admin may act on any organisation); and the route uses the `v1-force` rate limit (10/min per IP + email). See [Security fixes in the 1.x routes](#security-fixes-in-the-1x-routes-october-2026).

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

**Since October 2026**: a `system_id`/`system_register_id` of another organisation returns `404 {"message":"System not found"}`; teammates in the same organisation can still upload.

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

## CLI API v1 (/api/v1)

Versioned API for the atglance CLI v2. Every path starts with `/api/v1/` and is the same on the gateway (`http://localhost:8002/api/v1/...`) and on the app (`http://localhost:8000/api/v1/...`). The 1.x routes above keep working for 1.x hosts.

Responses are always JSON (validation errors, 404, 429) whether or not the client sends `Accept: application/json`; the one exception is the config file download, which returns the raw file.

### Headers

| Header | When | Value |
|---|---|---|
| `Authorization: Bearer atgla-...` | every route except `meta` and the two `*-force` routes | a PAT of the user |
| `X-Atglance-System-Id` | system-scoped routes (`systems/self*`, `config-files*`) | the system's id (from register) |
| `X-Atglance-System-Key` | system-scoped routes | the system's key (`system_key` from register; for a host registered by 1.x, the `validation_hash` it stored) |

System-scoped routes check, after the PAT:
- either header missing: `400 {"message":"X-Atglance-System-Id and X-Atglance-System-Key headers are required"}`
- unknown system, system without a key, wrong key, or a system of another organisation than the PAT's user: always the same `404 {"message":"System not found"}`, so the response does not reveal which systems exist
- the system's status is not checked, so an inactive system can call `reactivate`

Routes marked "active user" answer `403 {"message":"User is inactive"}` when the PAT's user is inactive. Every PAT route of v1 is marked so, reads included.

Path ids (`{id}`) and `X-Atglance-System-Id` are 1 to 18 digits; a longer or non-numeric id is a `404`.

### Rate limits

Named limiters, each with its own counter:
- `v1-meta`: `GET /api/v1/meta`, 60 requests per minute per IP.
- `v1-force`: the v1 force routes and the legacy `/system-deregister-force` and `/system-reactivate-force`, 10 requests per minute per IP and lower-cased `email`.

Behind Kong every request reaches the app from the gateway's IP, so all clients share the IP part of the key; for `v1-force` the email part is what separates callers, and `v1-meta` is effectively one counter for all clients of the gateway. Over the limit: `429` JSON.

### Endpoints

| Method and path | Auth | Purpose |
|---|---|---|
| `GET /api/v1/meta` | none (`v1-meta`, 60/min) | API and console version |
| `GET /api/v1/whoami` | PAT, active user | The PAT's user and token |
| `POST /api/v1/systems` | PAT, active user | Register a system |
| `GET /api/v1/systems/self` | PAT, active user, system | The calling system |
| `POST /api/v1/systems/self/deregister` | PAT, active user, system | Set the system inactive |
| `POST /api/v1/systems/self/reactivate` | PAT, active user, system | Set the system active |
| `POST /api/v1/systems/self/rotate-key` | PAT, active user, system | Replace the system key |
| `POST /api/v1/systems/{id}/deregister-force` | email + password/pin (`v1-force`, 10/min) | Admin force deregister |
| `POST /api/v1/systems/{id}/reactivate-force` | email + password/pin (`v1-force`, 10/min) | Admin force reactivate |
| `POST /api/v1/config-files` | PAT, active user, system | Upload a config backup |
| `GET /api/v1/config-files` | PAT, active user, system | List the system's config backups |
| `GET /api/v1/config-files/{id}` | PAT, active user, system | Download one config backup |

### GET /api/v1/meta

```bash
curl http://localhost:8002/api/v1/meta
```
```json
{"api": "atglance", "api_version": 1, "console_version": "2.0.0"}
```

### GET /api/v1/whoami

```bash
curl http://localhost:8002/api/v1/whoami -H "Authorization: Bearer atgla-xPyt2TeLn3TbbalkBMN"
```
```json
{
  "user": {"id": 1010, "name": "Jane Ops", "status": "active", "org_id": 200},
  "token": {"id": 7, "name": "web-01 cli", "expires_at": "2099-12-31T23:59:59+00:00"}
}
```
No email, date of birth or workspaces. Errors: `401` missing/invalid/expired PAT, `403` inactive user.

### POST /api/v1/systems

Body (JSON or form):
- `system_name` (required, string, max 255), `os_type` (required, max 100), `ip_address` (required, max 45)
- `distro`, `version` (max 100), `tags` (max 512), `metadata` (string): optional
- `workspace_id` (optional integer): when greater than 0 it must be a workspace of the user's organisation
- `system_key` (required, string, 32 to 255 characters): generated by the host and kept secret; stored as the system's `validation_hash`

```bash
curl -X POST http://localhost:8002/api/v1/systems \
  -H "Authorization: Bearer atgla-xPyt2TeLn3TbbalkBMN" -H "Content-Type: application/json" \
  -d '{"system_name":"web-01","os_type":"linux","ip_address":"10.0.0.5","distro":"ubuntu","version":"24.04","workspace_id":3,"system_key":"3f9c2b...64 hex chars..."}'
```
Response `201` (the key is never echoed):
```json
{"system": {"id": 4057010410, "system_name": "web-01", "status": "active", "workspace_id": 3, "created_at": "2026-10-11T09:30:00.000000Z"}}
```
Errors: `401` PAT, `403` inactive user, `422` validation (`system_key` missing or too short, `workspace_id` of another organisation, ...).

### GET /api/v1/systems/self

```bash
curl http://localhost:8002/api/v1/systems/self \
  -H "Authorization: Bearer atgla-xPyt2TeLn3TbbalkBMN" \
  -H "X-Atglance-System-Id: 4057010410" -H "X-Atglance-System-Key: 3f9c2b..."
```
```json
{
  "system": {
    "id": 4057010410, "system_name": "web-01", "status": "active",
    "workspace_id": 3, "workspace_name": "ansible",
    "ip_address": "10.0.0.5", "os_type": "linux", "distro": "ubuntu", "version": "24.04", "tags": "prod",
    "created_at": "2026-10-11T09:30:00.000000Z", "updated_at": "2026-10-11T09:30:00.000000Z"
  }
}
```

### POST /api/v1/systems/self/deregister and /reactivate

Body: `password` or `pin` of the PAT's user. Allowed for the system's owner or an admin of its organisation.

```bash
curl -X POST http://localhost:8002/api/v1/systems/self/deregister \
  -H "Authorization: Bearer atgla-xPyt2TeLn3TbbalkBMN" \
  -H "X-Atglance-System-Id: 4057010410" -H "X-Atglance-System-Key: 3f9c2b..." \
  -H "Content-Type: application/json" -d '{"password":"..."}'
```
```json
{"message": "System deregistered successfully", "system_id": 4057010410, "status": "inactive"}
```
Reactivate answers `{"message":"System reactivated successfully", ...,"status":"active"}`, or `200 {"message":"System is already active", ...}` when it already was.

Errors: `422 {"message":"Either password or pin is required"}` / `422 {"message":"Invalid password or pin"}`, `403 {"message":"Only the system owner or an admin of its organisation can change this system"}`, plus the header errors above.

### POST /api/v1/systems/self/rotate-key

Body: `password` or `pin`, and `new_key` (string, 32 to 255 characters, different from the current key). Same permission rule as deregister. The change is recorded in the activity log (`system.key_rotated`). In the same transaction the stored copies of the key are updated too: `validation_hash` of the system's `configuration_files` and `raw_data` rows that held the old key, and `system_hash` of the system's `services`, so legacy lookups by key (for example `GET /config-files/config-show`) keep finding the backups with the new key. Afterwards the old key gets `404` and the host must send the new key.

```bash
curl -X POST http://localhost:8002/api/v1/systems/self/rotate-key \
  -H "Authorization: Bearer atgla-xPyt2TeLn3TbbalkBMN" \
  -H "X-Atglance-System-Id: 4057010410" -H "X-Atglance-System-Key: 3f9c2b..." \
  -H "Content-Type: application/json" -d '{"pin":"1234","new_key":"a81d07...64 hex chars..."}'
```
```json
{"system_id": 4057010410, "rotated": true}
```
Errors: `422` credentials or `new_key` (missing, too short, same as current), `403` permission.

### POST /api/v1/systems/{id}/deregister-force and /reactivate-force

No bearer token. Body: `email` and `password` or `pin` of an admin. The admin must be active and belong to the system's organisation; a super admin may act on any organisation. Rate limit `v1-force` (10 per minute per IP + email, shared with the legacy force routes). The system id comes from the path only (1 to 18 digits).

```bash
curl -X POST http://localhost:8002/api/v1/systems/4057010410/deregister-force \
  -H "Content-Type: application/json" -d '{"email":"admin@example.com","password":"..."}'
```
```json
{"message": "System force deregistered successfully", "deregistered_by_user_id": 1011, "system_id": 4057010410, "system_user_id": 1010, "status": "inactive"}
```
Reactivate answers `{"message":"System force reactivated successfully","reactivated_by_user_id":...}` (or `"System is already active"`).

Errors: `422` email or password/pin missing; `401 {"message":"Invalid credentials"}` (unknown email, wrong password/pin, or a deactivated account); `403 {"message":"Only an admin of the system's organisation can force this action"}` (not an admin); `404 {"message":"System not found"}` (unknown id, or a system of another organisation for a non-super admin); `429` rate limit.

### POST /api/v1/config-files

Multipart: `file` (text/config file, max 10 MB) and `service_name`. The system comes from the headers; any `system_id`, `system_register_id` or `service_id` in the body is ignored. Versions are numbered per service (`v1`, `v2`, ...).

```bash
curl -X POST http://localhost:8002/api/v1/config-files \
  -H "Authorization: Bearer atgla-xPyt2TeLn3TbbalkBMN" \
  -H "X-Atglance-System-Id: 4057010410" -H "X-Atglance-System-Key: 3f9c2b..." \
  -F "file=@/etc/ssh/sshd_config" -F "service_name=ssh"
```
Response `201` (no hash, no storage paths):
```json
{"file": {"id": 2051, "file_name": "sshd_config", "service_name": "ssh", "version": "v3", "created_at": "2026-10-11T09:31:00.000000Z"}}
```
Errors: `422` (no file, wrong type, missing `service_name`), `403` inactive user, header errors above.

### GET /api/v1/config-files

Active config backups of the calling system, whoever uploaded them, newest first. Optional query `service_name`.

```bash
curl "http://localhost:8002/api/v1/config-files?service_name=ssh" \
  -H "Authorization: Bearer atgla-xPyt2TeLn3TbbalkBMN" \
  -H "X-Atglance-System-Id: 4057010410" -H "X-Atglance-System-Key: 3f9c2b..."
```
```json
{
  "files": [
    {"id": 2051, "file_name": "sshd_config", "service_name": "ssh", "version": "v3",
     "created_at": "2026-10-11T09:31:00.000000Z", "created_by": {"id": 1010, "name": "Jane Ops"}}
  ]
}
```

### GET /api/v1/config-files/{id}

Raw content of an active config backup of the calling system.

```bash
curl -OJ http://localhost:8002/api/v1/config-files/2051 \
  -H "Authorization: Bearer atgla-xPyt2TeLn3TbbalkBMN" \
  -H "X-Atglance-System-Id: 4057010410" -H "X-Atglance-System-Key: 3f9c2b..."
```
Response `200` with `Content-Type: application/octet-stream` and `Content-Disposition: attachment; filename=sshd_config`. Names that are not plain ASCII tokens get a quoted ASCII fallback plus a UTF-8 `filename*` (for example `attachment; filename=nginx-e.conf; filename*=utf-8''nginx-%C3%A9.conf`); control characters are dropped and `/` `\` replaced.

Errors: `404 {"message":"Configuration file not found"}` (unknown, inactive, or another system's file), `404 {"message":"File not found in storage"}`.

### Security fixes in the 1.x routes (October 2026)

Shipped with v1, because the code is shared:
- `POST/GET /system-deregister-force` and `/system-reactivate-force` (and the v1 force routes) now need an admin (`rbac_id` 100 or 101) of the system's organisation; a super admin may act on any organisation. Others get `403 {"message":"Only an admin of the system's organisation can force this action"}`. Before, any user with a valid email and password/PIN could force any system in any organisation.
- `POST /config-files/upload` with a `system_id`/`system_register_id` of another organisation now returns `404 {"message":"System not found"}` and stores nothing. Teammates in the same organisation can still upload.
- Force routes: a deactivated account gets `401 Invalid credentials`; an admin of another organisation gets `404 System not found` (as for an unknown id) instead of `403`; an unknown email costs the same bcrypt check as a known one; the routes use the `v1-force` rate limit (10/min per IP + email).
- `GET /config-files/config-show` returns only systems of the caller's organisation; for a system of another organisation the response is the usual shape with `total: 0` and `versions: []`.
- The activity log no longer stores `system_key`, `new_key`, `validation_hash` or `system_hash` from request payloads.

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

**CLI API v1** - October 11, 2026
- New versioned API under `/api/v1` for the atglance CLI v2 (see [CLI API v1](#cli-api-v1-apiv1))
- Force deregister/reactivate restricted to admins of the system's organisation (super admin: any)
- Config upload rejects systems of another organisation (404)
- Named rate limiters `v1-meta` and `v1-force` (force: per IP + email, also on the legacy force routes)
- Force: deactivated accounts rejected (401); other-organisation systems answer 404
- `GET /config-files/config-show` scoped to the caller's organisation
- Key rotation also updates the key stored on config files, raw data and services

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
