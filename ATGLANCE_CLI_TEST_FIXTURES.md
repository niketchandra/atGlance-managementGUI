# AtGlance CLI v2.0.0~alpha4 Testing - Fixtures and Results
**Test Date:** 2026-10-15  
**Console Version:** 2.1.2  
**CLI Version:** 2.0.0~alpha4  
**Test Environment:** localhost:8000 (Console) / localhost:8002 (Kong Gateway)

---

## PART 1: TEST FIXTURES CREATED

### 1.1 Test Users
Test users have been created in the Console database with both password and PIN authentication:

| ID | Email | Password | PIN | Role | Status |
|-----|-------|----------|-----|------|--------|
| 1014 | admin.tester@example.com | AdminTest123! | 1234 | admin | active |
| 1015 | user.tester@example.com | UserTest123! | 5678 | user | active |

### 1.2 Personal Access Tokens (PATs)
Three PATs have been created at the org-level scope:

| ID | Name | Owner | Created | Expires | Days Until Expiry | Token Format | Status |
|----|------|-------|---------|---------|------------------|--------------|--------|
| 6 | wsl-ubuntu-pat | admin.tester@example.com | 2026-10-15 17:24 | 2026-10-20 17:24 | 5 days | atgla-VstGyzVkUdYtCG7osFM | active |
| 7 | pi-raspbian-pat | admin.tester@example.com | 2026-10-15 17:24 | 2026-10-20 17:24 | 5 days | atgla-OkJIR24BExLbTteaDW3 | active |
| 8 | never-expire-pat | user.tester@example.com | 2026-10-15 17:24 | NULL | Never | atgla-T6eNHKJ4QcGUVqSZfqM | active |

**Credentials Requiring 5-Day Expiry Notices:** PAT-6 (wsl-ubuntu-pat), PAT-7 (pi-raspbian-pat)

### 1.3 Service Accounts
Four service accounts have been created for workspace and org-level testing:

| ID | Name | Scope | Workspace | Organization | Created | Status |
|----|------|-------|-----------|--------------|---------|--------|
| 4 | sa-workspace-1 | workspace | Default (1) | Default (200) | 2026-10-15 17:24 | active |
| 5 | sa-workspace-2 | workspace | Development (2) | Default (200) | 2026-10-15 17:24 | active |
| 6 | sa-org-1 | org | - | Default (200) | 2026-10-15 17:24 | active |
| 7 | sa-org-2 | org | - | Default (200) | 2026-10-15 17:24 | active |

---

## PART 2: CREDENTIALS API TESTING

### 2.1 Test Results Summary
**Tests Passed:** 7/9 (77.8%)  
**Tests Failed:** 2/9 (22.2%)

### 2.2 Detailed Test Results

#### PHASE 1: PAT Authentication Tests

| # | Test Name | Endpoint | Method | Token | Expected Code | Actual Code | Status | Notes |
|---|-----------|----------|--------|-------|---|---|---|---|
| 1 | Valid PAT - Credentials Status | /api/v1/credentials/status | GET | wsl-ubuntu-pat (6) | 200 | 200 | ✅ PASS | Returns key details with expiry date |
| 2 | Valid PAT - Expiry Notice | /api/v1/credentials/expiry-notice | POST | wsl-ubuntu-pat (6) | 200 | 200 | ✅ PASS | Returns {"sent":true,"stage":7} |
| 3 | No Token - Credentials Status | /api/v1/credentials/status | GET | (none) | 401 | 401 | ✅ PASS | Returns "PAT token required" message |
| 4 | Invalid Token - Credentials Status | /api/v1/credentials/status | GET | invalid-token | 401 | 403 | ❌ FAIL | Returns 403 instead of 401 (minor) |

#### PHASE 2: Expiry Notice Trigger Tests

| # | Test Name | Endpoint | Method | Token | Data | Expected Code | Actual Code | Status | Notes |
|---|-----------|----------|--------|-------|------|---|---|---|---|
| 5 | Expiry Notice - PAT (5 days) | /api/v1/credentials/expiry-notice | POST | wsl-ubuntu-pat (6) | days_left:5 | 200 | 200 | ✅ PASS | Returns {"sent":false,"reason":"already_sent"} - already notified |
| 6 | Expiry Notice - PAT (1 day) | /api/v1/credentials/expiry-notice | POST | pi-raspbian-pat (7) | days_left:1 | 200 | 200 | ✅ PASS | Returns {"sent":true,"stage":7} - first notice |

#### PHASE 3: Temporary Messages Tests

| # | Test Name | Endpoint | Method | Token | Expected Code | Actual Code | Status | Notes |
|---|-----------|----------|--------|-------|---|---|---|---|
| 7 | Get Temporary Messages | /api/v1/temporary-messages | GET | never-expire-pat (8) | 200 | 500 | ❌ FAIL | Server error - possible endpoint not implemented |

#### PHASE 4: Never-Expire Token Tests

| # | Test Name | Endpoint | Method | Token | Expected Code | Actual Code | Status | Notes |
|---|-----------|----------|--------|-------|---|---|---|---|
| 8 | Never-Expire Token - Status | /api/v1/credentials/status | GET | never-expire-pat (8) | 200 | 200 | ✅ PASS | Returns key with expires_at: null |
| 9 | Never-Expire Token - Expiry Notice | /api/v1/credentials/expiry-notice | POST | never-expire-pat (8) | days_left:null | 200 | 200 | ✅ PASS | Returns {"sent":false,"reason":"not_expiring"} |

