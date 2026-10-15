#!/bin/bash

echo "=========================================="
echo "VERIFICATION: Critical Fixes Applied"
echo "=========================================="
echo ""

# Test 1: Verify token expiry default changed
echo "TEST 1: Token Expiry Default (CRIT-001)"
echo "EXPECTED: Default should be NULL (not 2099)"
docker exec ce-atglance-app php artisan tinker --execute="
\$cols = \DB::select('DESCRIBE personal_access_tokens');
foreach (\$cols as \$col) {
    if (\$col->Field === 'expires_at') {
        echo 'Status: ';
        if (\$col->Default === null) {
            echo '[FIXED] Default is NULL' . PHP_EOL;
        } else {
            echo '[FAIL] Default is still: ' . \$col->Default . PHP_EOL;
        }
    }
}
" 2>&1 | grep -E "Status:|Default"
echo ""

# Test 2: Verify cleanup command exists
echo "TEST 2: Message Cleanup Command (CRIT-002)"
echo "EXPECTED: Command 'messages:cleanup-expired' should be available"
docker exec ce-atglance-app php artisan list 2>&1 | grep -i "cleanup-expired"
if [ $? -eq 0 ]; then
  echo "[FIXED] Command found"
else
  echo "[FAIL] Command not found"
fi
echo ""

# Test 3: Verify schedule includes cleanup
echo "TEST 3: Message Cleanup Scheduled"
echo "EXPECTED: Schedule should include messages:cleanup-expired hourly"
docker exec ce-atglance-app php artisan schedule:list 2>&1 | grep -i "cleanup-expired"
if [ $? -eq 0 ]; then
  echo "[FIXED] Scheduled task registered"
else
  echo "[FAIL] Scheduled task not registered"
fi
echo ""

# Test 4: Run cleanup command manually
echo "TEST 4: Run Cleanup Command Manually"
echo "EXPECTED: Command should execute without errors"
docker exec ce-atglance-app php artisan messages:cleanup-expired 2>&1
echo ""

echo "=========================================="
echo "Fix Verification Complete"
echo "=========================================="
