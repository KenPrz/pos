#!/bin/bash
#
# The M5 'lunch service' — the milestone's end-to-end proof, runnable against a
# freshly seeded stack: two tabs on two registers, modifiers (with a repeated one),
# a fired course, a qty bump on a fired line, a transfer, a three-way split paid
# across cash and card, a forced-and-approved drawer variance, and a clean close.
# Set POS_DEVICE_TOKEN (Till 2, food) and POS_DEVICE_TOKEN_2 (Till 1) first — both
# printed by `php artisan migrate:fresh --seed`. Never a token literal in this file.
#
set -e
API=http://127.0.0.1:8000/api/v1
DEVICE="${POS_DEVICE_TOKEN:?set POS_DEVICE_TOKEN to the Till 2 (food) device token, printed by php artisan migrate:fresh --seed}"
DEVICE2="${POS_DEVICE_TOKEN_2:?set POS_DEVICE_TOKEN_2 to the Till 1 device token, printed by php artisan migrate:fresh --seed}"
D="Authorization: Bearer $DEVICE"
D2="Authorization: Bearer $DEVICE2"
J='Content-Type: application/json'

fail() { echo "FAIL: $1" >&2; exit 1; }

# --- staff on two registers ---
LOGIN_A=$(curl -sf -X POST $API/staff/login -H "$D" -H "$J" -d '{"pin":"1111"}')
ALICE=$(echo "$LOGIN_A" | jq -r .data.staff_token)
A="X-Staff-Token: $ALICE"
TILL2=$(echo "$LOGIN_A" | jq -r .data.register.id)
[ "$(echo "$LOGIN_A" | jq -r .data.register.mode)" = "food" ] || fail "Till 2 is not in food mode"
echo "1. Alice (cashier) logged in at Till 2 (food mode, $TILL2)"

LOGIN_B1=$(curl -sf -X POST $API/staff/login -H "$D2" -H "$J" -d '{"pin":"2222"}')
BOB1=$(echo "$LOGIN_B1" | jq -r .data.staff_token)
B1="X-Staff-Token: $BOB1"
TILL1=$(echo "$LOGIN_B1" | jq -r .data.register.id)
[ "$(echo "$LOGIN_B1" | jq -r .data.register.mode)" = "retail" ] || fail "Till 1 is not in retail mode"
echo "2. Bob (supervisor) logged in at Till 1 (retail mode, $TILL1)"

SHIFT2=$(curl -sf -X POST $API/shifts/open -H "$D" -H "$A" -H "$J" -d '{"opening_float_cents":10000}' | jq -r .data.shift.id)
echo "3. Till 2 shift open, float 10000"

SHIFT1=$(curl -sf -X POST $API/shifts/open -H "$D2" -H "$B1" -H "$J" -d '{"opening_float_cents":10000}' | jq -r .data.shift.id)
echo "4. Till 1 shift open, float 10000"

# --- catalog lookups ---
CATALOG=$(curl -sf "$API/catalog" -H "$D")
LATTE=$(echo "$CATALOG" | jq -r '.data.variants[] | select(.sku=="LATTE") | .id')
CHEDDAR=$(echo "$CATALOG" | jq -r '.data.variants[] | select(.sku=="CHEESE-KG") | .id')
TSHIRT_M=$(echo "$CATALOG" | jq -r '.data.variants[] | select(.sku=="TSHIRT-BLUE-M") | .id')
OAT=$(echo "$CATALOG" | jq -r '.data.modifiers[] | select(.name=="Oat") | .id')
WHOLE=$(echo "$CATALOG" | jq -r '.data.modifiers[] | select(.name=="Whole") | .id')
EXTRA_SHOT=$(echo "$CATALOG" | jq -r '.data.modifiers[] | select(.name=="Extra shot") | .id')
echo "5. catalog resolved: latte, cheddar, t-shirt/M, oat, whole, extra shot"

# ============================================================
# Tab A (Till 2 / Alice): table T1, a fired course, a qty bump, then a transfer
# ============================================================

OA=$(curl -sf -X POST $API/orders -H "$D" -H "$A" -H "$J" -d '{"table_ref":"T1"}')
ORDER_A=$(echo "$OA" | jq -r .data.order.id)
[ "$(echo "$OA" | jq -r .data.order.table_ref)" = "T1" ] || fail "Tab A table_ref not set"
echo "6. Tab A opened at T1: $ORDER_A"

# latte x2, oat milk + a repeated modifier (double shot = 'Extra shot' selected twice —
# 03-api.md: modifiers[] accepts repeats). Line math re-derived from the response's own
# fields rather than hardcoded, so this proves the *server* applied the modifiers.
R=$(curl -sf -X POST $API/orders/$ORDER_A/lines -H "$D" -H "$A" -H 'If-Match: 0' -H "$J" \
  -d "{\"variant_id\":\"$LATTE\",\"qty\":\"2\",\"modifiers\":[\"$OAT\",\"$EXTRA_SHOT\",\"$EXTRA_SHOT\"]}")
