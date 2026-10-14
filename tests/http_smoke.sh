#!/usr/bin/env bash
#
# End-to-end HTTP smoke test.
#
#   DB_HOST=127.0.0.1 DB_PORT=3306 DB_NAME=senbet_maderaja DB_USER=root DB_PASS= \
#     php -S 127.0.0.1:18080 -t /path/to/senbet_maderaja &
#   BASE_URL=http://127.0.0.1:18080 tests/http_smoke.sh
#
# It signs in through the real login form (CSRF token included), then walks
# every page and asserts the data-isolation rules hold over HTTP as well.
set -uo pipefail

BASE_URL="${BASE_URL:-http://127.0.0.1:18080}"
JAR="$(mktemp)"
PASS=0
FAIL=0

cleanup() { rm -f "$JAR"; }
trap cleanup EXIT

ok()   { PASS=$((PASS + 1)); echo "  [PASS] $1"; }
bad()  { FAIL=$((FAIL + 1)); echo "  [FAIL] $1"; }

check_contains() {
  local name="$1" body="$2" needle="$3"
  if grep -qF -- "$needle" <<< "$body"; then ok "$name"; else bad "$name (missing: $needle)"; fi
}

check_status() {
  local name="$1" code="$2" want="$3"
  if [ "$code" = "$want" ]; then ok "$name"; else bad "$name (got $code, want $want)"; fi
}

sign_in() {
  local user="$1" pass="$2"
  : > "$JAR"
  local token
  token=$(curl -s -c "$JAR" "$BASE_URL/login.php" | grep -oE 'name="_token" value="[a-f0-9]+"' | head -1 | sed -E 's/.*value="([a-f0-9]+)".*/\1/')
  curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' \
    -d "_token=$token" -d "username=$user" -d "password=$pass" \
    "$BASE_URL/login.php"
}

fetch() {
  local path="$1"
  curl -s -b "$JAR" -c "$JAR" "$BASE_URL$path"
}

status_of() {
  local path="$1"
  curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -c "$JAR" "$BASE_URL$path"
}

echo "HTTP smoke test against $BASE_URL"
echo

# ---------------------------------------------------------------------
echo "1. Anonymous access is refused"
check_status "login page is public" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE_URL/login.php")" 200
check_status "dashboard redirects anonymous users" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE_URL/index.php")" 302
check_status "API refuses anonymous users" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE_URL/api/index.php?resource=me")" 401

echo
echo "2. Institution Admin (bole_admin) - aggregated view"
code=$(sign_in bole_admin 'Admin@123')
check_status "login succeeds (302 to dashboard)" "$code" 302
check_contains "dashboard renders" "$(fetch /index.php)" "Aggregated department report"
body=$(fetch /index.php)
check_contains "sees its own institution" "$body" "Bole SDA Church"
if grep -qF "Hawassa" <<< "$body"; then bad "must NOT see the other institution"; else ok "does not see the other institution"; fi
check_contains "reports page shows institution-wide aggregation" "$(fetch /modules/reports.php)" "Aggregated across the whole institution"
check_contains "academic report shows the aggregation badge" "$(fetch /modules/acadamics/academic_reports.php)" "Institution-wide aggregation"
check_contains "departments page lists both departments" "$(fetch /admin/departments.php)" "Youth Ministry"
check_contains "institutions page is scoped" "$(fetch /admin/institutions.php)" "Bole SDA Church"

echo
echo "3. Department Admin (ss_admin) - own sub-tree only"
code=$(sign_in ss_admin 'Senbet@123')
check_status "login succeeds" "$code" 302
body=$(fetch /modules/reports.php)
check_contains "sees its own department" "$body" "Sabbath School"
if grep -qF "Youth Ministry" <<< "$body"; then bad "must NOT see the sibling department"; else ok "does not see the sibling department"; fi
check_contains "shows the sub-tree badge" "$body" "Own sub-tree only"
check_contains "sees its sub-department" "$(fetch /admin/departments.php)" "Primary Division"
check_status "cannot open the institutions screen" "$(status_of /admin/institutions.php)" 403
check_status "cannot open another institution's department" "$(status_of '/api/index.php?resource=departments&id=4')" 403
check_status "cannot read a member of a sibling department" "$(status_of '/api/index.php?resource=members&id=4')" 403
check_status "can read its own member" "$(status_of '/api/index.php?resource=members&id=1')" 200

echo
echo "4. Module pages render for a teacher"
code=$(sign_in primary_teacher 'Senbet@123')
check_status "login succeeds" "$code" 302
check_status "academics landing renders" "$(status_of /modules/acadamics/academic.php)" 200
check_status "grades page renders" "$(status_of /modules/acadamics/grades.php)" 200
check_status "report cards render" "$(status_of /modules/acadamics/report_cards.php)" 200
check_status "finance is forbidden" "$(status_of /modules/finance.php)" 403
check_status "users screen is forbidden" "$(status_of /admin/users.php)" 403

echo
echo "5. JSON API"
code=$(sign_in main_admin 'Admin@123')
check_status "main admin login" "$code" 302
body=$(fetch '/api/index.php?resource=me')
check_contains "me endpoint returns the global scope" "$body" '"type":"global"'
body=$(fetch '/api/index.php?resource=reports&action=departments')
check_contains "report API returns rows" "$body" '"ok":true'
body=$(fetch '/api/index.php?resource=departments')
check_contains "department API returns the tree" "$body" "Sabbath School"
body=$(fetch '/api/index.php?resource=meta')
check_contains "meta endpoint lists resources" "$body" '"resources"'
check_status "unknown resource returns 404" "$(status_of '/api/index.php?resource=nope')" 404
check_status "anonymous API call returns 401" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE_URL/api/index.php?resource=departments")" 401

echo
echo "=================================================="
echo "HTTP smoke: $PASS passed, $FAIL failed"
echo "=================================================="
exit $([ "$FAIL" -eq 0 ] && echo 0 || echo 1)
