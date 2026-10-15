# AtGlance 2.1.2 - Deployment & Testing Plan

**Date:** 2026-10-15
**Environment:** Console v2.1.2 + CLI v2.0.0~alpha9
**Status:** Ready for testing

---

## Version Status

| Component | Current | Target | Status |
|-----------|---------|--------|--------|
| Console | 2.1.2 | 2.1.2 | ✓ Ready |
| CLI | 2.0.0~alpha9 | 2.0.0~alpha9 | ✓ Latest |
| Database | 2.1.2 schema | 2.1.2 schema | ✓ Migrated |

**Note:** CLI is not yet versioned to 2.1.2. New features (temp messages API) are in Console only.

---

## Multi-Host Testing Strategy

### Scenario 1: Single Host with PAT
**Goal:** Verify basic workflow
**Setup:**
1. Register 1 system with 1 PAT
2. Run `atglance check --scheduled`
3. Verify message creation via API

**Expected Results:**
```bash
System: Linux VM1 (192.168.1.10)
PAT: atglance-test-xxxxx
Console API: localhost:8000

1. atglance register --pat {token} --host localhost:8000
   ✓ System registered (ID: 1001)
   
2. atglance check --scheduled
   ✓ Credential status: Valid, 89 days left
   ✓ Message queued to /api/v1/credentials/expiry-notice
   
3. GET /api/v1/temporary-messages
   ✓ Message visible in console (expires in 12hrs)
```

**Hosts Needed:** 1 Linux VM + Console

---

### Scenario 2: Multiple Systems (10 Systems)
**Goal:** Verify multi-host coordination
**Setup:**
1. Register 1 PAT on 10 different systems
2. Collect system info (OS, IP, distro)
3. Run checks simultaneously
4. Monitor API response times

**Systems:**
```
VM1-10: Ubuntu 22.04 (192.168.1.10-19)
Credentials: All use same PAT (atglance-test-xxxxx)
Expected: 10 identical messages queued
```

**Test Commands:**
```bash
# Register all systems
for i in {1..10}; do
  ssh vm$i "atglance register --pat atglance-test-xxxxx --host console.local"
done

# Trigger checks
for i in {1..10}; do
  ssh vm$i "atglance check --scheduled" &
done

# Monitor API
watch 'curl -s http://console:8000/api/v1/temporary-messages | jq ".count"'
```

**Expected Results:**
- 10 API calls within 5 seconds
- All 10 messages created (identical content)
- No 429 (rate limit) responses
- API response time < 200ms per request
- Database transaction speed normal

**Performance Metrics to Record:**
- API call latency (min/max/avg)
- Database query time
- Message queue depth
- Concurrent connection count

**Hosts Needed:** 10 Linux VMs + Console

---

### Scenario 3: 100 Server Load Test
**Goal:** Verify scalability
**Setup:**
1. Simulate 100 systems checking same PAT
2. Monitor system resources
3. Identify bottlenecks

**Load Test Pattern:**
```
Wave 1: 10 systems → 2-second stagger
Wave 2: 20 systems → 2-second stagger
Wave 3: 30 systems → 2-second stagger
Wave 4: 40 systems → 2-second stagger
Total: 100 concurrent checks
```

**Monitoring:**
- Database CPU usage
- Database memory usage
- API response times
- Message table growth rate
- Redis connection pool (if used)

**Expected Behavior:**
```
✓ API handles 100 requests/sec
✓ No timeout (response < 5 seconds)
✓ No database locks
✓ 100 identical messages created
✗ Or identify bottleneck for remediation
```

**Hosts Needed:** 100 Linux systems or cloud simulation (LocalStack, Docker)

---

## API Workflow Testing

### Workflow 1: User Journey (Admin)
**Test:** Admin logs in, views messages, dismisses

