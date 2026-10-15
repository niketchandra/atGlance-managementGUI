# Quick Test Guide: Phase 5 Credential Expiry Messages

## The Fix (TL;DR)
Fixed critical bug where message `status` field was hardcoded to `'active'` instead of using actual credential state. Now messages correctly show as:
- `active` - credential valid (>15 days)
- `expiring` - credential expiring soon (0-15 days)  
- `expired` - credential has expired

## Quick Verification (5 minutes)

### 1. Run Tests
```bash
cd composer
php artisan test tests/Feature/Api/V1/CredentialExpiryMessageTest.php
```
✓ All 8 tests should pass

### 2. Quick Manual Test
```bash
# Start app on localhost:8000
# Get your PAT token from dashboard
TOKEN="atgla_xxxxxxxxxxxx"

# Call endpoint
curl -X POST "http://localhost:8000/api/v1/credentials/expiry-notice" \
  -H "Authorization: Bearer ${TOKEN}"
# Response: {"queued": true, "expires_in_hours": 12}

# Check message was created
curl -X GET "http://localhost:8000/api/v1/temporary-messages" \
  -H "Authorization: Bearer ${TOKEN}" | jq '.count'
# Response: count should be >= 1
```

### 3. Check Logs
```bash
# Look for successful message creation
tail -f composer/storage/logs/laravel.log | grep -i "CredentialStatus"

# Should see:
# [INFO] CredentialStatus::expiryNotice called
# [WARNING/ERROR] Only if there were actual problems
```

## Full Testing (15 minutes across 3 hosts)

### Automated Script (All hosts)
```bash
bash composer/test_expiry_messages.sh
```
Tests each host completely:
1. Authentication
2. PAT token retrieval
3. Call expiryNotice
4. Verify in database
5. Retrieve via messages API
6. Mark as read

### Manual Host Testing
Repeat for each host (host1, host2, host3):

```bash
# 1. SSH to host
ssh admin@host1

# 2. Verify messages in database
mysql -u root -p atglance -e \
  "SELECT id, status, days_left, severity, created_at FROM temporary_credential_messages ORDER BY created_at DESC LIMIT 5;"

# 3. Check for any errors in logs
tail -100 /var/log/atglance/laravel.log | grep -i "error\|exception"

# 4. Test with curl
TOKEN="atgla_xxxxxxxxxxxx"  # Get valid token for this host
curl -X POST "http://localhost:8000/api/v1/credentials/expiry-notice" \
  -H "Authorization: Bearer ${TOKEN}" | jq '.'
```

## Expected Results

### Message in Database
```sql
mysql> SELECT * FROM temporary_credential_messages WHERE id = 1\G
                     id: 1
         pat_token_id: 123
              user_id: 5
    service_account_id: NULL
              status: expiring          ← NOW CORRECT (was always 'active')
          days_left: 7
        notice_stage: 7
            message: Your credential 'my-token' (…1234) expires in 7 days.
           severity: warn
    credential_type: personal_access_token
               owner: john.doe
          expires_at: 2026-10-15 22:30:00
         created_at: 2026-10-15 10:30:00
         updated_at: 2026-10-15 10:30:00
```

### API Response
```bash
curl -X GET "http://localhost:8000/api/v1/temporary-messages" \
  -H "Authorization: Bearer atgla_token" | jq '.'

{
  "count": 1,
  "messages": [
    {
      "id": 1,
      "days_left": 7,
      "notice_stage": 7,
      "message": "Your credential 'my-token' (…1234) expires in 7 days.",
      "severity": "warn",
      "credential_type": "personal_access_token",
      "owner": "john.doe",
      "created_at": "2026-10-15T10:30:00.000000Z",
      "expires_at": "2026-10-15T22:30:00.000000Z"
    }
  ]
}
```

## Status Field Verification

Test each status type:

### Active Status (>15 days)
- Create token expiring in 30 days
- Call expiryNotice
- Check: `status` = `active`, `severity` = `info`

### Expiring Status (0-15 days)
- Create token expiring in 5 days
- Call expiryNotice
- Check: `status` = `expiring`, `severity` = `warn` or `error`

### Expired Status (<0 days)
- Create token already expired
- Call expiryNotice
- Check: `status` = `expired`, `severity` = `error`

## Troubleshooting

### Messages Not Appearing
1. Check logs: `tail -f storage/logs/laravel.log | grep -i "credentialstatus"`
2. Verify API response: `curl -X POST /api/v1/credentials/expiry-notice ...`
3. Check for exceptions: `grep -i "exception\|error" storage/logs/laravel.log`
4. Verify table exists: `mysql> DESCRIBE temporary_credential_messages;`

### Wrong Status Field
- ✓ FIXED in this commit (was hardcoded to 'active' before)
- Run tests to verify: `php artisan test tests/Feature/Api/V1/CredentialExpiryMessageTest.php`

### Database Errors
- Check for constraint violations: `mysql> SHOW CREATE TABLE temporary_credential_messages;`
- Verify foreign keys exist
- Check `pat_tokens` table has the token with correct `id`

## Files Changed
- `composer/app/Services/CredentialStatus.php` - Fixed bug + logging
- `composer/app/Http/Controllers/Api/V1/CredentialController.php` - Added logging
- `composer/tests/Feature/Api/V1/CredentialExpiryMessageTest.php` - New test suite
- `composer/test_expiry_messages.sh` - New manual test script
- `PHASE_5_EXPIRY_MESSAGE_FIX.md` - Detailed fix documentation

## Sign-Off Checklist
After testing:
- [ ] Tests pass on local
- [ ] Messages appear in database on Host 1
- [ ] Messages visible via API on Host 1
- [ ] Status field correct (not always 'active')
- [ ] Messages appear in database on Host 2
- [ ] Messages visible via API on Host 2
- [ ] Messages appear in database on Host 3
- [ ] Messages visible via API on Host 3
- [ ] No errors in logs (check for exceptions)
- [ ] UI displays messages properly on /messages page
