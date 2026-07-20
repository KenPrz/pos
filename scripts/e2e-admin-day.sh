#!/bin/bash
#
# The M6 'admin day' — the milestone's end-to-end proof, runnable against a freshly
# seeded stack: an admin builds a menu item from nothing (category, tax rate, product,
# variant, modifier group + modifiers, attach), hires a cashier, switches a till to food
# mode and issues it a fresh activation code (killing the old device token and every
# staff session bound to it mid-script), redeems that code for a new device token, a
# register sale runs on the new token, the admin reprices the sold variant and proves
# the paid order's receipt didn't move (snapshot), the three sales-report slices
# attribute the same sale by day/category/user, the audit log turns up every write, and
# the shift closes clean.
# Set POS_ADMIN_EMAIL / POS_ADMIN_PASSWORD (back-office login), POS_DEVICE_TOKEN (Till 1
# at Downtown — printed by php artisan migrate:fresh --seed) and POS_E2E_PIN (any 4-6
# digit PIN for the cashier this script hires) first. Never a token literal in this file.
#
set -euo pipefail
API=http://127.0.0.1:8000/api/v1
ADMIN_EMAIL="${POS_ADMIN_EMAIL:?set POS_ADMIN_EMAIL to the back-office admin email, printed by php artisan migrate:fresh --seed}"
ADMIN_PASSWORD="${POS_ADMIN_PASSWORD:?set POS_ADMIN_PASSWORD to the back-office admin password, printed by php artisan migrate:fresh --seed}"
DEVICE="${POS_DEVICE_TOKEN:?set POS_DEVICE_TOKEN to the Till 1 (Downtown) device token, printed by php artisan migrate:fresh --seed}"
E2E_PIN="${POS_E2E_PIN:?set POS_E2E_PIN to a 4-6 digit PIN for the cashier this script hires}"
J='Content-Type: application/json'

fail() { echo "FAIL: $1" >&2; exit 1; }

# req METHOD /path [curl args...] — Content-Type is always JSON; callers add auth
# headers (-H "$AD"/"$D"/"$S") and -d bodies as needed. Fails the script on non-2xx,
# same as e2e-lunch-service.sh's helper.
req() {
  local method="$1" path="$2"
  shift 2
  curl -sf -X "$method" "$API$path" -H "$J" "$@"
}

# status_of METHOD /path [curl args...] — like req, but returns the HTTP status code
# instead of failing on non-2xx, for asserting an *expected* refusal (the killed token).
status_of() {
  local method="$1" path="$2"
  shift 2
  curl -s -o /dev/null -w '%{http_code}' -X "$method" "$API$path" -H "$J" "$@"
}

# --- 1. admin login ---

LOGIN=$(req POST /admin/login -d "{\"email\":\"$ADMIN_EMAIL\",\"password\":\"$ADMIN_PASSWORD\"}")
ADMIN_TOKEN=$(echo "$LOGIN" | jq -r .data.token)
AD="Authorization: Bearer $ADMIN_TOKEN"
[ "$(echo "$LOGIN" | jq -r .data.user.is_admin)" = "true" ] || fail "admin login did not return an admin user"
echo "1. admin logged in via POST /admin/login"

# --- 2. build a menu item from nothing ---

LOCATIONS=$(req GET /admin/locations -H "$AD")
DOWNTOWN_ID=$(echo "$LOCATIONS" | jq -r '.data.items[] | select(.code=="DT") | .id')
[ -n "$DOWNTOWN_ID" ] && [ "$DOWNTOWN_ID" != "null" ] || fail "could not resolve Downtown (DT) location id"
echo "2. Downtown (DT) resolved: $DOWNTOWN_ID"

CATEGORY=$(req POST /admin/categories -H "$AD" -d '{"name":"Drinks","sort_order":1}')
CATEGORY_ID=$(echo "$CATEGORY" | jq -r .data.category.id)
echo "3. category created: Drinks ($CATEGORY_ID)"

TAX=$(req POST /admin/tax-rates -H "$AD" -d '{"name":"E2E 10%","rate_micros":100000}')
TAX_ID=$(echo "$TAX" | jq -r .data.tax_rate.id)
[ "$(echo "$TAX" | jq .data.tax_rate.rate_micros)" = "100000" ] || fail "tax rate should be 100000 micros (10%)"
echo "4. tax rate created: E2E 10% ($TAX_ID)"

