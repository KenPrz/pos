# 17. Glossary

| Term | Definition |
| --- | --- |
| Till / register | A physical terminal at a location — "till" and "register" mean the same thing throughout this manual (Chapter 1). Its **Mode** (retail or food) decides which sale screen it shows (Chapters 4, 11). |
| Device token | The long-lived credential a terminal holds once activated, proving *which register* a request came from. Alone, it can read the catalog; it can never touch money (Chapters 2, 3). |
| Activation code | The one-time, 7-day, admin-issued credential a till exchanges for its device token. Single-use — never typed in twice (Chapters 3, 11). |
| Staff session | The short-lived credential a PIN clock-in produces, proving *who* is acting on an already-authenticated register. Bound to that specific register — lifted to another till, it's inert (Chapters 2, 3). |
| PIN | The 4–6 digit code a cashier or supervisor types to start a staff session. Five wrong attempts in a row locks that till for 60 seconds (Chapter 3). |
| Shift | A till's own cash-accountability period: opens with a counted float, runs sales and cash movements, closes with another count (Chapter 7). |
| Float | The cash counted into the drawer when a shift opens, before a single sale (Chapter 7). |
| Cash movement | Any cash that moves in or out of the drawer without a sale attached — a payout, a deposit, a paid-in — always recorded with a reason (Chapter 7). |
| Z-report | The sales-and-cash summary produced the moment a shift closes: totals by tender, **Paid in**, **Payouts**, **Drops**, and order counts. It only ever appears together with the close itself (Chapter 7). |
| Variance | The difference between counted cash and expected cash at close. Inside the store's threshold it reconciles clean; outside it, a supervisor has to approve it from a different register at the same location (Chapter 7). |
| Tab | An open order at a food-service till — seated at a table or held for a walk-in — that lingers before payment instead of closing in under a minute (Chapter 6). |
| `table_ref` | The optional table name or number attached to a tab; left blank for a walk-in or a bar tab (Chapter 6). |
| Course / fire | The prep-state chip on a kitchen-tracked line — **Pending** → **Cooking** → **Ready**. Tapping it moves the line forward; **Cooking** is the "fired" state the kitchen sees (Chapter 6). |
| Modifier | A single pick attached to a menu item — an extra, a substitution, an option — priced as a delta on the item's own price (Chapter 6). |
| Modifier group | A named set of modifiers attached to a product (e.g. **Rice**, **Add-ons**). Can be marked required, and a repeat-legal choice can be added more than once (Chapters 6, 9). |
| Signed delta | A modifier's price relative to the item it's attached to — usually positive (an add-on), sometimes negative, like **No Rice** subtracting from Chicken Adobo (Chapter 6). |
| Split | Dividing one tab's payment across 2 to 10 separate checks once payment has started. The checks always sum back exactly to the original total (Chapter 6). |
| Transfer | Moving an open tab from one register to another. The receiving till's shift owns the sale's money from the instant it lands (Chapter 6). |
| Restock | Putting a refunded line's quantity back into on-hand stock. Left checked by default; cleared if the returned goods are damaged (Chapter 5). |
| Ledger basis | A report built from actual payments and refunds that moved money — the Sales report's **Day** and **User** tabs (Chapter 12). |
| Line basis | A report built from individual order lines, joined to current catalog data — the Sales report's **Category** tab. Doesn't reconcile with a ledger-basis report, by design (Chapter 12). |
| VAT-inclusive | A location's **Prices include tax** setting: the shelf price already contains tax, and tax on the receipt is extracted from that price rather than added on top (Chapters 2, 11). |
| SKU | A variant's own stock-keeping code — distinct from its barcode. Every variant needs one (Chapter 9). |
| Barcode | The optional code a scanner reads to find a variant directly. A variant needs no barcode to exist, but a scan needs one to find it (Chapter 9). |
| Archive | The back office's only removal action: unchecking **Active** on a product, variant, discount, or tax rate takes it off the live catalog while every past order line, receipt, and report that points at it keeps working (Chapter 9). |
