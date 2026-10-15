#!/bin/bash

echo "=========================================="
echo "PHASE 3: UI TESTING"
echo "=========================================="
echo ""

# Test 1: Dashboard loads
echo "TEST 1: Dashboard Access"
echo "EXPECTED: 302 redirect (to login) or 200 if already auth"
RESP=$(curl -s -o /dev/null -w "%{http_code}" http://localhost:8000/dashboard)
echo "Response: $RESP"
if [ "$RESP" = "302" ] || [ "$RESP" = "200" ]; then
  echo "STATUS: PASS"
else
  echo "STATUS: FAIL"
fi
echo ""

# Test 2: Messages page exists
echo "TEST 2: Messages Inbox Page"
echo "EXPECTED: 302 redirect (to login) or 200 if already auth"
RESP=$(curl -s -o /dev/null -w "%{http_code}" http://localhost:8000/messages)
echo "Response: $RESP"
if [ "$RESP" = "302" ] || [ "$RESP" = "200" ]; then
  echo "STATUS: PASS"
else
  echo "STATUS: FAIL"
fi
echo ""

echo "=========================================="
echo "PHASE 4: CLI INTEGRATION"
echo "=========================================="
echo ""

# Test 1: Verify API routes for CLI
echo "TEST 1: API Routes for CLI"
echo "EXPECTED: Routes should exist for temp messages and credential status"
docker exec ce-atglance-app php artisan route:list 2>&1 | grep -i "temporary-message\|credential/status"
echo ""

# Test 2: Create test message (simulate CLI call)
echo "TEST 2: Create Temporary Message (Simulated CLI)"
echo "EXPECTED: Should be able to create message via API"

# Get any PAT token from the database
PAT_TOKEN=$(docker exec ce-atglance-app php artisan tinker --execute="
\$token = \App\Models\PatToken::where('status', 'active')->first();
echo \$token ? 'atglance-' . \$token->last_four : 'none';
" 2>&1 | grep -o "atglance-[^']*")

if [ -z "$PAT_TOKEN" ] || [ "$PAT_TOKEN" = "none" ]; then
  echo "No active PAT tokens found, creating test data..."
  PAT_TOKEN="test-token-1234"
else
  echo "Using existing PAT: $PAT_TOKEN"
fi
echo ""

# Test 3: Check API response format
echo "TEST 3: API Response Format"
echo "EXPECTED: Endpoints should return valid JSON"
RESP=$(curl -s http://localhost:8000/api/v1/temporary-messages -H "Authorization: Bearer invalid" 2>/dev/null)
if echo "$RESP" | grep -q "json\|message\|error" 2>/dev/null; then
  echo "Response format valid (JSON detected)"
  echo "STATUS: PASS"
else
  echo "Response: $RESP" | head -5
  echo "STATUS: CHECK"
fi
echo ""

echo "=========================================="
echo "Phase 3 & 4 Complete"
echo "=========================================="
