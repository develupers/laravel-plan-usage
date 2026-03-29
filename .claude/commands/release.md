# Git Flow Release Command

Create and finish a git flow release with auto-incremented version.

## Instructions

1. Check for uncommitted changes using `git status --porcelain`. If there are any staged or unstaged changes, notify the user and **stop** - do not proceed with the release.
2. Push `.docker` submodule changes if any:
   ```bash
   git -C .docker push origin HEAD
   ```
3. Get the latest git tag using: `git describe --tags --abbrev=0`
4. Parse the version (format: v1.2.3) and increment based on argument:
   - `patch` (default): v1.2.3 → v1.2.4
   - `minor`: v1.2.3 → v1.3.0
   - `major`: v1.2.3 → v2.0.0
5. **Ask the user to confirm** the new version before proceeding using AskUserQuestion tool
6. Only if confirmed, run the git flow release:
   ```bash
   git describe --tags --abbrev=0
   git flow release start -F <new_version>
   git flow release finish -F -p <new_version> -m "Tagging version <new_version>"
   ```
7. Report success to the user

## Argument

$ARGUMENTS - Can be one of:
- A bump type: `patch`, `minor`, or `major` (defaults to `patch` if not provided)
- A specific version number: e.g., `v1.2.7` or `1.2.7` (will add `v` prefix if missing)

## Examples

- `/release` - auto-increment patch (v1.2.6 → v1.2.7)
- `/release minor` - auto-increment minor (v1.2.6 → v1.3.0)
- `/release v1.3.0` - use specific version v1.3.0
- `/release 2.0.0` - use specific version v2.0.0