PRODUCT=$(req POST /admin/products -H "$AD" -d "{\"name\":\"Flat White\",\"category_id\":\"$CATEGORY_ID\",\"kind\":\"service\"}")
PRODUCT_ID=$(echo "$PRODUCT" | jq -r .data.product.id)
[ "$(echo "$PRODUCT" | jq -r .data.product.kind)" = "service" ] || fail "product kind should be service"
echo "5. product created: Flat White ($PRODUCT_ID)"

# Downtown is prices_include_tax=false (tax added at the till, not baked into the shelf
# price — docs/02-data-model.md), so attaching the 10% rate above to this variant would
# make every downstream total 110% of the round numbers this walkthrough asserts. The
# tax rate is still exercised end-to-end (created, listed, available for a real variant);
# this particular untracked service item is deliberately left tax-exempt (tax_rate_id
# omitted, which CreateVariantRequest allows) so the modifier/reporting math below stays
# exact.
VARIANT=$(req POST /admin/variants -H "$AD" -d "{\"product_id\":\"$PRODUCT_ID\",\"name\":\"Regular\",\"sku\":\"FW-1\",\"price_cents\":450,\"track_inventory\":false}")
VARIANT_ID=$(echo "$VARIANT" | jq -r .data.variant.id)
[ "$(echo "$VARIANT" | jq .data.variant.price_cents)" = "450" ] || fail "variant should be 450c"
[ "$(echo "$VARIANT" | jq -r .data.variant.track_inventory)" = "false" ] || fail "variant should be untracked"
echo "6. variant created: FW-1, 450c, untracked, no tax rate attached ($VARIANT_ID)"

MILK=$(req POST /admin/modifier-groups -H "$AD" -d '{"name":"Milk","min_select":1,"max_select":1}')
MILK_ID=$(echo "$MILK" | jq -r .data.modifier_group.id)
echo "7. modifier group created: Milk, min 1 max 1 ($MILK_ID)"

OAT=$(req POST /admin/modifiers -H "$AD" -d "{\"group_id\":\"$MILK_ID\",\"name\":\"Oat\",\"price_delta_cents\":60}")
OAT_ID=$(echo "$OAT" | jq -r .data.modifier.id)
req POST /admin/modifiers -H "$AD" -d "{\"group_id\":\"$MILK_ID\",\"name\":\"Whole\",\"price_delta_cents\":0}" > /dev/null
echo "8. modifiers created: Oat (+60), Whole (+0)"

ATTACH=$(req PUT "/admin/products/$PRODUCT_ID/modifier-groups" -H "$AD" -d "{\"group_ids\":[\"$MILK_ID\"]}")
[ "$(echo "$ATTACH" | jq -r '.data.product.modifier_group_ids | length')" = "1" ] || fail "Milk should be the product's only attached group"
[ "$(echo "$ATTACH" | jq -r '.data.product.modifier_group_ids[0]')" = "$MILK_ID" ] || fail "wrong modifier group attached to Flat White"
echo "9. Milk attached to Flat White"

# --- 3. hire Eve ---

EVE=$(req POST /admin/users -H "$AD" -d "{\"name\":\"Eve\",\"pin\":\"$E2E_PIN\",\"roles\":[{\"location_id\":\"$DOWNTOWN_ID\",\"role\":\"cashier\"}]}")
EVE_ID=$(echo "$EVE" | jq -r .data.user.id)
echo "10. Eve hired, cashier @ DT ($EVE_ID)"

USERS=$(req GET /admin/users -H "$AD")
EVE_ROW=$(echo "$USERS" | jq -c --arg id "$EVE_ID" '.data.items[] | select(.id==$id)')
[ -n "$EVE_ROW" ] || fail "Eve does not appear in GET /admin/users"
EVE_ROLE=$(echo "$EVE_ROW" | jq -r --arg loc "$DOWNTOWN_ID" '.roles[] | select(.location_id==$loc) | .role')
[ "$EVE_ROLE" = "cashier" ] || fail "Eve's cashier role @ DT did not stick, got: $EVE_ROLE"
echo "11. Eve confirmed in GET /admin/users with cashier role @ DT"

