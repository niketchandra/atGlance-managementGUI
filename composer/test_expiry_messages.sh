#!/bin/bash

# Phase 5 Manual Testing Script: Credential Expiry Messages
# Tests the /api/v1/credentials/expiry-notice endpoint across all 3 hosts
# Verifies that messages are created in temporary_credential_messages table
# and can be retrieved via GET /api/v1/temporary-messages

set -e

# Color output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Configuration
HOSTS=("host1:8000" "host2:8000" "host3:8000")
TEST_USER_EMAIL="test@example.com"
TEST_USER_PASSWORD="TestPassword123!"

echo "=========================================="
echo "Phase 5: Credential Expiry Messages Test"
echo "=========================================="
echo ""

# Function to test a single host
test_host() {
    local host=$1
    local base_url="http://${host}"

    echo -e "${YELLOW}Testing Host: ${host}${NC}"
    echo ""

    # Step 1: Register/login to get session token
    echo "Step 1: Getting auth token..."
    local auth_response=$(curl -s -X POST "${base_url}/api/auth/login" \
        -H "Content-Type: application/json" \
        -d "{\"email\":\"${TEST_USER_EMAIL}\",\"password\":\"${TEST_USER_PASSWORD}\"}")

    local session_token=$(echo "$auth_response" | jq -r '.session_token // empty')
    if [ -z "$session_token" ]; then
        echo -e "${RED}✗ Failed to get session token from ${host}${NC}"
        return 1
    fi
    echo -e "${GREEN}✓ Got session token${NC}"
    echo ""

    # Step 2: Get or create PAT with expiry
    echo "Step 2: Getting PAT token..."
    local pat_response=$(curl -s -X GET "${base_url}/api/auth/pat-tokens" \
        -H "Authorization: Bearer ${session_token}")

    local pat_token=$(echo "$pat_response" | jq -r '.tokens[0].token // empty')
    local pat_id=$(echo "$pat_response" | jq -r '.tokens[0].id // empty')

    if [ -z "$pat_token" ]; then
        echo -e "${RED}✗ No PAT token found on ${host}${NC}"
        return 1
    fi
    echo -e "${GREEN}✓ Found PAT token: ${pat_id}${NC}"
    echo ""

    # Step 3: Call expiryNotice endpoint
    echo "Step 3: Calling /api/v1/credentials/expiry-notice..."
    local notice_response=$(curl -s -X POST "${base_url}/api/v1/credentials/expiry-notice" \
        -H "Authorization: Bearer ${pat_token}")

    echo "Response: $(echo "$notice_response" | jq '.')"

    local queued=$(echo "$notice_response" | jq -r '.queued // false')
    if [ "$queued" != "true" ]; then
        local error=$(echo "$notice_response" | jq -r '.error // "Unknown error"')
        echo -e "${RED}✗ expiryNotice returned error: ${error}${NC}"
        return 1
    fi
    echo -e "${GREEN}✓ Message queued successfully${NC}"
    echo ""

    # Step 4: Check database for message
    echo "Step 4: Checking database for message..."
    local db_result=$(curl -s -X GET "${base_url}/api/v1/temporary-messages" \
        -H "Authorization: Bearer ${pat_token}")

    local message_count=$(echo "$db_result" | jq '.count // 0')
    if [ "$message_count" -eq 0 ]; then
        echo -e "${RED}✗ No messages found in database${NC}"
        echo "Database response: $(echo "$db_result" | jq '.')"
        return 1
    fi
    echo -e "${GREEN}✓ Found ${message_count} message(s) in database${NC}"

    # Display message details
    local message=$(echo "$db_result" | jq '.messages[0] // {}')
    echo "Message details:"
    echo "  - Severity: $(echo "$message" | jq -r '.severity')"
    echo "  - Days Left: $(echo "$message" | jq -r '.days_left')"
    echo "  - Status: $(echo "$message" | jq -r '.status // "N/A"')"
    echo ""

    # Step 5: Test marking message as read
    echo "Step 5: Testing message read status..."
    local message_id=$(echo "$message" | jq -r '.id')
    if [ -n "$message_id" ] && [ "$message_id" != "null" ]; then
        local read_response=$(curl -s -X POST "${base_url}/api/v1/temporary-messages/${message_id}/read" \
            -H "Authorization: Bearer ${pat_token}")

        local marked_read=$(echo "$read_response" | jq -r '.marked_read // false')
        if [ "$marked_read" = "true" ]; then
            echo -e "${GREEN}✓ Message marked as read${NC}"
        else
            echo -e "${YELLOW}⚠ Could not mark message as read${NC}"
        fi
    fi
    echo ""

    echo -e "${GREEN}✓ All tests passed for ${host}${NC}"
    echo "=========================================="
    echo ""
}

# Main execution
error_count=0

for host in "${HOSTS[@]}"; do
    if ! test_host "$host"; then
        ((error_count++))
    fi
done

# Summary
echo ""
echo "=========================================="
echo "Test Summary"
echo "=========================================="
if [ $error_count -eq 0 ]; then
    echo -e "${GREEN}✓ All hosts passed Phase 5 tests${NC}"
    exit 0
else
    echo -e "${RED}✗ ${error_count} host(s) failed Phase 5 tests${NC}"
    exit 1
fi
