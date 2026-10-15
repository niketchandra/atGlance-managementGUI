#!/bin/bash

# AtGlance API Testing Script
# Tests all major endpoints for security vulnerabilities

API_URL="http://localhost:8000"
ADMIN_EMAIL="admin@example.com"
ADMIN_PASS="password"

echo "========================================="
echo "AtGlance API Security Test"
echo "========================================="

# Color codes
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

# Test counter
TESTS_PASSED=0
TESTS_FAILED=0

# Helper function to test endpoint
test_endpoint() {
    local name=$1
    local method=$2
    local endpoint=$3
    local data=$4
    local expected_code=$5

    echo -e "\n${YELLOW}Testing: $name${NC}"
    echo "  Endpoint: $method $endpoint"

    if [ "$method" == "GET" ]; then
        response=$(curl -s -w "\n%{http_code}" -X GET "$API_URL$endpoint" \
            -H "Accept: application/json" \
            -H "X-Requested-With: XMLHttpRequest")
    else
        response=$(curl -s -w "\n%{http_code}" -X "$method" "$API_URL$endpoint" \
            -H "Content-Type: application/json" \
            -H "Accept: application/json" \
            -H "X-Requested-With: XMLHttpRequest" \
            -d "$data")
    fi

    http_code=$(echo "$response" | tail -1)
    body=$(echo "$response" | head -1)

    echo "  Response Code: $http_code (Expected: $expected_code)"

    if [ "$http_code" == "$expected_code" ]; then
        echo -e "  ${GREEN}✓ PASS${NC}"
        ((TESTS_PASSED++))
    else
        echo -e "  ${RED}✗ FAIL${NC}"
        echo "  Response: $body"
        ((TESTS_FAILED++))
    fi
}

# ========================================
# PHASE 1: Authentication Tests
# ========================================
echo -e "\n${YELLOW}PHASE 1: Authentication${NC}"

test_endpoint "Login - Valid credentials" "POST" "/api/auth/login" \
    "{\"email\":\"$ADMIN_EMAIL\",\"password\":\"$ADMIN_PASS\"}" "200"

test_endpoint "Login - Invalid email" "POST" "/api/auth/login" \
    "{\"email\":\"invalid@test.com\",\"password\":\"wrong\"}" "401"

test_endpoint "Login - Missing password" "POST" "/api/auth/login" \
    "{\"email\":\"$ADMIN_EMAIL\"}" "422"

test_endpoint "Validate token - No token" "GET" "/api/auth/validate-token" "" "401"

# ========================================
# PHASE 2: API V1 Credentials
# ========================================
echo -e "\n${YELLOW}PHASE 2: Credentials API${NC}"

test_endpoint "Credentials status - No token" "GET" "/api/v1/credentials/status" "" "401"

test_endpoint "Expiry notice - No token" "POST" "/api/v1/credentials/expiry-notice" \
    "{}" "401"

test_endpoint "Temporary messages - No token" "GET" "/api/v1/temporary-messages" "" "401"

# ========================================
# PHASE 3: Authorization Tests
# ========================================
echo -e "\n${YELLOW}PHASE 3: Authorization Checks${NC}"

test_endpoint "Dashboard - Unauthenticated" "GET" "/dashboard" "" "302"

test_endpoint "Admin panel - Unauthenticated" "GET" "/admin/users" "" "302"

test_endpoint "Messages page - Unauthenticated" "GET" "/messages" "" "302"

# ========================================
# PHASE 4: Input Validation
# ========================================
echo -e "\n${YELLOW}PHASE 4: Input Validation${NC}"

# XSS payloads
XSS_PAYLOAD='{"email":"<script>alert(1)</script>@test.com","password":"test"}'
test_endpoint "Login - XSS in email" "POST" "/api/auth/login" "$XSS_PAYLOAD" "422"

# SQL injection patterns
SQLI_EMAIL='admin@test.com\' OR \'1\'=\'1'
test_endpoint "Login - SQL injection attempt" "POST" "/api/auth/login" \
    "{\"email\":\"$SQLI_EMAIL\",\"password\":\"test\"}" "401"

# ========================================
# PHASE 5: Rate Limiting
# ========================================
echo -e "\n${YELLOW}PHASE 5: Rate Limiting${NC}"

echo "Testing rate limiting on /api/auth/login (throttle:10,1)..."
for i in {1..15}; do
    response=$(curl -s -w "\n%{http_code}" -X POST "$API_URL/api/auth/login" \
        -H "Content-Type: application/json" \
        -d "{\"email\":\"test@test.com\",\"password\":\"test\"}" | tail -1)

    if [ "$response" == "429" ]; then
        echo -e "  ${GREEN}✓ Rate limit triggered at request $i${NC}"
        break
    fi
done

# ========================================
# Summary
# ========================================
echo -e "\n${YELLOW}=========================================${NC}"
echo "Test Summary"
echo -e "${YELLOW}=========================================${NC}"
echo -e "Passed: ${GREEN}$TESTS_PASSED${NC}"
echo -e "Failed: ${RED}$TESTS_FAILED${NC}"
echo "Total: $((TESTS_PASSED + TESTS_FAILED))"

if [ $TESTS_FAILED -eq 0 ]; then
    echo -e "${GREEN}All tests passed!${NC}"
    exit 0
else
    echo -e "${RED}Some tests failed. Review above.${NC}"
    exit 1
fi
