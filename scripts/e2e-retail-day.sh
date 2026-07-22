#!/bin/bash
#
# The M4 'retail bad day' — the milestone's end-to-end proof, runnable against a
# freshly seeded stack (grocery catalog), at the Manila Grocery (GRC): void, discount,
# cash change, card sale, refund with restock, payout, Z-report, zero-variance close.
# GRC is prices_include_tax=true, so totals ARE the shelf prices — VAT is derived
# inside them, never added on top. Set DEVICE to the seeder-printed GRC / Till 1 token.
#
set -e
API=http://127.0.0.1:8000/api/v1
DEVICE="${POS_DEVICE_TOKEN:?set POS_DEVICE_TOKEN to the GRC / Till 1 device token printed by php artisan migrate:fresh --seed}"
D="Authorization: Bearer $DEVICE"
J='Content-Type: application/json'

STAFF=$(curl -sf -X POST $API/staff/login -H "$D" -H "$J" -d '{"pin":"2222"}' | jq -r .data.staff_token)
S="X-Staff-Token: $STAFF"
echo "1. Bob (supervisor) logged in"

SHIFT_ID=$(curl -sf -X POST $API/shifts/open -H "$D" -H "$S" -H "$J" -d '{"opening_float_cents":20000}' | jq -r .data.shift.id)
echo "2. shift open, float 20000"

DISC_ID=$(curl -sf $API/catalog -H "$D" | jq -r '.data.discounts[] | select(.name=="10% off") | .id')
VN=$(curl -sf "$API/catalog/lookup?barcode=4809990000016" -H "$D" | jq -r .data.variant.id)   # Nescafé Classic 100g, 18500
VP=$(curl -sf "$API/catalog/lookup?barcode=4809990000023" -H "$D" | jq -r .data.variant.id)   # Lucky Me! Pancit Canton, 1500
VT=$(curl -sf "$API/catalog/lookup?barcode=4809990000030" -H "$D" | jq -r .data.variant.id)   # Century Tuna 420g, 25000

# Sale A: coffee + noodles, void the noodles, 10% off, cash
OID=$(curl -sf -X POST $API/orders -H "$D" -H "$S" -H "$J" -d '{}' | jq -r .data.order.id)
R=$(curl -sf -X POST $API/orders/$OID/lines -H "$D" -H "$S" -H 'If-Match: 0' -H "$J" -d "{\"variant_id\":\"$VN\",\"qty\":\"1\"}")
L1=$(echo $R | jq -r .data.line.id)
R=$(curl -sf -X POST $API/orders/$OID/lines -H "$D" -H "$S" -H 'If-Match: 1' -H "$J" -d "{\"variant_id\":\"$VP\",\"qty\":\"1\"}")
L2=$(echo $R | jq -r .data.line.id)
echo "3. sale A: coffee + noodles, total=$(echo $R | jq .data.order.total_cents) (expect 20000)"

R=$(curl -sf -X DELETE $API/orders/$OID/lines/$L2 -H "$D" -H "$S" -H 'If-Match: 2' -H "$J" -d '{"reason":"mis-scan"}')
echo "4. line voided: total=$(echo $R | jq .data.order.total_cents) (expect 18500)"

R=$(curl -sf -X POST $API/orders/$OID/discounts -H "$D" -H "$S" -H 'If-Match: 3' -H "$J" -d "{\"discount_id\":\"$DISC_ID\",\"order_line_id\":null,\"reason\":\"manager comp\"}")
ODISC=$(echo $R | jq .data.order.discount_cents); TOTAL_A=$(echo $R | jq .data.order.total_cents)
echo "5. 10% off applied: discount=$ODISC total=$TOTAL_A (expect 1850 / 16650); rows=$(echo $R | jq '.data.order.discounts | length')"

PAY=$(curl -sf -X POST $API/orders/$OID/payments -H "$D" -H "$S" -H 'If-Match: 4' -H "Idempotency-Key: $(uuidgen)" -H "$J" -d "{\"driver\":\"cash\",\"amount_cents\":$TOTAL_A,\"tendered_cents\":20000}")
NUM_A=$(echo $PAY | jq -r .data.order.number)
echo "6. cash paid: change=$(echo $PAY | jq .data.payment.change_cents) (expect 3350) status=$(echo $PAY | jq -r .data.order.status)"

# Sale B: card
OID2=$(curl -sf -X POST $API/orders -H "$D" -H "$S" -H "$J" -d '{}' | jq -r .data.order.id)
R=$(curl -sf -X POST $API/orders/$OID2/lines -H "$D" -H "$S" -H 'If-Match: 0' -H "$J" -d "{\"variant_id\":\"$VT\",\"qty\":\"1\"}")
PAY2=$(curl -sf -X POST $API/orders/$OID2/payments -H "$D" -H "$S" -H 'If-Match: 1' -H "Idempotency-Key: $(uuidgen)" -H "$J" -d "{\"driver\":\"external_card\",\"amount_cents\":$(echo $R | jq .data.order.total_cents),\"reference\":\"auth 004321\"}")
echo "7. card sale: $(echo $PAY2 | jq .data.payment.amount_cents)c (expect 25000) ref=$(echo $PAY2 | jq -r .data.payment.reference) change=$(echo $PAY2 | jq .data.payment.change_cents) (expect null)"

# Refund sale A (find by number, then refund line 1 with restock)
FOUND=$(curl -sf "$API/orders?number=$NUM_A" -H "$D" -H "$S" | jq '.data.orders | length')
REF=$(curl -sf -X POST $API/refunds -H "$D" -H "$S" -H "Idempotency-Key: $(uuidgen)" -H "$J" -d "{\"original_order_id\":\"$OID\",\"driver\":\"cash\",\"reason\":\"faulty\",\"lines\":[{\"original_order_line_id\":\"$L1\",\"qty\":\"1\",\"restock\":true}]}")
echo "8. lookup found=$FOUND; refund amount=$(echo $REF | jq .data.refund.amount_cents) (expect 16650 = the discounted, VAT-inclusive line)"

curl -sf -X POST $API/shifts/$SHIFT_ID/cash-movements -H "$D" -H "$S" -H "$J" -d '{"kind":"payout","amount_cents":300,"reason":"window cleaner"}' > /dev/null
echo "9. payout 300 recorded"

Z=$(curl -sf "$API/reports/z?shift_id=$SHIFT_ID" -H "$D" -H "$S")
echo "10. Z: sales=$(echo $Z | jq -c .data.sales_by_driver) refunds=$(echo $Z | jq -c .data.refunds_by_driver) payout=$(echo $Z | jq .data.movements.payout) orders_closed=$(echo $Z | jq .data.orders_closed) expected=$(echo $Z | jq .data.expected_cash_cents)"

EXPECTED=$(echo $Z | jq .data.expected_cash_cents)
CLOSE=$(curl -sf -X POST $API/shifts/$SHIFT_ID/close -H "$D" -H "$S" -H "Idempotency-Key: $(uuidgen)" -H "$J" -d "{\"counted_cash_cents\":$EXPECTED}")
echo "11. closed: variance=$(echo $CLOSE | jq .data.variance_cents) (expect 0)"
