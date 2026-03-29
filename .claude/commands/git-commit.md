# Git Commit Command

Generate a commit message and optionally commit the changes.

**Arguments:** $ARGUMENTS

## Options

- `--message` or `-m`: Only generate the commit message, do not commit
- (no args): Generate message AND create the commit

## Instructions

1. Run `git status --short` to understand the scope of changes
2. Run `git diff --staged` to see staged changes (or `git diff` if nothing staged)
3. Analyze the changes and generate a commit message

## Commit Message Format

```
<Brief one-line summary under 72 chars>

- <Notable feature or change 1>
- <Notable feature or change 2>
- <Notable feature or change 3>
```

## Message Rules

- Write for a manager audience - focus on business value and outcomes
- Keep the summary line under 72 characters
- Each bullet point should be distinct and non-overlapping
- Use action verbs (Add, Fix, Update, Remove, Refactor)
- Limit to 3-6 bullet points maximum
- Skip implementation details unless critical

## Execution

Check if `$ARGUMENTS` contains `--message` or `-m`:

**If --message or -m:**
- Output ONLY the commit message text, ready to copy
- Do NOT commit

**If no arguments (default):**
- Stage all changes with `git add -A`
- Create the commit using the generated message
- Use HEREDOC format for multi-line message:
```bash
git commit -m "$(cat <<'EOF'
Summary line here

- Bullet point 1
- Bullet point 2

Co-Authored-By: Claude <noreply@anthropic.com>
EOF
)"
```
- Run `git status` after to confirm success
- Show the user the commit hash and message