```
1. POST /api/auth/login (admin@example.com)
   Response: { token: "...", expires_at: "2099-12-31" }

2. GET /dashboard
   Response: 200 OK, dashboard HTML

3. GET /api/v1/temporary-messages
   Response: { 
       messages: [
           { id: 1, message: "PAT expires in 89 days", severity: "info", expires_at: "2026-10-15T14:30:00Z" }
       ],
       count: 1 
   }

4. POST /api/v1/temporary-messages/1/read
   Response: { marked_read: true }

5. GET /messages (new inbox page)
   Response: 200 OK, messages inbox view
```

**Tools:** curl, Postman, or Firefox DevTools

**Test Data:**
- Email: admin@example.com
- Password: (from installer config)
- PAT: (create via console)

---

### Workflow 2: CLI Check Cycle
**Test:** CLI performs full check including new temp message API

```bash
# 1. Register system
$ atglance register --pat atglance-xxxxx --host console.local
✓ System 9384 registered
✓ System validation key: abc123def456

# 2. Check status
$ atglance status
Hostname: prod-server-01
IP: 192.168.1.50
OS: Ubuntu 22.04
Status: Active
PAT expires: 2026-12-15 (89 days)

# 3. Run scheduled check
$ atglance check --scheduled
[INFO] Checking system health...
[INFO] Gathering unit status...
[INFO] Reading configuration files...
[POST] /api/v1/credentials/expiry-notice
  └─ Status: 200 OK
  └─ Message queued (expires: 2026-10-15T14:30:00Z)
[OK] Check complete
```

**Expected Console Output:**
```
Credential Status: Valid
Days Left: 89
Notice Stage: None (expires in 7+ days)
Message: "Your credential 'atglance-xxxxx' (…abcd) expires in 89 days."
Severity: info
TTL: 12 hours
```

---

### Workflow 3: Service Account Access
**Test:** Service account can only access granted workspaces

```bash
# 1. Create service account in workspace "Production"
POST /api/service-accounts
{
  "name": "prod-deployer",
  "scope": "workspace",
  "workspace_id": 1
}
Response: SA created (id: 5)

# 2. Grant SA to workspace "Production" only
POST /api/service-accounts/5/grants
{
  "workspace_id": 1
}

# 3. Issue key
POST /api/service-accounts/5/keys
Response: { token: "atglance-sa-xxxxx" }

# 4. Test access to "Production" workspace
GET /api/v1/config-files?workspace_id=1
Authorization: atglance-sa-xxxxx
Response: 200 OK (authorized)

# 5. Test access to "Staging" workspace (NOT granted)
GET /api/v1/config-files?workspace_id=2
Authorization: atglance-sa-xxxxx
Response: 403 Forbidden (workspace not granted)
```

**Expected Result:** SA can only access granted workspace

---

## Testing Checklist

### Phase 1: Console Deployment
- [ ] Database migrations run successfully
- [ ] All tables created (including temporary_credential_messages)
- [ ] Views deployed (messages inbox)
- [ ] API routes active (/messages, /api/v1/temporary-messages)
- [ ] Badge displays in topbar (no errors)

### Phase 2: API Testing
- [ ] POST /api/auth/login works
- [ ] GET /api/v1/credentials/status works
- [ ] POST /api/v1/credentials/expiry-notice creates message
- [ ] GET /api/v1/temporary-messages returns active messages only
- [ ] POST /api/v1/temporary-messages/{id}/read marks as read
- [ ] Cross-user access blocked (authorization working)

### Phase 3: UI Testing
- [ ] Messages badge appears in topbar
- [ ] Badge shows count when messages exist
- [ ] Clicking badge navigates to /messages
- [ ] Messages page displays list
- [ ] Each message shows TTL (expires in X hours/days)
- [ ] Dismiss button removes message and marks read
- [ ] Toast notifications appear on page load

### Phase 4: CLI Integration
- [ ] atglance register succeeds
- [ ] atglance status shows PAT expiry
- [ ] atglance check --scheduled calls new API
- [ ] API returns 200 and message is queued
- [ ] Message appears in console within 2 seconds