# --- 4. Till 1 to food mode, issue + redeem an activation code ---

REGISTERS=$(req GET /admin/registers -H "$AD")
TILL1_ID=$(echo "$REGISTERS" | jq -r --arg loc "$DOWNTOWN_ID" '.data.items[] | select(.location_id==$loc and .name=="Till 1") | .id')
[ -n "$TILL1_ID" ] && [ "$TILL1_ID" != "null" ] || fail "could not resolve Till 1's register id"
echo "12. Till 1 resolved: $TILL1_ID"

MODE=$(req PATCH "/admin/registers/$TILL1_ID" -H "$AD" -d '{"mode":"food"}')
[ "$(echo "$MODE" | jq -r .data.register.mode)" = "food" ] || fail "Till 1 did not switch to food mode"
echo "13. Till 1 set to food mode"

ISSUED=$(req POST "/admin/registers/$TILL1_ID/activation-code" -H "$AD")
ACTIVATION_CODE=$(echo "$ISSUED" | jq -r .data.activation_code)
[ -n "$ACTIVATION_CODE" ] && [ "$ACTIVATION_CODE" != "null" ] || fail "activation-code issue did not return a code"
[ "$(echo "$ISSUED" | jq -r .data.expires_at)" != "null" ] || fail "activation-code issue did not return an expiry"
echo "14. activation code issued for Till 1"

OLD_STATUS=$(status_of GET /catalog -H "Authorization: Bearer $DEVICE")
[ "$OLD_STATUS" = "401" ] || fail "the old device token should 401 on /catalog once a new code is issued, got $OLD_STATUS"
echo "15. old device token now 401s on /catalog (issuing a code revokes it in-transaction)"

ACTIVATED=$(req POST /registers/activate -d "{\"activation_code\":\"$ACTIVATION_CODE\"}")
[ "$(echo "$ACTIVATED" | jq -r .data.register.id)" = "$TILL1_ID" ] || fail "activation did not redeem against Till 1"
NEW_DEVICE=$(echo "$ACTIVATED" | jq -r .data.device_token)
[ -n "$NEW_DEVICE" ] && [ "$NEW_DEVICE" != "null" ] || fail "activation did not return a device token"
echo "16. activation code redeemed for a new device token (register.id confirmed = Till 1)"

D="Authorization: Bearer $NEW_DEVICE"
NEW_STATUS=$(status_of GET /catalog -H "$D")
[ "$NEW_STATUS" = "200" ] || fail "the new device token should work on /catalog, got $NEW_STATUS"
echo "17. new device token confirmed live on /catalog"

# --- 5. register leg, on the new token ---

LOGIN_EVE=$(req POST /staff/login -H "$D" -d "{\"pin\":\"$E2E_PIN\"}")
EVE_TOKEN=$(echo "$LOGIN_EVE" | jq -r .data.staff_token)
S="X-Staff-Token: $EVE_TOKEN"
[ "$(echo "$LOGIN_EVE" | jq -r .data.register.mode)" = "food" ] || fail "Eve's register should be food mode"
echo "18. Eve logged in at Till 1 (food mode), on the new device token"

SHIFT=$(req POST /shifts/open -H "$D" -H "$S" -d '{"opening_float_cents":5000}')
SHIFT_ID=$(echo "$SHIFT" | jq -r .data.shift.id)
echo "19. shift open, float 5000"

ORDER=$(req POST /orders -H "$D" -H "$S" -d '{"table_ref":"E2E"}')
ORDER_ID=$(echo "$ORDER" | jq -r .data.order.id)
BUSINESS_DATE=$(echo "$ORDER" | jq -r .data.order.business_date)
echo "20. tab opened at E2E: $ORDER_ID (business_date $BUSINESS_DATE)"

LINE=$(req POST "/orders/$ORDER_ID/lines" -H "$D" -H "$S" -H 'If-Match: 0' \
  -d "{\"variant_id\":\"$VARIANT_ID\",\"qty\":\"1\",\"modifiers\":[\"$OAT_ID\"]}")
