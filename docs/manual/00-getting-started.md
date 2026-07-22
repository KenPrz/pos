# Getting Started

This is the User Manual for the people who run the store day to day — cashiers,
supervisors, managers — and for whoever installs and operates the system. It assumes
nothing about what you've seen before.

## What this is

The system is a point-of-sale for one business running several locations. It handles
both kinds of selling from the same order:

- **Retail** — scan a barcode, take a payment, done in under a minute.
- **Food service** — open a tab against a table, add items over an hour, split the
  bill at the end.

Underneath, both are the same thing: open an order, add lines to it, take money,
close it. You won't notice the shared plumbing day to day — a retail register looks
like a cart screen, a food register looks like a floor of tables — but it means a
receipt behaves the same way everywhere, and a manager's reports cover both without
translation.

## The three surfaces

| Surface | Who uses it | How it signs in |
| --- | --- | --- |
| **Register** | Cashiers and supervisors, at a physical till | An activation code (once, per terminal — exchanged for a device token) plus a staff PIN (every shift) |
| **Back office** | Managers and admins | An email and password — no till involved |
| **API** | The two apps above, and nothing else you should be talking to directly | Whatever the calling app is signed in as |

The register and the back office are separate applications on separate screens (and,
in production, separate web addresses) — a cashier's till never loads back-office
code, and a manager's laptop never needs a device token. Both talk to the same API
underneath, so a sale rung up at the till shows up in the back office's reports
immediately.

## The desktop shell

The register also runs as a small desktop app — a Tauri shell wrapped around the exact
same register described throughout this manual, at `frontend/native/`. It exists for one
reason: a browser tab can't drive a receipt printer or open the cash drawer, and the
shell can. Everything else about the register is identical either way — running it in an
ordinary browser tab remains fully supported; the shell only adds hardware access on top,
it doesn't replace anything.

See the Operator Guide's
[Connect the desktop shell to a server](04-operator-guide.md#connect-the-desktop-shell-to-a-server)
for the one extra step a shell terminal takes before enrollment, the Cashier Guide's
[Ring up a retail sale](01-cashier-guide.md#ring-up-a-retail-sale) for what printing does
differently there, and the Supervisor Guide's
[Open the drawer with no sale](02-supervisor-guide.md#open-the-drawer-with-no-sale) for
what else the shell adds once it's running.

## Roles

Roles are manageable in the back office, so the exact set can differ by business — but
the seeded pair below covers the common cases out of the box and is deliberately coarse:

- **Cashier** — rings up sales, opens and closes their own shift, takes payments.
- **Supervisor** — everything a cashier can do, plus the actions that let money or
  stock leave the business without a customer noticing: voids, discounts, refunds,
  and approving a till's cash variance.
- **Admin** — full access, including the back office (catalog, staff, locations,
  reports, the audit trail). Admin is a property of the person, not a role assigned
  per location — an admin is an admin everywhere.

Cashier and supervisor are assigned **per location**: someone can be a cashier at one
store and a supervisor at another, and the two don't mix.

> Note: a supervisor action isn't a separate PIN prompt layered on top of a cashier's
> session — there is no such prompt. Every supervisor-only screen and button is simply
> gated by whoever is currently clocked in at that till. In practice, a cashier who
> hits something that needs a supervisor (a void, a discount, a refund) clocks out, the
> supervisor clocks in with their own PIN to do the one thing and clocks out again — or
> the supervisor is already the one running that till for the shift. See the Supervisor
> Guide.

## Signing in

There are three separate sign-in flows, one per surface.

### Activate a register (once per terminal)

The first time a till is used, it shows an **Activate this terminal** screen asking
for an activation code. This only happens once per terminal — after that, the till
remembers its credential and goes straight to **Enter PIN**.

1. Get an activation code for this register. A manager issues one from the back
   office — see the Manager Guide's
   [Issue an activation code](03-manager-guide.md#issue-an-activation-code) section,
   which also covers a brand-new till's very first code.
2. Type it into **Activation code** (placeholder `XXXXX-XXXXX`) on the **Activate this
   terminal** screen.
3. Tap **Activate**.

> Note: an activation code is single-use and expires after 7 days — it's a one-time
> credential the till exchanges for its own long-lived device token, not something
> you type in more than once. That device token, not the code, is the terminal's
> lasting identity from then on. See the Manager Guide's guidance on issuing a new
> code for a terminal that needs one.

### Clock in with a PIN

Once a register is enrolled, it shows an **Enter PIN** screen every time nobody's
signed in — at open, and after the previous person's shift or session ends.

1. Type your 4–6 digit PIN.
2. Tap **Clock in**.

> Note: five wrong PINs in a row locks that register out for 60 seconds. This is
> deliberate — PINs are short, and the lockout is what makes a short PIN safe to use
> at all.

### Sign in to the back office

The back office has its own **Sign in** screen — email and password, no till and no
device token involved.

1. Enter your email and password.
2. Tap **Sign in**.

Only admins can sign in to the back office in this version — see the Manager Guide.

## Where next

| You are... | Go to |
| --- | --- |
| A cashier ringing up sales | [Cashier Guide](01-cashier-guide.md) |
| A supervisor handling voids, discounts, refunds, or variance | [Supervisor Guide](02-supervisor-guide.md) |
| A manager running catalog, staff, locations, or reports | [Manager Guide](03-manager-guide.md) |
| Whoever installs, deploys, or backs up the system | [Operator Guide](04-operator-guide.md) |
