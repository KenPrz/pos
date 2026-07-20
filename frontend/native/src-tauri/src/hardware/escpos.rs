//! Known limitation: this module assumes receipt text is ASCII. `row()` budgets width
//! in `.chars()` but the printer receives raw UTF-8 bytes with no codepage
//! transcoding — see the comment on `row()` for the two failure modes and the upgrade
//! path. Accepted for now because only the mock driver ships.

use serde::Deserialize;

/// Mirrors backend/app/Http/Resources/ReceiptResource.php. Only the fields we put on
/// paper are modelled; serde ignores the rest, so the server can add fields freely.
///
/// Money is i64 cents, never a float — same rule as every other layer.
#[derive(Debug, Clone, Deserialize)]
pub struct Receipt {
    pub business: Business,
    pub location: LocationInfo,
    pub order: OrderInfo,
    pub lines: Vec<Line>,
    pub totals: Totals,
}

#[derive(Debug, Clone, Deserialize)]
pub struct Business {
    pub name: String,
}

#[derive(Debug, Clone, Deserialize)]
pub struct LocationInfo {
    pub name: String,
    #[serde(default)]
    pub header: Option<String>,
    #[serde(default)]
    pub footer: Option<String>,
}

#[derive(Debug, Clone, Deserialize)]
pub struct OrderInfo {
    pub number: String,
    pub business_date: String,
    #[serde(default)]
    pub cashier: Option<String>,
}

#[derive(Debug, Clone, Deserialize)]
pub struct Line {
    pub name: String,
    /// Quantity is a decimal STRING on the wire — numeric(12,3) does not survive
    /// IEEE-754. We only ever compare and print it, never do arithmetic on it.
    pub qty: String,
    pub line_total_cents: i64,
    #[serde(default)]
    pub modifiers: Vec<Modifier>,
}

#[derive(Debug, Clone, Deserialize)]
pub struct Modifier {
    pub name: String,
    #[serde(default)]
    pub price_delta_cents: i64,
}

#[derive(Debug, Clone, Deserialize)]
pub struct Totals {
    pub subtotal_cents: i64,
    #[serde(default)]
    pub discount_cents: i64,
    pub tax_cents: i64,
    pub total_cents: i64,
}

/// 80mm paper at font A. 58mm paper is 32; a printer setting can select it later.
const WIDTH: usize = 42;

const INIT: [u8; 2] = [0x1B, 0x40];
const CUT: [u8; 3] = [0x1D, 0x56, 0x00];
const ALIGN_CENTRE: [u8; 3] = [0x1B, 0x61, 0x01];
const ALIGN_LEFT: [u8; 3] = [0x1B, 0x61, 0x00];

/// ESC p 0 — pulse pin 2. The drawer is kicked BY the printer; it has no computer in it.
pub const DRAWER_KICK: [u8; 5] = [0x1B, 0x70, 0x00, 0x19, 0xFA];

/// Integer-only cents formatting. A float here would eventually print the wrong total.
///
/// Uses `unsigned_abs()` rather than `abs()`: `i64::MIN.abs()` panics in debug builds
/// and silently wraps (staying negative) in release, which would print a corrupted
/// total on the one input where correctness matters most. `unsigned_abs()` widens to
/// `u64`, which holds `i64::MIN`'s magnitude exactly, so there is no overflow to guard.
pub fn money(cents: i64) -> String {
    let sign = if cents < 0 { "-" } else { "" };
    let absolute = cents.unsigned_abs();
    format!("{sign}{}.{:02}", absolute / 100, absolute % 100)
}

