# Scenarios Tested and Fixed

## Scenario 1: Normal user creation through Kong
- MySQL up, breaker closed.
- POST /users through Kong returned 201 and created the user.

## Scenario 2: MySQL down, write queued
- MySQL stopped to simulate outage.
- POST /users returned 202 with `status: queued`.
- Queue worker retried with exponential backoff.
- When MySQL started, the user was created automatically.

## Scenario 3: Circuit breaker open during outage
- Multiple DB failures triggered breaker open.
- GET /users returned 503 with `circuit_state: open`.
- After timeout, breaker moved to half-open and closed on successful calls.

## Scenario 4: Validation hitting DB during outage
- Unique email validation tried to reach DB and failed.
- Fix: validation now skips DB-backed `unique` rule when breaker is open and queues the request.
- This enabled writes to be queued during outages.

## Scenario 5: DB_HOST misconfiguration
- API container used `DB_HOST=127.0.0.1`, which failed inside Docker.
- Fix: updated `.env` and `.env.example` to `DB_HOST=mysql`.
- Rebuilt containers so runtime `.env` uses mysql service.

## Scenario 6: Fresh restart without tables
- After full restart, tables were missing.
- Fix: ran `php artisan migrate --force` to create tables.

## Scenario 7: Kong rate limiting
- Kong rate limit (4/min) returned 429 during repeated requests.
- Fix: slow down requests or wait for reset window.

## Scenario 8: JSON body issues in PowerShell
- `curl.exe` JSON payloads were not parsed correctly.
- Fix: use `Invoke-RestMethod` with `ConvertTo-Json` to send proper JSON.

## Scenario 9: Queue visibility
- Queue often showed 0 pending because jobs were moved into delayed retries.
- Verified by queue-worker logs and eventual success after DB recovery.

## Scenario 10: Full end-to-end retry after DB recovery
- User created while DB down.
- MySQL started after delay.
- User created automatically by queue retry.
