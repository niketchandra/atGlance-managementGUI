#!/bin/bash

echo "=========================================="
echo "TEST: Service Account Workspace Isolation (HIGH-002)"
echo "=========================================="
echo ""

echo "Auditing ServiceAccount authorization logic..."
echo ""

# Check 1: Find ServiceAccount model and workspace relationship
echo "CHECK 1: ServiceAccount Workspace Relationship"
grep -r "workspaces\|workspace_id" "composer/app/Models/ServiceAccount.php" 2>/dev/null | head -3
echo ""

# Check 2: Check ConfigFileController for workspace validation
echo "CHECK 2: ConfigFileController Workspace Authorization"
grep -n "workspace" "composer/app/Http/Controllers/Api/V1/ConfigFileController.php" 2>/dev/null | head -5
echo ""

# Check 3: Check if service_account_workspaces table exists
echo "CHECK 3: Service Account Workspaces Table"
docker exec ce-atglance-app php artisan tinker --execute="
\$tables = \DB::select('SHOW TABLES LIKE \"service_account_workspaces\"');
if (!empty(\$tables)) {
    echo '[OK] Table exists' . PHP_EOL;
    \$cols = \DB::select('DESCRIBE service_account_workspaces');
    foreach (\$cols as \$col) {
        echo '  - ' . \$col->Field . ': ' . \$col->Type . PHP_EOL;
    }
} else {
    echo '[WARN] Table not found' . PHP_EOL;
}
" 2>&1 | grep -v "Tinker v"
echo ""

# Check 4: Verify authorization checks in critical endpoints
echo "CHECK 4: Authorization Check in System Controller"
grep -n "workspace\|grant" "composer/app/Http/Controllers/Api/V1/SystemController.php" 2>/dev/null | head -5
echo ""

echo "=========================================="
echo "Service Account Isolation Audit Complete"
echo "=========================================="
echo ""
echo "RECOMMENDATION: HIGH-002 requires code review of:"
echo "  1. ConfigFileController::index() - does it verify workspace access?"
echo "  2. SystemController::deregister() - does it verify workspace access?"
echo "  3. Is service_account_workspaces checked for all API calls?"