### Phase 5: Multi-Host
- [ ] 10 systems check simultaneously (no race conditions)
- [ ] All 10 messages created without errors
- [ ] API response time < 200ms per request
- [ ] Database doesn't timeout
- [ ] No duplicate message creation

### Phase 6: Security
- [ ] User A cannot read User B's messages
- [ ] Service account cannot access ungrantedworkspaces
- [ ] Unauthenticated requests get 401/403
- [ ] Rate limiting works (429 after threshold)
- [ ] Message content is properly escaped (no XSS)

---

## Known Issues to Test

### Issue 1: Token Expiry (2099 vs 90 days)
**Test:**
```bash
# Create PAT
POST /api/auth/pat-tokens
Response: { expires_at: "2099-12-31 23:59:59" }

# Expected: Should be 90 days from now
# Current: Year 2099 (WRONG)
```

**Remediation Test After Fix:**
```bash
# After fix, verify:
expires_at should be approx: now() + 90 days
# e.g., 2026-12-15 if today is 2026-10-15
```

---

### Issue 2: Message TTL Cleanup
**Test:**
```sql
-- Check temp messages table before test
SELECT COUNT(*) FROM temporary_credential_messages;
-- Expected: N messages

-- Run 24-hour test (simulated)
-- Create 1000 messages every hour

-- After 24 hours, check cleanup
SELECT COUNT(*) FROM temporary_credential_messages 
WHERE expires_at < now();
-- Expected: 0 (old messages cleaned up)
-- Actual: May show thousands (NO CLEANUP JOB)
```

**Remediation Test After Fix:**
```bash
# Verify cleanup job runs hourly
ps aux | grep "messages:cleanup-expired"
# Or check logs
tail -f storage/logs/laravel.log | grep "cleanup"
# Should see: "Deleted 456 expired messages"
```

---

## Performance Baseline

### Single Request
```
POST /api/v1/credentials/expiry-notice
Expected Response Time: < 100ms
Database Time: < 50ms
Message Queue Time: < 10ms
```

### 10 Concurrent Requests
```
Total Time: < 1 second
Database Connections: < 5
Memory Growth: < 10MB
CPU Usage: < 5%
```

### 100 Concurrent Requests
```
Total Time: < 10 seconds
Database Connections: < 20
Memory Growth: < 50MB
CPU Usage: < 20%
API Still Responsive: Yes
No timeouts: Yes
No connection errors: Yes
```

---

## Deployment Steps

### Step 1: Database Migrations
```bash
cd /app
php artisan migrate --force

# Verify migration ran
php artisan migrate:status | grep 2026_10_14
# Output: [✓] 2026_10_14_000000_version_2_1_2_schema

# Verify table exists
mysql -u root -p atglance -e "DESC temporary_credential_messages;"
```

### Step 2: Deploy Code
```bash
# Copy files to console
docker cp composer/resources/views/messages/ ce-atglance-app:/app/resources/views/
docker cp composer/resources/views/partials/ ce-atglance-app:/app/resources/views/
docker cp composer/routes/web.php ce-atglance-app:/app/routes/

# Clear caches
docker exec ce-atglance-app php artisan view:clear
docker exec ce-atglance-app php artisan route:clear
docker exec ce-atglance-app php artisan config:clear
```

### Step 3: Verify Deployment
```bash
# Test API endpoint
curl -s http://localhost:8000/api/v1/temporary-messages \
  -H "Authorization: Bearer {valid_token}" | jq '.count'

# Test web route
curl -s http://localhost:8000/messages | grep "Temporary Messages"

# Check badge in topbar
curl -s http://localhost:8000/dashboard | grep "messages-badge"
```

### Step 4: Test CLI Integration
```bash
# Register system with Console 2.1.2
atglance register --pat atglance-xxxxx --host console.local

# Verify it works
atglance check --scheduled

# Monitor console API
curl -s http://console:8000/api/v1/temporary-messages | jq '.messages[]'
```

---

## Rollback Plan

