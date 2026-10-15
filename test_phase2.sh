#!/bin/bash

echo "=========================================="
echo "PHASE 2: API ENDPOINT TESTING"
echo "=========================================="
echo ""

# Create a test user and get auth token
echo "Setting up test credentials..."
TEST_EMAIL="test@example.com"
TEST_PASS="Test1234!@#"

# Try to login (may fail if user doesn't exist, that's ok)
TOKEN_RESPONSE=$(curl -s -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d "{\"email\":\"admin@example.com\",\"password\":\"password\"}" 2>/dev/null)

# Extract token if present
AUTH_TOKEN=$(echo $TOKEN_RESPONSE | grep -o '"token":"[^"]*' | cut -d'"' -f4)

if [ -z "$AUTH_TOKEN" ]; then
  echo "WARNING: Could not get auth token, using anonymous testing"
  echo "Response: $TOKEN_RESPONSE" | head -5
  echo ""
else
  echo "Auth Token: ${AUTH_TOKEN:0:20}..."
  echo ""
fi

# Test 1: Login endpoint
echo "TEST 1: POST /api/auth/login"
echo "EXPECTED: 200 OK with token (or 422 with validation error)"
curl -s -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@example.com","password":"testpass"}' \
  -w "\nHTTP Status: %{http_code}\n" 2>/dev/null | head -5
echo ""

# Test 2: Temporary messages endpoint (without auth)
echo "TEST 2: GET /api/v1/temporary-messages (no auth)"
echo "EXPECTED: 401 Unauthorized"
curl -s -o /dev/null -w "HTTP Status: %{http_code}\n" http://localhost:8000/api/v1/temporary-messages
echo ""

# Test 3: Check if routes are registered
echo "TEST 3: API Route Registration"
echo "EXPECTED: Routes for messages API should exist"
docker exec ce-atglance-app php artisan route:list 2>&1 | grep -i "temporary-message\|credential"
echo ""

# Test 4: Check database has any data
echo "TEST 4: Database State"
echo "EXPECTED: Check temporary_credential_messages table"
docker exec ce-atglance-app php artisan tinker --execute="
echo 'Temporary Messages Count: ' . \DB::table('temporary_credential_messages')->count();
echo 'Personal Access Tokens Count: ' . \DB::table('personal_access_tokens')->count();
echo 'Users Count: ' . \DB::table('users')->count();
" 2>&1 | grep -E "Count|error"
echo ""

echo "=========================================="
echo "Phase 2 Complete"
echo "=========================================="