/// One line of the receipt: label left, amount hard right. The label is truncated rather
/// than wrapped, because an amount pushed onto its own line reads as a different total.
///
/// ASSUMPTION: receipt text is ASCII. Width here is budgeted in `.chars()`, but the
/// bytes actually sent to the printer (`text.as_bytes()` in `centred`/`line`) are raw
/// UTF-8 with no codepage transcoding. On real hardware a non-ASCII name (e.g. "Café")
/// fails two ways at once: (1) the column budget is wrong, because most ESC/POS
/// printers expect a single-byte codepage like CP437, not UTF-8, so a "1 char" budget
/// doesn't match "1 byte on the wire" the way it does for ASCII; (2) the printer then
/// renders those bytes as garbage, since it isn't decoding UTF-8. Accepted as a known
/// gap while only the mock driver ships. Upgrade path when real hardware arrives:
/// transcode to the printer's codepage before encoding, and budget width in the
/// resulting encoded bytes rather than `.chars()`.
pub fn row(label: &str, amount: &str, width: usize) -> String {
    let amount_len = amount.chars().count();
    if amount_len >= width {
        return amount.chars().take(width).collect();
    }
    let room = width - amount_len;
    let label: String = label.chars().take(room).collect();
    format!(
        "{label}{}{amount}",
        " ".repeat(room - label.chars().count())
    )
}

fn centred(text: &str, out: &mut Vec<u8>) {
    out.extend_from_slice(&ALIGN_CENTRE);
    out.extend_from_slice(text.as_bytes());
    out.push(b'\n');
    out.extend_from_slice(&ALIGN_LEFT);
}

fn line(text: &str, out: &mut Vec<u8>) {
    out.extend_from_slice(text.as_bytes());
    out.push(b'\n');
}

/// Server-provided JSON in, printer bytes out. This function decides NOTHING about what
/// the receipt says — that is the server's job, from snapshot columns, so a reprint next
/// year is identical. See docs/01-architecture.md.
pub fn encode(receipt: &Receipt, currency: &str) -> Vec<u8> {
    let mut out = Vec::new();
    out.extend_from_slice(&INIT);

    centred(&receipt.business.name, &mut out);
    centred(&receipt.location.name, &mut out);
    if let Some(header) = receipt.location.header.as_deref().filter(|h| !h.is_empty()) {
        centred(header, &mut out);
    }
    line("", &mut out);

    line(&format!("Order {}", receipt.order.number), &mut out);
    line(&receipt.order.business_date, &mut out);
    if let Some(cashier) = receipt.order.cashier.as_deref() {
        line(&format!("Served by {cashier}"), &mut out);
    }
    line(&"-".repeat(WIDTH), &mut out);

    for item in &receipt.lines {
        // "1.000" is the overwhelmingly common case and a leading "1 x" is just noise.
        let label = if item.qty == "1.000" {
            item.name.clone()
        } else {
            format!(
                "{} x {}",
                item.qty.trim_end_matches('0').trim_end_matches('.'),
                item.name
            )
        };
        line(&row(&label, &money(item.line_total_cents), WIDTH), &mut out);

        for modifier in &item.modifiers {
            let delta = if modifier.price_delta_cents == 0 {
                String::new()
            } else {
                money(modifier.price_delta_cents)
            };
            line(
                &row(&format!("  {}", modifier.name), &delta, WIDTH),
                &mut out,
            );
        }
    }

    line(&"-".repeat(WIDTH), &mut out);
    line(
        &row("Subtotal", &money(receipt.totals.subtotal_cents), WIDTH),
        &mut out,
    );
    if receipt.totals.discount_cents != 0 {
        // Deliberately not `money(-receipt.totals.discount_cents)`: negating i64::MIN
        // overflows (same failure family as the old `.abs()` above). Format the
        // magnitude directly and hardcode the leading '-', since a discount row is
        // always shown as a deduction regardless of the sign the server sent.
        let magnitude = receipt.totals.discount_cents.unsigned_abs();
        let amount = format!("-{}.{:02}", magnitude / 100, magnitude % 100);
        line(&row("Discount", &amount, WIDTH), &mut out);
    }
    line(
        &row("Tax", &money(receipt.totals.tax_cents), WIDTH),
        &mut out,
    );
    line(
        &row(
            &format!("Total ({currency})"),
            &money(receipt.totals.total_cents),
            WIDTH,
        ),
        &mut out,
    );

    if let Some(footer) = receipt.location.footer.as_deref().filter(|f| !f.is_empty()) {
        line("", &mut out);
        centred(footer, &mut out);
    }

    // Feed clear of the cutter before cutting, or the last line is sliced.
    line("", &mut out);
    line("", &mut out);
    line("", &mut out);
    out.extend_from_slice(&CUT);
    out
}

