# System Brain — planning & audit docs (NOT code)

This folder is the **documentation library** for WorkToGo — architecture maps, launch plans, role audits, and security analyses. It is kept in the same repository as the code **for backup and version history**, but it is deliberately **separate from the application code**:

- **Code** lives in the top-level app folders (`heart/`, `body/`, `core/`, `lib/`, `app/`, `admin/`, `vendor/`, …).
- **Docs** live here, under `system-brain/`.

**Do not deploy/serve this folder.** It contains internal security analysis. A `.htaccess` here denies all web access; if you host on nginx, block `/system-brain` there too. **Keep the repository private.**

**Start with:** `MAP/system-map.md` (the house blueprint), `MAP/naming-and-structure.md` (names), and `LAUNCH/` (final launch gate, server-day checklist, prehosting prep). The running log is `CHANGELOG.md`.

> This is a mirror of the working `WTG-SYSTEM-BRAIN/` folder kept alongside the code on disk. When you update the docs, re-copy and commit to refresh this backup (see `LAUNCH/CHANGELOG.md` for the one-line command).