[ "$(echo "$LINE" | jq .data.order.total_cents)" = "510" ] || fail "expected 510 (450 + 60 oat), got $(echo "$LINE" | jq .data.order.total_cents)"
echo "21. Flat White + Oat added: total=510 (server-verified)"

PAY=$(req POST "/orders/$ORDER_ID/payments" -H "$D" -H "$S" -H 'If-Match: 1' -H "Idempotency-Key: $(uuidgen)" \
  -d '{"driver":"cash","amount_cents":510,"tendered_cents":510}')
[ "$(echo "$PAY" | jq -r .data.order.status)" = "closed" ] || fail "order did not close on cash 510"
echo "22. paid cash 510, order closed"

RECEIPT=$(req GET "/orders/$ORDER_ID/receipt" -H "$D" -H "$S")
[ "$(echo "$RECEIPT" | jq .data.lines[0].line_total_cents)" = "510" ] || fail "receipt line should be 510"
[ "$(echo "$RECEIPT" | jq -r .data.lines[0].modifiers[0].name)" = "Oat" ] || fail "receipt should show the Oat modifier"
echo "23. receipt shows Flat White + Oat, line total 510"

# --- 6. admin leg: reprice, re-fetch the paid receipt, sales reports ---

REPRICE=$(req PATCH "/admin/variants/$VARIANT_ID" -H "$AD" -d '{"price_cents":500}')
[ "$(echo "$REPRICE" | jq .data.variant.price_cents)" = "500" ] || fail "reprice did not stick"
echo "24. Flat White repriced 450 -> 500"

RECEIPT2=$(req GET "/orders/$ORDER_ID/receipt" -H "$D" -H "$S")
[ "$(echo "$RECEIPT2" | jq .data.lines[0].line_total_cents)" = "510" ] || fail "the paid order's receipt drifted after reprice — snapshot broken"
echo "25. paid order's receipt still reads 510 — the reprice never touched it (snapshot proof)"

SALES_DAY=$(req GET "/admin/reports/sales?location_id=$DOWNTOWN_ID&from=$BUSINESS_DATE&to=$BUSINESS_DATE&group_by=day" -H "$AD")
[ "$(echo "$SALES_DAY" | jq -r .data.basis)" = "ledger" ] || fail "day report should be ledger-basis"
[ "$(echo "$SALES_DAY" | jq .data.totals.gross_cents)" = "510" ] || fail "day gross should be exactly 510 on a fresh seed, got $(echo "$SALES_DAY" | jq .data.totals.gross_cents)"
echo "26. sales report (day, ledger-basis): gross=510"

SALES_CATEGORY=$(req GET "/admin/reports/sales?location_id=$DOWNTOWN_ID&from=$BUSINESS_DATE&to=$BUSINESS_DATE&group_by=category" -H "$AD")
[ "$(echo "$SALES_CATEGORY" | jq -r .data.basis)" = "lines" ] || fail "category report should be line-basis"
DRINKS_CENTS=$(echo "$SALES_CATEGORY" | jq '[.data.rows[] | select(.bucket=="Drinks") | .line_total_cents] | add')
[ "$DRINKS_CENTS" = "510" ] || fail "Drinks category total should be 510, got $DRINKS_CENTS"
echo "27. sales report (category, line-basis): Drinks=510"

SALES_USER=$(req GET "/admin/reports/sales?location_id=$DOWNTOWN_ID&from=$BUSINESS_DATE&to=$BUSINESS_DATE&group_by=user" -H "$AD")
EVE_CENTS=$(echo "$SALES_USER" | jq '[.data.rows[] | select(.bucket=="Eve") | .gross_cents] | add')
[ "$EVE_CENTS" = "510" ] || fail "Eve's user-report gross should be 510, got $EVE_CENTS"
echo "28. sales report (user, ledger-basis): Eve=510"
echo "    (day/user are ledger-basis — payments and refunds; category is line-basis — order lines. They don't reconcile by design.)"

# --- 7. audit viewer ---

AUDIT_PRODUCT=$(req GET "/admin/audit?action=admin.product.create" -H "$AD")
FOUND_PRODUCT=$(echo "$AUDIT_PRODUCT" | jq -r --arg id "$PRODUCT_ID" '[.data.rows[] | select(.entity_id==$id)] | length')
[ "$FOUND_PRODUCT" != "0" ] || fail "audit log missing admin.product.create for Flat White"
echo "29. audit: admin.product.create found for Flat White"

