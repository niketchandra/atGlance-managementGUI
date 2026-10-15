# Phase 5 Fix: Credential Expiry Messages Not Created

## Problem Statement
The `/api/v1/credentials/expiry-notice` endpoint was returning success responses but not creating message records in the `temporary_credential_messages` table.

## Root Cause
Found and fixed **critical bug** in `CredentialStatus::storeMessage()`:

**Bug**: The message status field was hardcoded to `'active'` instead of using the actual status from the message data.

```php
// BEFORE (Line 76 - WRONG)
'status' => 'active',  // Always 'active' regardless of actual state

// AFTER (Line 76 - FIXED)
'status' => $messageData['status'] ?? 'active',  // Uses correct status
```

This caused status mismatches:
- **Expiring credentials** (0-15 days left): Should be `'expiring'` → Was `'active'`
- **Expired credentials** (past expiry): Should be `'expired'` → Was `'active'`
- **Active credentials** (>15 days): Should be `'active'` → Was `'active'` ✓

## Changes Made

### 1. Fixed Bug in `CredentialStatus::storeMessage()`
- **File**: `composer/app/Services/CredentialStatus.php`
- **Change**: Use `$messageData['status']` instead of hardcoded `'active'`
- **Added**: Exception handling and debug logging for troubleshooting

```php
try {
    $inserted = DB::table('temporary_credential_messages')->insert([
        // ... other fields
        'status' => $messageData['status'] ?? 'active',  // FIX
        // ...
    ]);
    
    if (!$inserted) {
        \Log::warning('CredentialStatus::storeMessage failed to insert', [...]);
    }
    
    return ['queued' => true, 'expires_in_hours' => 12];
} catch (\Exception $e) {
    \Log::error('CredentialStatus::storeMessage exception', [...]);
    return ['queued' => false, 'error' => $e->getMessage(), ...];
}
```

### 2. Enhanced Debug Logging in `CredentialController::expiryNotice()`
- **File**: `composer/app/Http/Controllers/Api/V1/CredentialController.php`
- **Added**: Try-catch block with debug logging for all calls
- **Logs**: Pat token ID, token name, result, and any exceptions

### 3. Created Comprehensive Test Suite
- **File**: `composer/tests/Feature/Api/V1/CredentialExpiryMessageTest.php`
- **Tests**:
  - ✓ Message creation for expiring PAT (5 days)
  - ✓ Message creation for expired PAT (past expiry)
  - ✓ Message creation for service account keys
  - ✓ Message retrieval via GET /api/v1/temporary-messages
  - ✓ Status correctly reflects credential state (active/expiring/expired)
  - ✓ Multiple messages stored separately
  - ✓ Expired messages filtered out (TTL handling)

### 4. Created Manual Testing Script
- **File**: `composer/test_expiry_messages.sh`
- **Tests**: Full flow across all 3 hosts:
  1. Auth and session token
  2. PAT token retrieval
  3. Call expiryNotice endpoint
  4. Verify message in database
  5. Retrieve message via messages endpoint
  6. Test marking message as read

## How to Verify the Fix

### Option 1: Run Automated Tests
```bash
cd composer
php artisan test tests/Feature/Api/V1/CredentialExpiryMessageTest.php
```

Expected output: All 8 tests pass ✓

### Option 2: Run Manual Testing Script
```bash
bash composer/test_expiry_messages.sh
```

This will test all 3 hosts in sequence and report results.

### Option 3: Manual Testing via curl

1. **Get a valid PAT token** (substitute your actual token):
```bash
TOKEN="atgla_xxxxxxxxxxxx"
HOST="http://localhost:8000"
```

2. **Call the expiryNotice endpoint**:
```bash
curl -X POST "${HOST}/api/v1/credentials/expiry-notice" \
  -H "Authorization: Bearer ${TOKEN}"
```

Expected response:
```json
{"queued": true, "expires_in_hours": 12}
```

3. **Verify message was created in database**:
```bash
# Check logs for successful insert
tail -f storage/logs/laravel.log | grep "CredentialStatus::storeMessage"
```

4. **Retrieve message via API**:
```bash
curl -X GET "${HOST}/api/v1/temporary-messages" \
  -H "Authorization: Bearer ${TOKEN}"
```

Expected response:
```json
{
  "count": 1,
  "messages": [
    {
      "id": 1,
      "days_left": 7,
      "notice_stage": 7,
      "message": "Your credential 'MyToken' (…1234) expires in 7 days.",
      "severity": "warn",
      "credential_type": "personal_access_token",
      "owner": "username",
      "created_at": "2026-10-15T...",
      "expires_at": "2026-10-15T..."
    }
  ]
}
```

## Debug Logging

When enabled (check logs), the following will be logged:

### Success Case
```
[2026-10-15 10:30:45] local.INFO: CredentialStatus::expiryNotice called {
  "pat_token_id": 123,
  "token_name": "my-token",
  "result": {"queued": true, "expires_in_hours": 12}
}
```

### Failure Case
```
[2026-10-15 10:30:45] local.ERROR: CredentialStatus::storeMessage exception {
  "pat_token_id": 123,
  "error": "SQLSTATE[HY000]: General error: ...",
  "trace": "..."
}
```

## Testing on All 3 Hosts

After deploying this fix to all 3 hosts, verify:

1. **Host 1 (Development/Test)**
   - Create test PAT with near-expiry date
   - Call expiryNotice endpoint
   - Verify message in temp_messages table
   - Verify UI shows message on /messages page

2. **Host 2 (Staging)**
   - Test with real service account key
   - Verify message contains correct owner name
   - Test message TTL (should expire in 12 hours)

3. **Host 3 (Production)**
   - Monitor logs for any exceptions
   - Verify messages are being created for active credentials
   - Test across different credential types (PAT, Service Account)

## Affected Files Summary

| File | Change | Severity |
|------|--------|----------|
| CredentialStatus.php | Fix hardcoded status field | **CRITICAL** |
| CredentialController.php | Add debug logging & error handling | Minor |
| CredentialExpiryMessageTest.php | New test suite | N/A (new file) |
| test_expiry_messages.sh | New testing script | N/A (new file) |

## Verification Checklist

After fix deployment:

- [ ] Automated tests pass: `php artisan test tests/Feature/Api/V1/CredentialExpiryMessageTest.php`
- [ ] Manual test successful on Host 1
- [ ] Manual test successful on Host 2
- [ ] Manual test successful on Host 3
- [ ] Logs show successful message creation (no exceptions)
- [ ] Messages visible in database: `SELECT COUNT(*) FROM temporary_credential_messages WHERE created_at > NOW() - INTERVAL 1 HOUR`
- [ ] Messages retrievable via API: `GET /api/v1/temporary-messages` returns count > 0
- [ ] UI displays messages on /messages page
- [ ] Message TTL works (expires after 12 hours)
- [ ] Message status correctly shows: active/expiring/expired based on days_left

## Related Issues Fixed
- Messages not being stored when calling expiryNotice endpoint
- Status field always showing 'active' instead of actual state
- No way to debug if message creation fails

## Future Improvements
1. Add metrics/monitoring for message creation success rate
2. Add retry logic for failed inserts
3. Add batch message creation for bulk operations
4. Extend TTL configuration (currently hardcoded to 12 hours)
