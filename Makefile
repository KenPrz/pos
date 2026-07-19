# POS — runner surface. `make help` lists everything.
COMPOSE_DEV  := docker compose -f compose.dev.yml
COMPOSE_PROD := docker compose -f compose.prod.yml

# backup/restore/restore-drill target whichever stack COMPOSE points at
# (COMPOSE=prod switches to the prod stack; default is dev). Both stacks name
# their db service `db` and their database/user `pos`, so one set of targets
# covers either.
COMPOSE_VAR := $(if $(filter prod,$(COMPOSE)),$(COMPOSE_PROD),$(COMPOSE_DEV))

.DEFAULT_GOAL := help
.PHONY: help dev dev-down logs ps seed migrate dev-key test test-backend test-web test-bo typecheck clean build prod-up prod-down prod-logs backup restore restore-drill e2e

help: ## List available targets
	@grep -hE '^[a-zA-Z0-9_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}'

# The echo below deliberately doesn't print concrete ports: .env's
# POS_DEV_*_PORT overrides land in docker compose (which reads .env itself),
# not in make's recipe shell (nothing here `include`s .env), so hardcoding the
# defaults here would lie the moment someone actually overrides a port. `make
# ps` asks Docker, which knows the truth.
dev: ## Bring up the full dev stack (db, api, register, back office)
	$(COMPOSE_DEV) up -d --build
	@echo "stack is up — see 'make ps' for the actual host ports (defaults: api 8000, register 5174, back office 5175, overridable via POS_DEV_*_PORT in .env)"

dev-down: ## Stop the dev stack (volumes survive)
	$(COMPOSE_DEV) down

logs: ## Tail dev stack logs
	$(COMPOSE_DEV) logs -f --tail=100

ps: ## Dev stack status
	$(COMPOSE_DEV) ps

# Deliberately compose-free and vendor-free: this has to work before .env has
# a key in it at all, and compose.dev.yml's api service can't even be built —
# let alone run `artisan key:generate` — until it does (a blank
# POS_DEV_APP_KEY used to be a hard `:?required` in compose.dev.yml, which
# fails interpolation for the WHOLE file, including this target's own
# `build api`). An APP_KEY is nothing but base64 of 32 random bytes, so mint
# it with plain PHP instead, from the Dockerfile's `base` target (has PHP,
# never touches vendor/ or app code): build that image, run it once for its
# one line of output, done. Nothing here needs Postgres, compose, or a key
# that already exists — just Docker and this repo.
dev-key: ## Mint an APP_KEY for the root .env — no vendor, no compose, no existing key needed
	docker run --rm $$(docker build -q --target base backend) php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"

# compose.dev.yml's api/web/back-office services start as root (to chown a
# fresh named volume once) then hand off to a non-root user for good — pos
# for api, node for web/back-office (see compose.dev.yml comments). `exec`
# without --user reconnects as root, so every target below that writes to a
# bind-mounted directory names the user explicitly.
seed: ## Fresh migrate + seed (prints dev PINs and device tokens)
	$(COMPOSE_DEV) exec --user pos api php artisan migrate:fresh --seed

migrate: ## Run pending migrations
	$(COMPOSE_DEV) exec --user pos api php artisan migrate

