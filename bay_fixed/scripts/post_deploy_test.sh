#!/usr/bin/env bash
set -euo pipefail
API_BASE=${1:-http://localhost/bay}
ORIGIN=${2:-http://localhost:5173}
HEALTH_TOKEN=${3:-}

echo "API base: $API_BASE"

# Health
HT_URL="$API_BASE/health.php"
if [[ -n "$HEALTH_TOKEN" ]]; then
  HT_URL="$HT_URL?token=$HEALTH_TOKEN"
fi

echo "Calling health: $HT_URL"
curl -sS -H "Origin: $ORIGIN" "$HT_URL" | jq . || { echo "Health check failed"; exit 2; }

# Preflight
echo "Preflight OPTIONS for auth_api.php"
curl -i -X OPTIONS "$API_BASE/auth_api.php" -H "Origin: $ORIGIN" -H "Access-Control-Request-Method: POST" | grep -i "Access-Control-Allow-Origin" || { echo "CORS preflight failed"; exit 3; }

echo "Login test (jai)"
COOKIE_JAR=$(mktemp)
curl -sS -c "$COOKIE_JAR" -X POST "$API_BASE/auth_api.php" -H "Origin: $ORIGIN" -H "Content-Type: application/json" -d '{"action":"login","username":"jai","password":"212121"}' | jq . || { echo "Login failed"; rm -f "$COOKIE_JAR"; exit 4; }

# Protected endpoint
echo "Testing protected endpoint order_status_api.php?mode=queue"
curl -sS -b "$COOKIE_JAR" -H "Origin: $ORIGIN" "$API_BASE/order_status_api.php?mode=queue" | jq . || { echo "Protected endpoint failed"; rm -f "$COOKIE_JAR"; exit 5; }

# Products
echo "Fetching products list"
curl -sS -H "Origin: $ORIGIN" "$API_BASE/products_api.php" | jq . || { echo "Products fetch failed"; rm -f "$COOKIE_JAR"; exit 6; }

rm -f "$COOKIE_JAR"
echo "Post-deploy smoke tests completed successfully."
exit 0
