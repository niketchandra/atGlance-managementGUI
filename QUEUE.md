# Queue System Details

## Purpose
The queue persists write operations when the database is unavailable and retries them safely when the database recovers.

## Components
- Redis (queue backend)
- Queue worker service (runs `php artisan queue:work`)
- Job classes:
  - [composer/app/Jobs/CreateUserJob.php](composer/app/Jobs/CreateUserJob.php)
  - [composer/app/Jobs/UpdateUserJob.php](composer/app/Jobs/UpdateUserJob.php)
  - [composer/app/Jobs/DeleteUserJob.php](composer/app/Jobs/DeleteUserJob.php)
  - [composer/app/Jobs/CreateProductJob.php](composer/app/Jobs/CreateProductJob.php)
  - [composer/app/Jobs/UpdateProductJob.php](composer/app/Jobs/UpdateProductJob.php)
  - [composer/app/Jobs/DeleteProductJob.php](composer/app/Jobs/DeleteProductJob.php)

## Worker command (from docker-compose)
The worker runs with retries and backoff:

- `--tries=5`
- `--backoff=30,60,120,300,600`
- `--timeout=60`
- `--sleep=3`

This means:
- Attempt 1: immediate
- Attempt 2: +30s
- Attempt 3: +60s
- Attempt 4: +120s
- Attempt 5: +300s
- Final attempt 6: +600s

## How the queue integrates with the system
1. Controller validates request.
2. If breaker is open or DB call fails, controller dispatches a job.
3. API returns 202 with `request_id` and `circuit_state`.
4. Worker tries to execute the job.
5. If DB is still down, the job fails and is rescheduled with backoff.
6. When DB is back, the job succeeds and writes to DB.

## Queue states and visibility
- Pending: jobs waiting to be processed.
- Reserved: jobs currently being processed.
- Delayed: jobs scheduled for future retry.

In outages, jobs are usually delayed, so `queue:monitor` might show 0 pending while retries are scheduled.

## Monitoring and troubleshooting
- Check queue health:
  - `php artisan queue:monitor redis`
- Watch worker logs:
  - `docker compose logs -f queue-worker`
- Failed jobs:
  - `php artisan queue:failed`
- Retry failed job:
  - `php artisan queue:retry <id>`

## Common behaviors
- If MySQL is down, jobs will fail and be retried.
- When MySQL returns, the next retry succeeds and inserts the record.
- If all retries fail, the job is moved to the failed jobs list.

## Extending to other workflows
To add queue support for a new workflow (e.g., file upload):
1. Create a new job class for the workflow.
2. Dispatch the job when breaker is open or when DB/storage fails.
3. Use the same retry and backoff pattern.
4. Return 202 with a `request_id` so clients can track the request.