LINE1=$(echo "$R" | jq -r .data.line.id)
MODCOUNT=$(echo "$R" | jq '.data.line.modifiers | length')
[ "$MODCOUNT" = "3" ] || fail "expected 3 modifier rows (oat + 2x extra shot), got $MODCOUNT"
UNIT=$(echo "$R" | jq .data.line.unit_price_cents)
MODSUM=$(echo "$R" | jq '[.data.line.modifiers[].price_delta_cents] | add')
QTY=$(echo "$R" | jq -r .data.line.qty | cut -d. -f1)
EXPECT_LINE_TOTAL=$(( (UNIT + MODSUM) * QTY ))
ACTUAL_LINE_TOTAL=$(echo "$R" | jq .data.line.line_total_cents)
[ "$ACTUAL_LINE_TOTAL" = "$EXPECT_LINE_TOTAL" ] || fail "modifier math: expected $EXPECT_LINE_TOTAL got $ACTUAL_LINE_TOTAL"
echo "7. course 1: latte x2 + oat + double shot — unit=$UNIT modsum=$MODSUM line_total=$ACTUAL_LINE_TOTAL (server-verified)"

R=$(curl -sf -X PATCH $API/orders/$ORDER_A/lines/$LINE1/prep -H "$D" -H "$A" -H "$J" -d '{"state":"in_progress"}')
[ "$(echo "$R" | jq -r .data.line.prep_state)" = "in_progress" ] || fail "course 1 not fired"
[ "$(echo "$R" | jq -r .data.order.version)" = "1" ] || fail "prep must not bump order version"
echo "8. course 1 fired (in_progress); order version unchanged (prep is lock-free)"

R=$(curl -sf -X POST $API/orders/$ORDER_A/lines -H "$D" -H "$A" -H 'If-Match: 1' -H "$J" \
  -d "{\"variant_id\":\"$CHEDDAR\",\"qty\":\"1\"}")
[ "$(echo "$R" | jq -r .data.order.version)" = "2" ] || fail "second course add-line should bump to version 2"
echo "9. course 2 added (cheddar, no modifiers required): total=$(echo "$R" | jq .data.order.total_cents)"

R=$(curl -sf -X PATCH $API/orders/$ORDER_A/lines/$LINE1 -H "$D" -H "$A" -H 'If-Match: 2' -H "$J" -d '{"qty":"3"}')
[ "$(echo "$R" | jq -r .data.line.qty)" = "3.000" ] || fail "qty bump 2->3 did not stick"
[ "$(echo "$R" | jq -r .data.order.version)" = "3" ] || fail "qty bump should bump to version 3"
TOTAL_A_PRE_TRANSFER=$(echo "$R" | jq .data.order.total_cents)
echo "10. course 1 bumped 2->3 (increase on a fired line, no supervisor needed): total=$TOTAL_A_PRE_TRANSFER"

# ============================================================
# Tab B (Till 2 / Alice): table T2, three lines, split three ways
# ============================================================

OB=$(curl -sf -X POST $API/orders -H "$D" -H "$A" -H "$J" -d '{"table_ref":"T2"}')
ORDER_B=$(echo "$OB" | jq -r .data.order.id)
echo "11. Tab B opened at T2: $ORDER_B"

curl -sf -X POST $API/orders/$ORDER_B/lines -H "$D" -H "$A" -H 'If-Match: 0' -H "$J" \
  -d "{\"variant_id\":\"$LATTE\",\"qty\":\"1\",\"modifiers\":[\"$WHOLE\"]}" > /dev/null
curl -sf -X POST $API/orders/$ORDER_B/lines -H "$D" -H "$A" -H 'If-Match: 1' -H "$J" \
  -d "{\"variant_id\":\"$CHEDDAR\",\"qty\":\"1\"}" > /dev/null
R=$(curl -sf -X POST $API/orders/$ORDER_B/lines -H "$D" -H "$A" -H 'If-Match: 2' -H "$J" \
  -d "{\"variant_id\":\"$TSHIRT_M\",\"qty\":\"1\"}")
[ "$(echo "$R" | jq -r .data.order.version)" = "3" ] || fail "Tab B should be at version 3 after three lines"
TOTAL_B=$(echo "$R" | jq .data.order.total_cents)
echo "12. Tab B: three lines (latte, cheddar, t-shirt), total=$TOTAL_B"

