---
description: Generate and execute an optimal ralph-loop command for the current speckit tasks.md
---

## User Input

```text
$ARGUMENTS
```

You **MUST** consider the user input before proceeding (if not empty). User input can override default max-iterations or add custom instructions.

## Outline

This skill bridges the speckit workflow with ralph-loop execution by generating an optimized ralph-loop command based on the current feature's tasks.md.

### Step 1: Load Feature Context

1. Run `.specify/scripts/bash/check-prerequisites.sh --json --require-tasks --include-tasks` from repo root
2. Parse JSON for:
   - `FEATURE_DIR` - Path to current feature specs
   - `TASKS_CONTENT` - The tasks.md content (if included)
   - `BRANCH` - Current feature branch name
3. If tasks.md doesn't exist, ERROR: "No tasks.md found. Run `/speckit.tasks` first."

### Step 2: Analyze Tasks

Read and analyze from FEATURE_DIR:

1. **tasks.md** (required):
   - Count total tasks
   - Count tasks per phase
   - Identify test tasks (TDD requirements)
   - Extract phase names and checkpoints
   - Note any special requirements (SSR builds, Horizon restarts, etc.)

2. **plan.md** (if exists):
   - Extract tech stack (PHP/Laravel, TypeScript/React, etc.)
   - Extract test commands (composer test:unit, npm run types, etc.)
   - Extract build commands (npm run build:ssr, etc.)
   - Note constitution requirements (TDD, type safety, etc.)

3. **spec.md** (if exists):
   - Extract feature name
   - Count user stories

### Step 3: Determine Parameters

Based on analysis, calculate:

1. **max-iterations**:
   - Base: `(total_tasks * 3)` (allows retries per task)
   - Minimum: 20
   - Maximum: 200
   - Add buffer for TDD cycles: `+10` if tests present

2. **completion-promise**:
   - Default: `SPECKIT_COMPLETE`
   - Unique to avoid false positives

3. **Verification commands** to include in prompt:
   - PHP projects: `composer test:unit`, `composer test:types`, `composer lint`
   - TypeScript/React: `npm run types`, `npm run build:ssr`
   - Laravel + Horizon: Note about Horizon restart

### Step 4: Generate Ralph-Loop Command

Construct the prompt following ralph-loop best practices:

```text
/ralph-loop:ralph-loop "<generated_prompt>" --max-iterations <N> --completion-promise "SPECKIT_COMPLETE"
```

The generated prompt MUST include:

1. **Clear task reference**: Point to tasks.md location
2. **Execution instructions**: Follow phases in order, respect [P] markers
3. **TDD requirements**: Write failing tests first, then implement
4. **Verification steps**: Run test suite after each implementation task
5. **Progress tracking**: Mark tasks [X] when complete
6. **Completion criteria**: All tasks marked [X], all tests passing
7. **Escape hatch**: What to do if stuck after N iterations
8. **Completion signal**: Output the promise when done

### Step 5: Display and Confirm

Display to user:

```markdown
## Generated Ralph-Loop Command

**Feature**: [feature name]
**Branch**: [branch name]
**Total Tasks**: [count]
**Max Iterations**: [calculated value]

### Command:

\`\`\`bash
/ralph-loop:ralph-loop "[prompt]" --max-iterations [N] --completion-promise "SPECKIT_COMPLETE"
\`\`\`

### Prompt Breakdown:
- Tasks file: [path]
- Phases: [list]
- Test commands: [list]
- Build commands: [list]

---

**Ready to start?** This will begin autonomous implementation.
```

Then use AskUserQuestion:
- "Execute this ralph-loop command now?"
- Options: "Yes, start now" / "No, I'll run it manually"

### Step 6: Execute (if approved)

If user approves:
1. Invoke `/ralph-loop:ralph-loop` with the generated parameters
2. The ralph-loop will take over from here

If user declines:
1. Remind them they can copy the command and run it later
2. Suggest any modifications they might want to make

---

## Prompt Template

Use this template for generating the ralph-loop prompt:

```text
Implement the [FEATURE_NAME] feature following the task list at [TASKS_PATH].

## Execution Rules

1. **Read tasks.md first** - Understand all phases and dependencies
2. **Follow phase order** - Complete Setup → Foundational → User Stories → Polish
3. **Respect markers**:
   - [P] = Can run in parallel with other [P] tasks in same phase
   - [US#] = Belongs to User Story N
4. **TDD Required** - For each implementation task:
   - Write/verify test exists and FAILS
   - Implement minimum code to pass
   - Run tests to confirm GREEN
   - Refactor if needed
5. **Mark progress** - Change `- [ ]` to `- [X]` in tasks.md after completing each task

## Verification Commands

Run after each task:
[VERIFICATION_COMMANDS]

## Phase Checkpoints

After completing each phase:
1. Run full test suite
2. Verify all phase tasks marked [X]
3. Commit changes with descriptive message

## Completion Criteria

You are DONE when:
- ALL tasks in tasks.md are marked [X]
- ALL tests pass ([TEST_COMMAND])
- ALL type checks pass ([TYPE_COMMANDS])
- ALL builds succeed ([BUILD_COMMANDS])

## If Stuck

After [STUCK_ITERATIONS] iterations without progress:
1. Document what's blocking in a comment
2. List attempted solutions
3. Move to next task if possible
4. Output <promise>SPECKIT_COMPLETE</promise> with incomplete task list

## When Complete

Output exactly: <promise>SPECKIT_COMPLETE</promise>
```

---

## Example Output

For the WordPress Auto-Publishing feature:

```bash
/ralph-loop:ralph-loop "Implement the WordPress Auto-Publishing feature following the task list at /home/user/project/specs/001-wordpress-publishing/tasks.md.

## Execution Rules

1. **Read tasks.md first** - Understand all 49 tasks across 7 phases
2. **Follow phase order** - Complete Setup → Foundational → US1 → US2 → US3 → US4 → Polish
3. **Respect markers**:
   - [P] = Can run in parallel with other [P] tasks in same phase
   - [US#] = Belongs to User Story N
4. **TDD Required** - For each implementation task:
   - Write/verify test exists and FAILS
   - Implement minimum code to pass
   - Run tests to confirm GREEN
5. **Mark progress** - Change '- [ ]' to '- [X]' in tasks.md after completing each task

## Verification Commands

Run after each implementation task:
- composer test:unit
- composer test:types
- composer lint
- npm run types
- npm run build:ssr (after React changes)

## Important Notes

- After modifying Jobs or Actions, note that Horizon needs restart (user will handle via Portainer)
- SSR build is REQUIRED after any React component changes

## Completion Criteria

You are DONE when:
- ALL 49 tasks in tasks.md are marked [X]
- ALL tests pass (composer test:unit)
- ALL type checks pass (composer test:types && npm run types)
- SSR build succeeds (npm run build:ssr)

## If Stuck

After 30 iterations without progress:
1. Document blocking issue as comment in relevant file
2. List what was attempted
3. Skip to next task if possible

## When Complete

Output exactly: <promise>SPECKIT_COMPLETE</promise>" --max-iterations 160 --completion-promise "SPECKIT_COMPLETE"
```