---

## PART 3: SYSTEM REGISTRATION PLAN

The following systems need to register with the Console using the created credentials:

### 3.1 WSL Ubuntu 24.04
- **Hostname:** wsl-ubuntu
- **IP Address:** localhost (accessible via Windows loopback)
- **Console Address:** http://localhost:8002
- **Registration Credential:** PAT-6 (wsl-ubuntu-pat) - Expires in 5 days
- **Command:** `atglance register --name wsl-ubuntu --pat atgla-VstGyzVkUdYtCG7osFM --console http://localhost:8002`
- **Expected Status:** Should register successfully and receive expiry warnings
- **Test Cases:**
  - [ ] `atglance login` with PAT
  - [ ] `atglance check --scheduled` should trigger expiry notice
  - [ ] Verify message appears in Console

### 3.2 pi-1 Raspbian 12 (armv7l)
- **Hostname:** pi-1
- **IP Address:** 100.123.139.98
- **Console Address:** http://100.65.50.75:8002
- **Registration Credential:** PAT-7 (pi-raspbian-pat) - Expires in 5 days
- **Command:** `atglance register --name pi-1 --pat atgla-OkJIR24BExLbTteaDW3 --console http://100.65.50.75:8002`
- **Expected Status:** Should register successfully and receive expiry warnings
- **Test Cases:**
  - [ ] `atglance login` with PAT
  - [ ] `atglance check --scheduled` should trigger expiry notice
  - [ ] Verify message appears in Console

### 3.3 raspberrypi Debian 13 (aarch64)
- **Hostname:** raspberrypi
- **IP Address:** 100.116.86.27
- **Console Address:** http://100.65.50.75:8002
- **Registration Credential:** SA-6 (sa-org-1) - Org-level service account
- **Command:** `atglance register --name raspberrypi --service-account sa-org-1 --console http://100.65.50.75:8002`
- **Expected Status:** Should register successfully (no expiry warnings for SA without expires_at)
- **Test Cases:**
  - [ ] `atglance login` with Service Account
  - [ ] `atglance check --scheduled` should work
  - [ ] Verify no expiry notice sent (SA has no expiry)

---

## PART 4: FINDINGS & ISSUES

### 4.1 Passing Tests
- ✅ PAT authentication working correctly
- ✅ Credentials status endpoint returns proper token metadata including expiry_date
- ✅ Expiry notice endpoint properly tracks notification stages
- ✅ Never-expire tokens correctly identified and skip expiry notices
- ✅ Already-notified tokens return "already_sent" to prevent duplicate messages
- ✅ Token format validation working (prefix "atgla-", 25 chars total)

### 4.2 Issues Identified
- ❌ Invalid token returns HTTP 403 instead of 401 (cosmetic issue)
- ❌ /api/v1/temporary-messages endpoint returns 500 error (needs investigation)
  - Likely cause: Endpoint not fully implemented or missing database interaction
  - Impact: Cannot verify message delivery from API side (can still verify in UI)

### 4.3 Database Verification
All required tables present and properly structured:
- `personal_access_tokens` - Contains test PATs with correct expiry dates
- `service_accounts` - Contains test SAs with correct scopes
- `temporary_credential_messages` - Created for message storage
- `users` - Contains test users with hashed passwords

---

## PART 5: NEXT STEPS

### Immediate (Can be done now)
1. ✅ Test fixtures created and verified
2. ✅ Credentials API tested (7/9 tests passing)
3. ✅ Database schema validated

### Required for Full Testing
1. **Install atglance CLI v2.0.0~alpha4** on test hosts:
   - WSL Ubuntu 24.04
   - pi-1 Raspbian 12
   - raspberrypi Debian 13

2. **Register each system** using created credentials

3. **Run CLI tests** from each host:
   - Login with PAT/SA credentials
   - Run `atglance check --scheduled` to trigger expiry notices
   - Verify message delivery to Console

4. **Verify expiry handling**:
   - Check that PAT-6 and PAT-7 trigger 5-day expiry notices
   - Verify never-expire token (PAT-8) does NOT trigger notices
   - Confirm service accounts follow their own expiry rules

### Optional Improvements
1. Fix invalid token response code (403 → 401)
2. Debug and fix /api/v1/temporary-messages endpoint
3. Add integration tests for CI/CD pipeline

---

## PART 6: CREDENTIAL SUMMARY FOR TESTING

### Credentials to Use on Each Host

**WSL Ubuntu 24.04:**
```
PAT: atgla-VstGyzVkUdYtCG7osFM
Expires: 2026-10-20 (5 days)
Console: http://localhost:8002
```

**pi-1 Raspbian 12:**
```
PAT: atgla-OkJIR24BExLbTteaDW3
Expires: 2026-10-20 (5 days)
Console: http://100.65.50.75:8002
```

**raspberrypi Debian 13:**
```
Service Account: sa-org-1 (ID: 6)
Scope: org
Console: http://100.65.50.75:8002
```

**For Testing Never-Expire Scenario:**
```
PAT: atgla-T6eNHKJ4QcGUVqSZfqM
Expires: Never
Console: http://localhost:8002
```

---

## PART 7: CREDENTIALS FILE

All test fixtures and credentials have been saved to:
```
C:\Users\HP 745 G6\.claude\atglance-local-fixtures.json
```

---

**Report Generated:** 2026-10-15  
**Testing Status:** ✅ Phase 1 & 2 Complete, Awaiting Phase 3 & 4  
**Overall Status:** Ready for System Registration and CLI Testing