SPLIT=$(curl -sf -X POST $API/orders/$ORDER_B/split -H "$D" -H "$A" -H 'If-Match: 3' -H "Idempotency-Key: $(uuidgen)" -H "$J" -d '{"ways":3}')
CHILD0=$(echo "$SPLIT" | jq -r '.data.orders[0].id')
CHILD1=$(echo "$SPLIT" | jq -r '.data.orders[1].id')
CHILD2=$(echo "$SPLIT" | jq -r '.data.orders[2].id')
T0=$(echo "$SPLIT" | jq -r '.data.orders[0].total_cents')
T1=$(echo "$SPLIT" | jq -r '.data.orders[1].total_cents')
T2=$(echo "$SPLIT" | jq -r '.data.orders[2].total_cents')
SUM_CHILDREN=$((T0 + T1 + T2))
[ "$SUM_CHILDREN" = "$TOTAL_B" ] || fail "split children ($SUM_CHILDREN) do not sum to original Tab B total ($TOTAL_B)"
echo "13. Tab B split 3 ways: children totals $T0 + $T1 + $T2 = $SUM_CHILDREN (matches original $TOTAL_B)"

LATTE_QTYS=$(echo "$SPLIT" | jq -r '.data.orders[].lines[] | select(.sku=="LATTE") | .qty')
QTY_SUM=$(echo "$LATTE_QTYS" | awk '{s+=$1} END{printf "%.3f", s}')
[ "$QTY_SUM" = "1.000" ] || fail "split latte qtys ($LATTE_QTYS) do not sum back to 1.000"
echo "14. split latte line qtys: $(echo $LATTE_QTYS | tr '\n' ' ')(fractional, allocator-exact, sum $QTY_SUM)"

curl -sf -X POST $API/orders/$CHILD0/payments -H "$D" -H "$A" -H 'If-Match: 0' -H "Idempotency-Key: $(uuidgen)" -H "$J" \
  -d "{\"driver\":\"cash\",\"amount_cents\":$T0,\"tendered_cents\":$T0}" \
  | jq -e '.data.order.status == "closed"' > /dev/null || fail "split child 1 (cash) did not close"
curl -sf -X POST $API/orders/$CHILD1/payments -H "$D" -H "$A" -H 'If-Match: 0' -H "Idempotency-Key: $(uuidgen)" -H "$J" \
  -d "{\"driver\":\"cash\",\"amount_cents\":$T1,\"tendered_cents\":$T1}" \
  | jq -e '.data.order.status == "closed"' > /dev/null || fail "split child 2 (cash) did not close"
curl -sf -X POST $API/orders/$CHILD2/payments -H "$D" -H "$A" -H 'If-Match: 0' -H "Idempotency-Key: $(uuidgen)" -H "$J" \
  -d "{\"driver\":\"external_card\",\"amount_cents\":$T2,\"reference\":\"auth 998877\"}" \
  | jq -e '.data.order.status == "closed"' > /dev/null || fail "split child 3 (card) did not close"
echo "15. all three children paid and closed (cash, cash, card) — total cash from the split: $((T0 + T1))"

RECEIPT=$(curl -sf "$API/orders/$CHILD0/receipt" -H "$D" -H "$A")
RECEIPT_QTY=$(echo "$RECEIPT" | jq -r '.data.lines[] | select(.sku=="LATTE") | .qty')
[ "$RECEIPT_QTY" != "1.000" ] || fail "receipt should render a fractional split qty, got $RECEIPT_QTY"
echo "16. receipt renders the fractional qty: $RECEIPT_QTY"

# ============================================================
# Transfer Tab A to Till 1, then pay it there
# ============================================================

TRANSFER=$(curl -sf -X POST $API/orders/$ORDER_A/transfer -H "$D2" -H "$B1" -H 'If-Match: 3' -H "$J" -d "{\"register_id\":\"$TILL1\"}")
[ "$(echo "$TRANSFER" | jq -r .data.order.register_id)" = "$TILL1" ] || fail "Tab A did not move to Till 1"
echo "17. Tab A transferred to Till 1 (supervisor Bob), register_id now $TILL1"

PAY_A=$(curl -sf -X POST $API/orders/$ORDER_A/payments -H "$D2" -H "$B1" -H 'If-Match: 4' -H "Idempotency-Key: $(uuidgen)" -H "$J" \
  -d "{\"driver\":\"cash\",\"amount_cents\":$TOTAL_A_PRE_TRANSFER,\"tendered_cents\":$TOTAL_A_PRE_TRANSFER}")
[ "$(echo "$PAY_A" | jq -r .data.order.status)" = "closed" ] || fail "Tab A did not close on Till 1"
echo "18. Tab A paid cash on Till 1 (it's that drawer's tab now): $TOTAL_A_PRE_TRANSFER"

# ============================================================
# Force a variance on Till 2, approve it from Till 1, then reconcile Till 1 clean
# ============================================================