# Credential extraction is pinned to the seeder's ACTUAL printed table
# (DatabaseSeeder::run), not an `artisan tinker` query: Sanctum stores only
# the HASH of a device token server-side (personal_access_tokens.token) — the
# plaintext exists exactly once, at creation, which is this printed table.
# There is nothing left for tinker to fetch after the fact; minting a *new*
# token via tinker would work but seeds an extra, undocumented row the
# seeder itself doesn't account for. So: parse what the seeder already
# prints. The one wrinkle — a Sanctum plaintext token is itself `<id>|<hash>`,
# and the table's own border character is also `|`, so a naive `awk -F'|'`
# split misaligns on the token's embedded pipe. Anchoring the sed pattern on
# the fixed "DT / Till N" label and matching to the LAST `|` on the line
# (greedy `.*`, trimmed of trailing padding in a second pass) survives it.
# Only Downtown's tokens are needed: e2e-retail-day.sh and the Till-1 leg of
# e2e-lunch-service.sh / e2e-admin-day.sh all resolve through Bob/Alice, who
# only hold roles at Downtown (see DatabaseSeeder::seedStaff).
#
# Each `$(MAKE) seed` below is captured to a file with a plain redirect
# (`> file 2>&1`), never piped through `tee` — a pipeline's exit status is
# its LAST command's (tee always succeeds), which would silently swallow a
# failed seed and let the target stumble on to grep an empty/stale file. The
# `|| { cat file; exit 1; }` surfaces the real error and fails the target
# at the seed step, exactly where it broke; the `cat` on the success path
# re-prints the table so the target's own terminal output is unchanged.
#
# Leaves data behind: this target reseeds (destructively — `migrate:fresh`)
# TWICE and runs three scripts' worth of orders/shifts/payments against
# whichever seed came last, so the dev db is NOT empty when this finishes —
# it holds the second seed's fixtures plus e2e-admin-day.sh's writes. Run
# `make seed` again afterward for a clean slate before using the dev stack
# for anything else.
# Target-specific variable, simply-expanded (`:=`): computed once, the first
# time make binds variables for this target's recipe — every recipe line
# below is still its own separate shell (make doesn't do .ONESHELL here), but
# make substitutes the same literal directory path into all of them at
# expansion time, so they share one private temp dir without needing a shell
# variable to survive across shells. `mktemp -d` is mode 0700 — unlike the old
# fixed /tmp/pos-*.txt names (mode 0644, predictable, readable by any local
# user), nothing but this user can even list what's inside, which matters
# here because these files hold live device tokens.
e2e: E2E_TMP := $(shell mktemp -d)
e2e: ## Reseed (twice — see comment above), run all three committed e2e proofs, THEN LEAVE THE DEV DB DIRTY with two seeds' + e2e-admin-day's data (re-run `make seed` after for a clean slate). Needs the api container reachable at http://127.0.0.1:8000 — the scripts hardcode it; override POS_DEV_API_PORT back to 8000 in root .env if something else is squatting on it.
	@$(MAKE) seed > $(E2E_TMP)/pos-seed-out.txt 2>&1 || { cat $(E2E_TMP)/pos-seed-out.txt; exit 1; }
	@cat $(E2E_TMP)/pos-seed-out.txt
	@grep '| DT / Till 1 ' $(E2E_TMP)/pos-seed-out.txt | sed -E 's/^\| *DT \/ Till 1 *\| *(.*) *\|$$/\1/' | sed -E 's/ +$$//' > $(E2E_TMP)/pos-e2e-till1.txt
	@grep '| DT / Till 2 ' $(E2E_TMP)/pos-seed-out.txt | sed -E 's/^\| *DT \/ Till 2 *\| *(.*) *\|$$/\1/' | sed -E 's/ +$$//' > $(E2E_TMP)/pos-e2e-till2.txt
	@test -s $(E2E_TMP)/pos-e2e-till1.txt && test -s $(E2E_TMP)/pos-e2e-till2.txt || { echo "could not extract DT / Till 1|2 device tokens — seeder's printed table format may have changed"; exit 1; }
	@echo "extracted: DT/Till1=$$(cut -c1-8 $(E2E_TMP)/pos-e2e-till1.txt)... DT/Till2=$$(cut -c1-8 $(E2E_TMP)/pos-e2e-till2.txt)..."
	POS_DEVICE_TOKEN=$$(cat $(E2E_TMP)/pos-e2e-till1.txt) bash scripts/e2e-retail-day.sh
	POS_DEVICE_TOKEN=$$(cat $(E2E_TMP)/pos-e2e-till2.txt) POS_DEVICE_TOKEN_2=$$(cat $(E2E_TMP)/pos-e2e-till1.txt) bash scripts/e2e-lunch-service.sh
	@# e2e-admin-day.sh's sales-report checks are location+date scoped, not shift-
	@# scoped like the other two scripts' Z-reports, and it asserts an absolute
	@# Downtown day-gross of exactly 510c — an assumption baked in when it was
	@# authored/proven standalone in M6 (git 7d75dcf), against its OWN fresh seed.
	@# Chaining it after the two scripts above (which also transact at Downtown,
	@# same calendar day) makes that specific assertion false even though nothing
	@# is actually broken — it's a test-isolation assumption the script carries,
	@# not a stack bug. Re-seeding here restores the exact precondition the script
	@# was written against, without editing the script itself. Reordering instead
	@# (running admin-day first) was considered and rejected: admin-day flips
	@# Till 1 to food mode and reissues its token, which e2e-lunch-service.sh
	@# depends on still being retail-mode — so admin-day must stay last, and a
	@# second reseed is the only fix that touches neither script nor ordering.
	@$(MAKE) seed > $(E2E_TMP)/pos-seed-out2.txt 2>&1 || { cat $(E2E_TMP)/pos-seed-out2.txt; exit 1; }
	@cat $(E2E_TMP)/pos-seed-out2.txt
	@grep '| DT / Till 1 ' $(E2E_TMP)/pos-seed-out2.txt | sed -E 's/^\| *DT \/ Till 1 *\| *(.*) *\|$$/\1/' | sed -E 's/ +$$//' > $(E2E_TMP)/pos-e2e-till1b.txt
	@test -s $(E2E_TMP)/pos-e2e-till1b.txt || { echo "could not extract DT / Till 1 device token on the second reseed"; exit 1; }
	POS_ADMIN_EMAIL=admin@pos.test POS_ADMIN_PASSWORD=admin-dev-password POS_DEVICE_TOKEN=$$(cat $(E2E_TMP)/pos-e2e-till1b.txt) POS_E2E_PIN=9876 bash scripts/e2e-admin-day.sh
	@rm -rf $(E2E_TMP)