If deployment fails:

```bash
# 1. Revert code changes
git checkout HEAD~5 routes/web.php
git checkout HEAD~5 resources/views/messages/
git checkout HEAD~5 resources/views/partials/messages-badge.blade.php

# 2. Clear caches
docker exec ce-atglance-app php artisan view:clear
docker exec ce-atglance-app php artisan route:clear

# 3. Drop new table (if needed)
docker exec ce-atglance-db mysql -u root -p atglance \
  -e "DROP TABLE temporary_credential_messages;"

# 4. Restart containers
docker-compose restart ce-atglance-app ce-atglance-worker ce-atglance-scheduler
```

---

## Success Criteria

### Minimum (Go/No-Go)
- ✓ All API endpoints respond with correct codes
- ✓ Database migrations complete
- ✓ No 500 errors on console
- ✓ CLI can check against Console 2.1.2

### Full Deployment
- ✓ All above +
- ✓ 10-system test passes
- ✓ Messages persist and expire correctly
- ✓ Badge displays count
- ✓ Security checks pass (no cross-user access)

### Optimal
- ✓ All above +
- ✓ 100-system test shows < 5 second response
- ✓ Database cleanup working
- ✓ Token expiry fixed to 90 days
- ✓ All pentest issues addressed

---

## Issues & Challenges Log

### Challenge 1: CLI Version Mismatch
**Status:** NOTED
**Details:** CLI is v2.0.0~alpha9, Console is v2.1.2
**Impact:** New temp message features only in Console, CLI doesn't display new features yet
**Resolution:** CLI will be updated in next release to v2.1.2

**Mitigation for Testing:**
- Focus on API-level testing (message creation works)
- CLI check still triggers the API call successfully
- Console UI (badge, inbox) works independently

---

### Challenge 2: 100-Server Simulation on Windows
**Status:** BLOCKED
**Details:** Cannot run 100 Linux VMs on Windows dev machine
**Impact:** Cannot fully stress-test 100-concurrent scenario
**Resolution:** Recommend cloud-based load testing

**Options:**
1. Use LoadRunner / JMeter to simulate 100 concurrent requests
2. Set up 10 AWS EC2 instances for distributed testing
3. Use LocalStack Docker containers (local cloud simulation)
4. Run load test against staging environment

---

### Challenge 3: Go SDK Not Available
**Status:** OK
**Details:** Go not installed, cannot rebuild CLI
**Impact:** Cannot test latest CLI build
**Resolution:** Use pre-built binaries from dist/

---

## Recommended Testing Environment

### Ideal Setup
```
Console Host: Dedicated Linux VM
  - Ubuntu 22.04 LTS
  - Docker + Docker Compose
  - 8GB RAM, 100GB storage
  - Network: 192.168.1.0/24

CLI Test Hosts: 10 Linux VMs
  - Ubuntu 22.04 LTS
  - atglance CLI binary
  - 2GB RAM, 20GB storage
  - Same network (192.168.1.0/24)

Load Testing Host: 1 VM
  - Apache JMeter or LoadRunner
  - 4GB RAM
  - Can generate 100+ concurrent connections

Monitoring:
  - Prometheus (metrics)
  - Grafana (dashboards)
  - MySQL slow query log
```

---

## Next Steps

1. **Before Production:**
   - [ ] Fix token expiry (CRIT-001)
   - [ ] Add message cleanup job (CRIT-002)
   - [ ] Run Phase 1-4 tests above

2. **Post-Launch:**
   - [ ] Run 10-system test (Week 1)
   - [ ] Monitor database growth
   - [ ] Check API performance metrics
   - [ ] Collect user feedback

3. **Optimization (Month 2):**
   - [ ] Fix ID enumeration (HIGH-001)
   - [ ] Run 100-server load test
   - [ ] Implement caching if needed

---

**Testing Owner:** QA Team / DevOps
**Deployment Owner:** DevOps / System Admin
**Security Review:** Security Team