LOGIN_B2=$(curl -sf -X POST $API/staff/login -H "$D" -H "$J" -d '{"pin":"2222"}')
BOB2=$(echo "$LOGIN_B2" | jq -r .data.staff_token)
B2="X-Staff-Token: $BOB2"
echo "19. Bob also logs in at Till 2 (supervisor-only: recording a payout there)"

curl -sf -X POST $API/shifts/$SHIFT2/cash-movements -H "$D" -H "$B2" -H "$J" \
  -d '{"kind":"payout","amount_cents":300,"reason":"linen service"}' > /dev/null
echo "20. payout 300 recorded on Till 2"

Z2=$(curl -sf "$API/reports/z?shift_id=$SHIFT2" -H "$D" -H "$A")
EXPECTED2=$(echo "$Z2" | jq .data.expected_cash_cents)
# config/pos.php's variance_approval_threshold_cents is 500 — a plain 300 payout would
# reconcile clean if counted matched expected, so the drawer is *also* blind-counted
# 700 short on top of the payout to land past the threshold and exercise approval.
COUNTED2=$((EXPECTED2 - 700))
CLOSE2=$(curl -sf -X POST $API/shifts/$SHIFT2/close -H "$D" -H "$A" -H "Idempotency-Key: $(uuidgen)" -H "$J" -d "{\"counted_cash_cents\":$COUNTED2}")
VAR2=$(echo "$CLOSE2" | jq .data.variance_cents)
[ "$VAR2" = "-700" ] || fail "expected Till 2 variance -700, got $VAR2"
[ "$(echo "$CLOSE2" | jq -r .data.requires_approval)" = "true" ] || fail "Till 2's variance should require approval"
echo "21. Till 2 closed: expected=$EXPECTED2 counted=$COUNTED2 variance=$VAR2 requires_approval=true"

# Approving from Till 2 itself would 401 here — closing just revoked that register's
# staff sessions (docs/06-roadmap.md, M5 notes). Till 1 is still open, so Bob approves
# from there instead.
APPROVE=$(curl -sf -X POST $API/shifts/$SHIFT2/approve-variance -H "$D2" -H "$B1" -H "$J" -d '{}')
APPROVED_BY=$(echo "$APPROVE" | jq -r .data.shift.variance_approved_by)
[ "$APPROVED_BY" != "null" ] && [ -n "$APPROVED_BY" ] || fail "Till 2's variance was not approved"
[ "$(echo "$APPROVE" | jq -r .data.shift.variance_approved_at)" != "null" ] || fail "variance_approved_at not set"
echo "22. Till 2's variance approved by Bob from Till 1 (variance_approved_by=$APPROVED_BY)"

Z1=$(curl -sf "$API/reports/z?shift_id=$SHIFT1" -H "$D2" -H "$B1")
EXPECTED1=$(echo "$Z1" | jq .data.expected_cash_cents)
EXPECT1_CALC=$((10000 + TOTAL_A_PRE_TRANSFER))
[ "$EXPECTED1" = "$EXPECT1_CALC" ] || fail "Till 1 expected cash should include Tab A's cash: expected $EXPECT1_CALC got $EXPECTED1"
CLOSE1=$(curl -sf -X POST $API/shifts/$SHIFT1/close -H "$D2" -H "$B1" -H "Idempotency-Key: $(uuidgen)" -H "$J" -d "{\"counted_cash_cents\":$EXPECTED1}")
VAR1=$(echo "$CLOSE1" | jq .data.variance_cents)
[ "$VAR1" = "0" ] || fail "Till 1 should reconcile exactly, got $VAR1"
echo "23. Till 1 closed: expected=$EXPECTED1 (float 10000 + Tab A's $TOTAL_A_PRE_TRANSFER) variance=$VAR1"

echo
echo "=== Lunch service summary ==="
printf "%-10s %-8s %10s %10s\n" "Order" "Table" "Total" "Status"
printf "%-10s %-8s %10s %10s\n" "Tab A" "T1" "$TOTAL_A_PRE_TRANSFER" "closed"
printf "%-10s %-8s %10s %10s\n" "Tab B/1" "T2" "$T0" "closed"
printf "%-10s %-8s %10s %10s\n" "Tab B/2" "T2" "$T1" "closed"
printf "%-10s %-8s %10s %10s\n" "Tab B/3" "T2" "$T2" "closed"
echo
printf "%-10s %10s %10s %10s %10s\n" "Shift" "Float" "Expected" "Counted" "Variance"
printf "%-10s %10s %10s %10s %10s\n" "Till 2" "10000" "$EXPECTED2" "$COUNTED2" "$VAR2 (approved)"
printf "%-10s %10s %10s %10s %10s\n" "Till 1" "10000" "$EXPECTED1" "$EXPECTED1" "$VAR1"
