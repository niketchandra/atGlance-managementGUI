#!/bin/bash

# Phase 1: Console Deployment Tests
echo "=========================================="
echo "PHASE 1: CONSOLE DEPLOYMENT TESTING"
echo "=========================================="
echo ""

# Test 1: Database migrations
echo "TEST 1: Database Migrations"
echo "EXPECTED: All migrations completed successfully"
docker exec ce-atglance-app php artisan migrate:status 2>&1 | tail -5
echo ""

# Test 2: Temporary messages table exists
echo "TEST 2: Temporary Credential Messages Table"
echo "EXPECTED: Table exists with correct structure"
docker exec ce-atglance-app php artisan tinker --execute="
\$tables = \DB::select('SHOW TABLES LIKE \"temporary_credential_messages\"');
if (!empty(\$tables)) {
    echo '[OK] Table exists' . PHP_EOL;
    \$cols = \DB::select('DESCRIBE temporary_credential_messages');
    foreach (\$cols as \$col) {
        echo '  - ' . \$col->Field . ': ' . \$col->Type . PHP_EOL;
    }
} else {
    echo '[FAIL] Table not found' . PHP_EOL;
}
" 2>&1 | grep -v "Tinker v"
echo ""

# Test 3: Check personal_access_tokens table expiry default
echo "TEST 3: Token Expiry Default"
echo "EXPECTED: Should have reasonable default (90 days, not 2099)"
docker exec ce-atglance-app php artisan tinker --execute="
\$cols = \DB::select('DESCRIBE personal_access_tokens');
foreach (\$cols as \$col) {
    if (\$col->Field === 'expires_at') {
        echo 'Column: ' . \$col->Field . PHP_EOL;
        echo 'Type: ' . \$col->Type . PHP_EOL;
        echo 'Default: ' . \$col->Default . PHP_EOL;
        echo 'Null: ' . \$col->Null . PHP_EOL;
    }
}
" 2>&1 | grep -v "Tinker v"
echo ""

# Test 4: Check for cleanup command
echo "TEST 4: Message Cleanup Command"
echo "EXPECTED: Cleanup command should be defined"
docker exec ce-atglance-app php artisan list 2>&1 | grep -i "cleanup\|message"
echo ""

# Test 5: Dashboard loads
echo "TEST 5: Web Routes - Dashboard"
echo "EXPECTED: Dashboard responds with 302 (redirect to login)"
curl -s -o /dev/null -w "HTTP Status: %{http_code}\n" http://localhost:8000/dashboard
echo ""

# Test 6: Messages route exists
echo "TEST 6: Web Routes - Messages Inbox"
echo "EXPECTED: /messages route exists"
curl -s -o /dev/null -w "HTTP Status: %{http_code}\n" http://localhost:8000/messages
echo ""

echo "=========================================="
echo "Phase 1 Complete"
echo "=========================================="
