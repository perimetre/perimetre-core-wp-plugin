---
name: bump
description: "Bump the plugin version and add a changelog entry. Use when the user says /bump, 'bump the version', or 'prepare a release'."
---

# Version Bump

Bump the Perimetre Core plugin version. Accepts a semver increment (patch, minor, major) and a changelog summary.

## Steps

1. Read the current version from the `PERIMETRE_CORE_VERSION` constant in `perimetre-core.php`
2. Ask the user (if not provided): **patch**, **minor**, or **major** increment, and a one-line changelog summary
3. Compute the new version number
4. Update all three locations:
   - `Version:` header comment in `perimetre-core.php`
   - `PERIMETRE_CORE_VERSION` constant in `perimetre-core.php`
   - `**Current Version**` line in `README.md`
5. Add a new changelog entry at the top of the Changelog section in `README.md` with the summary
6. Report the old and new version numbers

## Rules

- Follow semantic versioning: patch for fixes, minor for new features, major for breaking changes
- The changelog entry format must match existing entries (### heading + bulleted list)
- Do NOT create a git commit or tag — the user will do that when ready
