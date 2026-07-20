# POS register — desktop shell

Tauri v2 shell that hosts the register SPA (`frontend/web`) and adds the two things a
browser cannot do: drive a thermal printer and kick a cash drawer.

It is not a third frontend. It bundles the same SPA and adds a hardware bridge. The
seam is `docs/01-architecture.md`: the server decides *what*, the shell does *how*, and
no money decision lives here.

## Running

    npm run dev      # expects frontend/web's dev server on :5174
    npm run build    # static-exports the SPA, then bundles

## Tests

    npm test         # cargo test — ESC/POS encoding, config, path validation
    npm run lint     # cargo fmt --check && cargo clippy -D warnings

## System dependencies (Linux)

    libwebkit2gtk-4.1-dev libgtk-3-dev librsvg2-dev build-essential

Design: `docs/superpowers/specs/2026-07-20-tauri-register-shell-design.md`
