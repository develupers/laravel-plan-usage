---
name: release
description: Cut a git flow release with an auto-incremented semver tag. Requires a clean working tree, pushes the .docker submodule if present, verifies gitflow config, confirms the new version with the user, runs git flow release start/finish, then verifies the tag reached the remote. Use when the user asks to release, cut a release, tag a release, ship a version, or bump the version (patch/minor/major).
---

# Git Flow Release

Create and finish a git flow release with an auto-incremented version.

## Arguments

Arguments are optional and can be one of:

- A bump type: `patch`, `minor`, or `major` — defaults to `patch` when nothing is passed.
- A specific version: e.g. `v1.2.7` or `1.2.7` — add the `v` prefix if it is missing.

Examples:

- `/release` — auto-increment patch (v1.2.6 → v1.2.7)
- `/release minor` — auto-increment minor (v1.2.6 → v1.3.0)
- `/release v1.3.0` — use the specific version v1.3.0
- `/release 2.0.0` — use the specific version v2.0.0

## Steps

### 1. Require a clean working tree

```bash
git status --porcelain
```

If there are any staged or unstaged changes, notify the user and **stop** — do not proceed with
the release.

### 2. Push `.docker` submodule changes if any

```bash
git -C .docker push origin HEAD
```

Skip this step in repos that have no `.docker` submodule.

### 3. Ensure gitflow is initialized and its config is correct

- Run `git flow config` to check if gitflow is initialized. If not, run `git flow init -d`.
- After init, verify `gitflow.path.hooks` in `.git/config` points to this repo's `.git/hooks`
  (not a submodule or another repo). Fix with `git config gitflow.path.hooks .git/hooks` if wrong.
- Verify the remote is correct by running `git remote -v` and confirming it matches the expected
  project repository.

### 4. Get the latest tag and determine the new version

```bash
git describe --tags --abbrev=0
```

Parse the version (format `v1.2.3`) and increment based on the argument:

- `patch` (default): v1.2.3 → v1.2.4
- `minor`: v1.2.3 → v1.3.0
- `major`: v1.2.3 → v2.0.0

### 5. Confirm with the user

**Ask the user to confirm** the new version before proceeding, using the AskUserQuestion tool.

### 6. Run the release

Only if confirmed:

```bash
git describe --tags --abbrev=0
git flow release start -F <new_version>
git flow release finish -F -p <new_version> -m "Tagging version <new_version>"
```

### 7. Verify the tag was pushed to the correct remote

```bash
git ls-remote --tags origin | grep <new_version>
```

If the tag is not on the remote, say so explicitly — the release is not done.

### 8. Report success to the user