#[cfg(test)]
mod tests {
    use super::*;

    fn sample() -> Receipt {
        serde_json::from_str(
            r#"{
              "business": { "name": "Dev Trading Co" },
              "location": { "name": "Downtown", "header": "Thanks!", "footer": "See you soon" },
              "order": { "number": "N-0001", "business_date": "2026-07-20", "cashier": "Alice" },
              "lines": [
                { "name": "Flat white", "qty": "1.000", "line_total_cents": 450,
                  "modifiers": [{ "name": "Oat milk", "price_delta_cents": 50 }] },
                { "name": "Croissant", "qty": "2.000", "line_total_cents": 700, "modifiers": [] }
              ],
              "totals": { "subtotal_cents": 1150, "discount_cents": 0, "tax_cents": 115, "total_cents": 1265 }
            }"#,
        )
        .unwrap()
    }

    #[test]
    fn formats_cents_without_floats() {
        assert_eq!(money(0), "0.00");
        assert_eq!(money(5), "0.05");
        assert_eq!(money(1265), "12.65");
        assert_eq!(money(-250), "-2.50");
        assert_eq!(money(100_000_000), "1000000.00");
    }

    #[test]
    fn money_handles_the_i64_extremes_without_panicking() {
        // i64::MIN.abs() panics in debug builds and silently wraps in release — this is
        // the regression test for switching to unsigned_abs().
        assert_eq!(money(i64::MIN), "-92233720368547758.08");
        assert_eq!(money(i64::MAX), "92233720368547758.07");
    }

    #[test]
    fn discount_row_survives_an_i64_min_discount_without_overflowing() {
        // Negating i64::MIN overflows; the discount row must format the magnitude
        // directly rather than going through `money(-discount_cents)`.
        let mut receipt = sample();
        receipt.totals.discount_cents = i64::MIN;
        let text = String::from_utf8_lossy(&encode(&receipt, "USD")).to_string();
        assert!(text.contains("-92233720368547758.08"));
    }

    #[test]
    fn pads_a_row_to_the_paper_width_with_the_amount_right_aligned() {
        assert_eq!(row("Coffee", "4.50", 20), "Coffee          4.50");
    }

    #[test]
    fn truncates_a_name_too_long_for_the_paper_rather_than_wrapping_the_amount() {
        let line = row("A very long product name indeed", "4.50", 20);
        assert_eq!(line.chars().count(), 20);
        assert!(line.ends_with("4.50"));
    }

    #[test]
    fn row_returns_exactly_width_chars_when_the_amount_alone_is_too_long() {
        // Early-return branch: amount_len >= width. Must not panic (a naive
        // `width - amount_len` would underflow) and must still produce exactly
        // `width` characters.
        let result = row("Label", "123456", 4);
        assert_eq!(result, "1234");
        assert_eq!(result.chars().count(), 4);

        // Also exercise the boundary where amount_len == width exactly.
        let boundary = row("Label", "1234", 4);
        assert_eq!(boundary, "1234");
        assert_eq!(boundary.chars().count(), 4);
    }

    #[test]
    fn starts_with_the_initialise_command_and_ends_with_a_cut() {
        let bytes = encode(&sample(), "USD");
        assert!(bytes.starts_with(&[0x1B, 0x40]));
        assert!(bytes.ends_with(&[0x1D, 0x56, 0x00]));
    }

    #[test]
    fn prints_every_line_its_modifiers_and_the_total() {
        let text = String::from_utf8_lossy(&encode(&sample(), "USD")).to_string();
        assert!(text.contains("Flat white"));
        assert!(text.contains("Oat milk"));
        assert!(text.contains("Croissant"));
        assert!(text.contains("N-0001"));
        assert!(text.contains("Dev Trading Co"));
        assert!(text.contains("12.65"));
    }

    #[test]
    fn shows_a_quantity_only_when_it_is_not_exactly_one() {
        let text = String::from_utf8_lossy(&encode(&sample(), "USD")).to_string();
        assert!(text.contains("2 x Croissant"));
        assert!(!text.contains("1 x Flat white"));
    }

    #[test]
    fn omits_a_zero_discount_row_but_prints_a_real_one() {
        let text = String::from_utf8_lossy(&encode(&sample(), "USD")).to_string();
        assert!(!text.contains("Discount"));

        let mut discounted = sample();
        discounted.totals.discount_cents = 200;
        let text = String::from_utf8_lossy(&encode(&discounted, "USD")).to_string();
        assert!(text.contains("Discount"));
    }

    #[test]
    fn trims_a_decimal_quantity_down_to_its_significant_digits() {
        let mut receipt = sample();
        receipt.lines[0].qty = "0.500".to_string();
        receipt.lines[1].qty = "10.000".to_string();
        let text = String::from_utf8_lossy(&encode(&receipt, "USD")).to_string();
        assert!(text.contains("0.5 x Flat white"));
        assert!(text.contains("10 x Croissant"));

        // "100.000" must trim to "100", not collapse all the way to "1" — the
        // trailing-zero trim only strips zeros after the decimal point, not the
        // zeros that are part of the integer portion.
        let mut receipt = sample();
        receipt.lines[0].qty = "100.000".to_string();
        let text = String::from_utf8_lossy(&encode(&receipt, "USD")).to_string();
        assert!(text.contains("100 x Flat white"));
        assert!(!text.contains("1 x Flat white"));
    }

    #[test]
    fn prints_the_location_header_footer_and_cashier() {
        let text = String::from_utf8_lossy(&encode(&sample(), "USD")).to_string();
        assert!(text.contains("Thanks!"));
        assert!(text.contains("See you soon"));
        assert!(text.contains("Served by Alice"));
    }

    #[test]
    fn suppresses_a_blank_centred_line_for_an_empty_header_or_footer() {
        let mut receipt = sample();
        receipt.location.header = Some(String::new());
        receipt.location.footer = Some(String::new());
        let bytes = encode(&receipt, "USD");

        // Only the business name and location name should be centred — an empty
        // header/footer must not emit its own (blank) centred line.
        let centre_count = bytes
            .windows(ALIGN_CENTRE.len())
            .filter(|w| *w == ALIGN_CENTRE)
            .count();
        assert_eq!(centre_count, 2);
    }

    #[test]
    fn a_zero_modifier_delta_renders_no_amount_while_a_nonzero_one_does() {
        let mut receipt = sample();
        receipt.lines[0].modifiers = vec![
            Modifier {
                name: "No charge".to_string(),
                price_delta_cents: 0,
            },
            Modifier {
                name: "Extra shot".to_string(),
                price_delta_cents: 75,
            },
        ];
        let text = String::from_utf8_lossy(&encode(&receipt, "USD")).to_string();

        let no_charge_line = text
            .lines()
            .find(|l| l.contains("No charge"))
            .expect("No charge modifier line present");
        assert_eq!(no_charge_line.trim_end(), "  No charge");

        let extra_shot_line = text
            .lines()
            .find(|l| l.contains("Extra shot"))
            .expect("Extra shot modifier line present");
        assert!(extra_shot_line.ends_with("0.75"));
    }
}
