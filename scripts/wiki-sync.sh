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

# Fallback for .md links that AREN'T one of the known PAGES — i.e. a link to a file
# that isn't synced to the wiki. Unlike the PAGES rewrite above (which only has to
# match a known basename, regardless of directory, because the destination page name
# doesn't depend on where the link came from), resolving one of these requires actually
# walking the relative path, because the destination DOES depend on the source
# directory: a bare `foo.md` inside docs/manual/ means docs/manual/foo.md, but the same
# `foo.md` inside docs/ means docs/foo.md. It also tolerates the same ./, ../, docs/,
# manual/ prefixes the PAGES rewrite does.
#
# Resolving the path is only half of it: a resolved path that doesn't correspond to a
# real file in the repo is a typo or a stale link, not a legitimate cross-reference —
# rewriting it to a blob URL anyway would "fix" the .md-link audit while shipping a
# 404. So this only rewrites to a blob URL when the resolved path exists on disk;
# anything that doesn't resolve to a real file is left exactly as found (still a bare
# relative link) so the fail-loudly guard below catches it and fails the build instead
# of a human finding a dead wiki link later.
read -r -d '' FALLBACK_AWK <<'AWK_EOF' || true
function file_exists(f,    ret, junk) {
  ret = (getline junk < f)
  close(f)
  return (ret >= 0)
}
function resolve(target,    rest, ups, n, path, i, parts) {
  rest = target
  if (rest ~ /^\.\//) sub(/^\.\//, "", rest)
  ups = 0
  while (rest ~ /^\.\.\//) { sub(/^\.\.\//, "", rest); ups++ }
  if (kind == "manual") { n = 2; parts[1] = "docs"; parts[2] = "manual" }
  else                  { n = 1; parts[1] = "docs" }
  n = n - ups
  if (n < 0) n = 0
  if (ups == 0) {
    if (kind == "manual" && rest ~ /^manual\//) sub(/^manual\//, "", rest)
    if (kind == "docs"   && rest ~ /^docs\//)   sub(/^docs\//,   "", rest)
  }
  path = ""
  for (i = 1; i <= n; i++) path = path parts[i] "/"
  return path rest
}
{
  remaining = $0
  out = ""
  while (match(remaining, /\]\([^)]*\.md[^)]*\)/)) {
    pre = substr(remaining, 1, RSTART - 1)
    m = substr(remaining, RSTART, RLENGTH)
    inner = substr(m, 3, length(m) - 3)
    target = inner
    anchor = ""
    p = index(inner, "#")
    if (p > 0) { target = substr(inner, 1, p - 1); anchor = substr(inner, p) }
    resolved = resolve(target)
    if (file_exists(resolved)) {
      out = out pre "](" repourl "/blob/main/" resolved anchor ")"
    } else {
      out = out pre m
    }
    remaining = substr(remaining, RSTART + RLENGTH)
  }
  print out remaining
}
AWK_EOF

for src in "${!PAGES[@]}"; do
  [ -f "$src" ] || { echo "missing source: $src" >&2; exit 1; }
  case "$src" in
    docs/manual/*) kind="manual" ;;
    *)             kind="docs" ;;
  esac
  sed "${SED_ARGS[@]}" "$src" | awk -v kind="$kind" -v repourl="$REPO_URL" "$FALLBACK_AWK" > "$OUT/${PAGES[$src]}.md"
done

# Home: docs/README.md with the same rewrites, plus a manual pointer up top.
[ -f docs/README.md ] || { echo "missing source: docs/README.md" >&2; exit 1; }
{
  echo "> **New here?** Start with [Getting Started](Getting-Started) — the User Manual for people running the store. This page indexes the technical documentation."
  echo
  sed "${SED_ARGS[@]}" docs/README.md | awk -v kind="docs" -v repourl="$REPO_URL" "$FALLBACK_AWK"
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

# Fail-loudly guard: every link above was either turned into a wiki page name (no
# more .md) or, via the fallback above, into an absolute repo blob URL. If any .md
# link survives that ISN'T already absolute (http/https), it's a dead relative link
# in the published wiki — a mapping this script doesn't know about, not something to
# ship silently. Fail the build instead of publishing a broken wiki.
BAD_LINKS="$(grep -noE '\]\([^)]*\.md[^)]*\)' "$OUT"/*.md | grep -Ev '\]\(https?://' || true)"
if [ -n "$BAD_LINKS" ]; then
  echo "wiki-sync: unresolved relative .md link(s) survived rewriting — add the source to PAGES or fix the link:" >&2
  echo "$BAD_LINKS" >&2
  exit 1
fi

echo "wiki tree built in $OUT ($(ls "$OUT" | wc -l) files)"
