# Circuit Breaker

## Purpose
The circuit breaker protects the API from repeated database failures. When the database is unstable or down, it opens the circuit to stop expensive retries, returns a fast 503 to callers, and lets the queue handle writes.

## Where the code lives
- Core implementation: [composer/app/Services/CircuitBreaker.php](composer/app/Services/CircuitBreaker.php)
- Database wrapper: [composer/app/Services/DatabaseCircuitBreaker.php](composer/app/Services/DatabaseCircuitBreaker.php)
- Usage in user endpoints: [composer/app/Http/Controllers/Api/UserController.php](composer/app/Http/Controllers/Api/UserController.php)
- Usage in product endpoints: [composer/app/Http/Controllers/Api/ProductController.php](composer/app/Http/Controllers/Api/ProductController.php)

## What the code means
- `CircuitBreaker` maintains state in cache with four keys: `state`, `failures`, `successes`, `opened_at`.
- States:
  - `closed`: normal operation, failures are tracked.
  - `open`: short-circuits calls for the configured timeout.
  - `half-open`: after timeout, allows a small number of test calls to decide recovery.
- Rules:
  - `failureThreshold`: after 3 failures for DB, the circuit opens.
  - `timeout`: circuit stays open for 30 seconds before half-open.
  - `successThreshold`: 2 successful calls in half-open closes the circuit.
  - `monitoringWindow`: failures expire after 60 seconds.

In `DatabaseCircuitBreaker`, `query()` first runs `SELECT 1`. If that fails, the breaker records a failure and opens when the threshold is reached. When open, the controller returns a 503 for reads and queues writes.

## Complete workflow (current system)
1. Request hits controller.
2. Controller checks breaker state.
3. If breaker is open:
   - Reads return 503 with `circuit_state`.
   - Writes are queued with `request_id` and return 202.
4. If breaker is closed:
   - Controller executes DB call through `DatabaseCircuitBreaker::query()`.
5. On DB failure, breaker increments failures and may open.
6. On DB recovery, breaker moves to half-open after timeout.
7. Two successful calls close the breaker.
8. Queue retries writes until they succeed or exhaust retries.

## Workflow (request flow)
```
Client
  |
  v
Controller -> Breaker state check
  |                |
  |                +-- open --> 503 (read) / 202 + queue (write)
  |
  +-- closed --> DatabaseCircuitBreaker::query()
              |
              +-- success --> 2 successes close breaker
              +-- failure --> record failure -> open if threshold
```

## Using the same scenario for file uploads
You can apply the same pattern to file uploads (e.g., when storage or DB is unavailable):

1. Add a file upload endpoint and wrap the DB or storage operation in the breaker.
2. If breaker is open, dispatch a new upload job (e.g., `UploadFileJob`) and return 202 with a `request_id`.
3. In the job, retry the storage write using the queue backoff.
4. If the job succeeds, persist metadata in the DB.

Suggested placement:
- Upload job: `composer/app/Jobs/UploadFileJob.php`
- Upload controller action in a dedicated controller (or existing controller).
- Apply the same guard used in user creation: check `isAvailable()` first, then fall back to queue.

## Notes
- The breaker is service-specific: each service name has its own state in cache.
- The breaker state survives across requests via cache keys.
- The queue handles write durability when the DB is temporarily unavailable.