test: test-backend test-web test-bo ## All suites, in containers

# -e overrides beat phpunit.xml <env> values by design (real env wins — the
# same mechanism as the local DB_PORT=5433 habit). Never edit phpunit.xml.
test-backend: ## Pest against the compose db (creates pos_test if missing)
	$(COMPOSE_DEV) exec db psql -U pos -d pos -tc "SELECT 1 FROM pg_database WHERE datname='pos_test'" | grep -q 1 || $(COMPOSE_DEV) exec db createdb -U pos pos_test
	$(COMPOSE_DEV) exec --user pos -e DB_HOST=db -e DB_PORT=5432 -e DB_DATABASE=pos_test api php -d memory_limit=512M ./vendor/bin/pest

test-web: ## Register app vitest
	$(COMPOSE_DEV) exec --user node web npm test

test-bo: ## Back-office vitest
	$(COMPOSE_DEV) exec --user node back-office npm test

typecheck: ## tsgo on both frontend apps
	$(COMPOSE_DEV) exec --user node web npm run typecheck
	$(COMPOSE_DEV) exec --user node back-office npm run typecheck

clean: ## Dev stack down AND volumes destroyed (asks first)
	@read -p "Destroy dev volumes (db data, vendor, node_modules)? [y/N] " a && [ "$$a" = "y" ]
	$(COMPOSE_DEV) down -v

build: ## Build all three production images
	docker build --target prod -t pos-api:latest backend
	docker build --target runner -t pos-web:latest frontend/web
	docker build --target runner -t pos-back-office:latest frontend/back-office

prod-up: ## Start the production stack (needs .env — see .env.prod.example)
	$(COMPOSE_PROD) up -d --build

prod-down: ## Stop the production stack
	$(COMPOSE_PROD) down

prod-logs: ## Tail production logs
	$(COMPOSE_PROD) logs -f --tail=100

# db execs stay unqualified (no --user) like test-backend's above: pg_dump/
# pg_restore/dropdb/createdb only ever touch Postgres's own data directory,
# never the bind-mounted host tree, so there is no ownership hazard to dodge.
# The backup file itself lands on the HOST via shell stdout redirection
# (`> backups/...`), so it is owned by whoever ran `make`, not the container.
backup: ## pg_dump -Fc the stack db -> backups/pos-<utc>.dump (COMPOSE=prod for prod)
	@mkdir -p backups
	$(COMPOSE_VAR) exec -T db pg_dump -U pos -d pos -Fc > backups/pos-$$(date -u +%Y%m%dT%H%M%SZ).dump
	@ls -lh backups/ | tail -1

restore: ## Restore FILE=backups/... into the running db (DESTRUCTIVE, asks first)
	@test -n "$(FILE)" || { echo "usage: make restore FILE=backups/pos-....dump"; exit 1; }
	@read -p "Overwrite the live 'pos' database with $(FILE)? Type 'restore' to confirm: " a && [ "$$a" = "restore" ]
	$(COMPOSE_VAR) exec -T db dropdb -U pos --force pos && $(COMPOSE_VAR) exec -T db createdb -U pos pos
	$(COMPOSE_VAR) exec -T db pg_restore -U pos -d pos --no-owner < $(FILE)
	@echo "restored $(FILE)"

# Verification counts users/products/registers, not orders/payments/audit_log:
# a fresh `make seed` never places an order (no sales happen at seed time), so
# those would read 0 whether or not the restore worked — a passing drill would
# prove nothing. users/products/registers are populated by every seed and are
# exactly what this drill has on hand; run one e2e sale first (or use a backup
# taken after real traffic) to also exercise orders/payments/audit_log.
restore-drill: ## Prove the newest backup restores: throwaway db, row counts, teardown
	@test -n "$$(ls backups/*.dump 2>/dev/null)" || { echo "no backups yet - run 'make backup'"; exit 1; }
	@LATEST=$$(ls -t backups/*.dump | head -1); echo "drilling $$LATEST"; \
	set -e; \
	docker run -d --name pos-drill -e POSTGRES_PASSWORD=drill postgres:18-alpine >/dev/null; \
	trap 'docker rm -f pos-drill >/dev/null 2>&1' EXIT; \
	tries=0; until docker exec pos-drill pg_isready -U postgres >/dev/null 2>&1; do tries=$$((tries+1)); [ "$$tries" -lt 60 ] || { echo "pos-drill never became ready"; exit 1; }; sleep 1; done; \
	docker exec pos-drill createdb -U postgres pos; \
	docker exec -i pos-drill pg_restore -U postgres -d pos --no-owner < $$LATEST; \
	docker exec pos-drill psql -U postgres -d pos -tc \
	  "SELECT 'users: '||count(*) FROM users UNION ALL SELECT 'products: '||count(*) FROM products UNION ALL SELECT 'registers: '||count(*) FROM registers"; \
	echo "restore drill PASSED - the backup is not a rumor"
