#!/usr/bin/env bash
# scripts/wiki-sync.sh OUT_DIR — build the GitHub-wiki tree from docs/.
# CI pushes the result to <repo>.wiki.git; run locally for a dry preview.
# Synced pages are OVERWRITTEN on every run — the wiki is generated, edit docs/ instead.
set -euo pipefail

OUT="${1:?usage: wiki-sync.sh OUT_DIR}"
mkdir -p "$OUT"

# source-path → wiki page name (flat namespace; names are the H1s, hyphenated)
declare -A PAGES=(
  [docs/manual/00-getting-started.md]="Getting-Started"
  [docs/manual/01-cashier-guide.md]="Cashier-Guide"
  [docs/manual/02-supervisor-guide.md]="Supervisor-Guide"
  [docs/manual/03-manager-guide.md]="Manager-Guide"
  [docs/manual/04-operator-guide.md]="Operator-Guide"
  [docs/00-overview.md]="Overview"
  [docs/01-architecture.md]="Architecture"
  [docs/02-data-model.md]="Data-Model"
  [docs/03-api.md]="API"
  [docs/04-backend-conventions.md]="Backend-Conventions"
  [docs/05-rbac.md]="RBAC"
  [docs/06-roadmap.md]="Roadmap"
)

REPO_URL="${WIKI_REPO_URL:-https://github.com/KenPrz/pos}"
SHA="$(git rev-parse --short HEAD)"

# Build one sed program that rewrites every known relative link to its wiki page,
# in ]( ... ) position, tolerating ./ ../ docs/ manual/ prefixes and #anchors.
# All current sources link with bare filenames (no directory prefix — every link
# audited in docs/*.md and docs/manual/*.md is `](NN-name.md)` or `](NN-name.md#anchor)`),
# but the pattern below also tolerates ./, one or more ../, and docs/ or manual/
# prefixes so it doesn't silently break if a future doc adds a deeper relative link.
SED_ARGS=()
for src in "${!PAGES[@]}"; do
  base="$(basename "$src")"
  page="${PAGES[$src]}"
  SED_ARGS+=(-e "s|](\\(\\./\\)\\?\\(\\.\\./\\)*\\(docs/\\)\\?\\(manual/\\)\\?${base}|](${page}|g")
done

for src in "${!PAGES[@]}"; do
  [ -f "$src" ] || { echo "missing source: $src" >&2; exit 1; }
  sed "${SED_ARGS[@]}" "$src" > "$OUT/${PAGES[$src]}.md"
done

# Home: docs/README.md with the same rewrites, plus a manual pointer up top.
{
  echo "> **New here?** Start with [Getting Started](Getting-Started) — the User Manual for people running the store. This page indexes the technical documentation."
  echo
  sed "${SED_ARGS[@]}" docs/README.md
} > "$OUT/Home.md"

cat > "$OUT/_Sidebar.md" <<'EOF'
**User Manual**
- [Getting Started](Getting-Started)
- [Cashier Guide](Cashier-Guide)
- [Supervisor Guide](Supervisor-Guide)
- [Manager Guide](Manager-Guide)
- [Operator Guide](Operator-Guide)

**Technical Documentation**
- [Overview](Overview)
- [Architecture](Architecture)
- [Data Model](Data-Model)
- [API](API)
- [Backend Conventions](Backend-Conventions)
- [RBAC](RBAC)
- [Roadmap](Roadmap)
EOF

printf '*Synced from [`docs/`](%s/tree/main/docs) at %s — edit in the repo, not here.*\n' "$REPO_URL" "$SHA" > "$OUT/_Footer.md"

# Any remaining relative .md link is a link to an unsynced file — rewrite to the repo blob.
sed -i "s|](\\(\\./\\)\\?\\(\\.\\./\\)\\+\\([^)]*\\.md\\)|](${REPO_URL}/blob/main/\\3|g" "$OUT"/*.md

echo "wiki tree built in $OUT ($(ls "$OUT" | wc -l) files)"