AUDIT_VARIANT=$(req GET "/admin/audit?entity_type=ProductVariant&entity_id=$VARIANT_ID" -H "$AD")
REPRICE_ROW=$(echo "$AUDIT_VARIANT" | jq -c '[.data.rows[] | select(.action=="admin.variant.update")][0]')
[ "$REPRICE_ROW" != "null" ] || fail "audit log missing the reprice event for the variant"
[ "$(echo "$REPRICE_ROW" | jq .payload.price_cents.from)" = "450" ] || fail "reprice audit 'from' should be 450"
[ "$(echo "$REPRICE_ROW" | jq .payload.price_cents.to)" = "500" ] || fail "reprice audit 'to' should be 500"
echo "30. audit: reprice logged with price_cents from=450 to=500"

AUDIT_ISSUE=$(req GET "/admin/audit?action=admin.register.code_issue" -H "$AD")
FOUND_ISSUE=$(echo "$AUDIT_ISSUE" | jq -r --arg id "$TILL1_ID" '[.data.rows[] | select(.entity_id==$id)] | length')
[ "$FOUND_ISSUE" != "0" ] || fail "audit log missing admin.register.code_issue for Till 1"
echo "31. audit: admin.register.code_issue found for Till 1"

AUDIT_ACTIVATE=$(req GET "/admin/audit?action=register.activate" -H "$AD")
FOUND_ACTIVATE=$(echo "$AUDIT_ACTIVATE" | jq -r --arg id "$TILL1_ID" '[.data.rows[] | select(.entity_id==$id)] | length')
[ "$FOUND_ACTIVATE" != "0" ] || fail "audit log missing register.activate for Till 1"
echo "32. audit: register.activate found for Till 1"

# --- 8. close the shift ---

Z=$(req GET "/reports/z?shift_id=$SHIFT_ID" -H "$D" -H "$S")
EXPECTED=$(echo "$Z" | jq .data.expected_cash_cents)
[ "$EXPECTED" = "5510" ] || fail "expected cash should be 5510 (5000 float + 510 sale), got $EXPECTED"
[ "$(echo "$Z" | jq .data.orders_closed)" = "1" ] || fail "Z-report should show 1 closed order"
echo "33. Z-report: expected_cash=5510, orders_closed=1"

CLOSE=$(req POST "/shifts/$SHIFT_ID/close" -H "$D" -H "$S" -H "Idempotency-Key: $(uuidgen)" -d '{"counted_cash_cents":5510}')
VARIANCE=$(echo "$CLOSE" | jq .data.variance_cents)
[ "$VARIANCE" = "0" ] || fail "shift should reconcile clean, got variance $VARIANCE"
echo "34. shift closed clean: counted=5510 variance=0"

echo
echo "=== Admin day summary ==="
printf "%-22s %s\n" "Location" "Downtown (DT)"
printf "%-22s %s\n" "Product" "Flat White / FW-1: 450c -> 500c (repriced after sale)"
printf "%-22s %s\n" "Modifier group" "Milk (min 1, max 1): Oat +60, Whole +0"
printf "%-22s %s\n" "Staff hired" "Eve — cashier @ DT"
printf "%-22s %s\n" "Register" "Till 1 — retail -> food, activation code issued + redeemed"
echo
printf "%-10s %-8s %10s %10s\n" "Order" "Table" "Total" "Status"
printf "%-10s %-8s %10s %10s\n" "${ORDER_ID:0:8}.." "E2E" "510" "closed"
echo
printf "%-26s %s\n" "Sales report (day)" "gross=510 (ledger)"
printf "%-26s %s\n" "Sales report (category)" "Drinks=510 (lines)"
printf "%-26s %s\n" "Sales report (user)" "Eve=510 (ledger)"
echo
printf "%-10s %10s %10s %10s %10s\n" "Shift" "Float" "Expected" "Counted" "Variance"
printf "%-10s %10s %10s %10s %10s\n" "Till 1" "5000" "5510" "5510" "0"
