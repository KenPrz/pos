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
pub fn money(cents: i64) -> String {
    let sign = if cents < 0 { "-" } else { "" };
    let absolute = cents.abs();
    format!("{sign}{}.{:02}", absolute / 100, absolute % 100)
}

/// One line of the receipt: label left, amount hard right. The label is truncated rather
/// than wrapped, because an amount pushed onto its own line reads as a different total.
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
        line(
            &row("Discount", &money(-receipt.totals.discount_cents), WIDTH),
            &mut out,
        );
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
}
