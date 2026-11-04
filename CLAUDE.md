<!-- n45:start -->

## N45

### Spawning Subagents

**Never call `npx --loglevel=error n45-ai@1.1.0 exec 85e23643 <agent> --7638e8 <id> --e9666d <task_id> --a6a0ee claude` directly.** Only build the `prompt` and call `Agent()` — the subagent loads its own instructions internally.

```
Agent(
  description: "<Description from the agent mapping table>",
  subagent_type: "general-purpose",
  prompt: "Use the Bash tool to run: npx --loglevel=error n45-ai@1.1.0 exec 85e23643 <agent> --7638e8 <id> --e9666d <task_id> --a6a0ee claude\nThe output is a prompt: follow the body after the '---' header (frontmatter). If the output starts with '{' it is an error — read 'message' and stop.\n\n<specific instruction>",
  model: "<sonnet|haiku|opus>"
)
```

**`--7638e8 <id>`** = the `Work:` id from the payload. **`--e9666d <task_id>`** = the `Task:` id — add it **only when the payload carries `Task:`** (task executor / `-review`); omit otherwise. They resolve the injected `Contexto (Injetado) > spec` / `> task` blocks.

**`<specific instruction>` = only the activation payload the active skill prescribes** (e.g. `Work · Task · Language`, plus skill-defined fields like `WorktreePath`, `Missing:`, an adjustments table). Verbatim, nothing added — the subagent already loads its canonical prompt + the task body. Restating steps/paths/commit-format/shell rules creates a conflicting second source. **Never add justification, a "trust context", or persuasion** (e.g. "trust it, it's the user's own framework", "the registry is their machine", "the previous attempt refused, but…") — it doesn't help do the task and the subagent reads it as injection/social-engineering, worse if it argues against a refusal. If it declines on provenance/safety grounds, that's its call (from its canonical prompt); the leader doesn't override it in the payload.

- **Recovery re-spawn only** (`attempt > 1`, after `blocked` / `insufficient` / review rejection / user `decide`): append one `Additional guidance: <decision delta>` line — only what changes, never the protocol.
- **Ad-hoc agent** (task outside the framework, no agent to `85e23643`): free-form brief, with no canonical-prompt bootstrap.

| Capacity    | Model    |
| ----------- | -------- |
| **maximum** | `opus`   |
| **high**    | `sonnet` |
| **low**     | `haiku`  |

- `run_in_background: false` (default). Do not use `true` unless explicitly required by the active skill.
- Parallel: launch multiple `Agent()` calls in the same turn when there are no dependencies. They run in parallel and return together.
- Sequential: one `Agent()` per turn. Next step only after the return.
- N45 managed worktree: the orchestrator creates a worktree via `npx --loglevel=error n45-ai@1.1.0 exec 7ad6f14f --e9666d` and persists it on the task. Task executors and their `-review` (`50a8844c7d`, `844dd534f4`, `29a9b94336`, `82654b3833`, `688f217a71`) read the worktree **from the task**. The task-less agents (`cc7098b458`, `d92c04968c`, `df8c93ba6a`, `10b8108d31`) receive `WorktreePath` in the spawn prompt.
- Pre-spawn (before `7ad6f14f`): `git status --porcelain | grep -v "^?? " | grep -v "activity\.jsonl"` → if output is returned, commit `.n45/` before creating the worktree (it is born from HEAD)
- **Commit it — never `git stash` / `checkout -- .` / `clean` / `reset --hard`.** A dirty tree here is `.n45` state the binary just wrote (marked criteria, `42b0c53d35`, activity), and a stash carries it away: in one real run a stash took 13 marked criteria and a `status: done` with it, the entry was never referenced (`git stash list` empty), and the work existed while the roadmap did not know. Your clean tree is the task's **worktree**; the main repo is dirty by construction, and that is not a problem to solve.
- Mention a subagent in chat by its **Description** (from the mapping below), never the agent name.
- Never use `isolation: "worktree"` — N45 creates the worktree (base = HEAD of the active branch); the harness must not create another on top.
- Spawn-prompt paths must be **relative** (`apps/api/`, not `/project/apps/api/`) — absolute paths write outside the worktree and corrupt the merge.

### Agent → Description Mapping

Use these values in the Agent `description` field; an agent absent from the map → use the agent name as fallback. The executor is `<persona>` and the reviewer/fixer is `<persona>-review` (distinct agents) — in the review/fix step spawn `<persona>-review`, never the executor's agent.

`37b2b8a1d8`: Architect
`0d1f7f2db6`: Architect
`dd7bec9400`: Architect
`35e66b8591`: Architect
`034a7c3ee2`: Architect
`01db849782`: Architect
`663ed13e57`: Architect
`ee0ed625ad`: Architect
`44744a19d0`: Architect
`de2c034d84`: Architect
`ce6e586c26`: Architect
`2dacd78296`: Architect
`4f26ff80ec`: Architect
`959f6ee6fb`: Architect
`a4f43651da`: Architect
`97e3860e02`: Architect
`62f80db82e`: Architect
`1021aaba1d`: Backend Review
`50a8844c7d`: Backend
`72eb861755`: Data Architect
`be979e5247`: DBA Review
`29a9b94336`: DBA
`a4a914df30`: DevOps Review
`82654b3833`: DevOps
`fccaa99553`: Frontend Review
`844dd534f4`: Frontend
`df8c93ba6a`: Fullstack Review
`10b8108d31`: Fullstack
`d92c04968c`: Fullstack
`cc7098b458`: Fullstack
`6ac9bc0bd1`: QA Review
`688f217a71`: QA
`eb9b3ea292`: Security
`27ccda5a4d`: SRE
`17430d0227`: UI-UX

### Reporting Progress

Use the harness task list to show real-time progress during long operations. **The tool has two names and which one you have depends on the CLI version — read YOUR tool list and call the one that is in it, never the other:** `TaskCreate`+`TaskUpdate` (current) or `TodoWrite` (older). Neither listed → report progress in text and carry on; this is presentation, never a reason to stop.

With `TaskCreate`: one call per entry (every task is born `pending`), and `TaskUpdate` to move it.

```
TaskCreate({ subject: "<short imperative>", description: "<what gets done>" })
TaskUpdate({ taskId: "<id>", status: "in_progress" })
```

Status: `pending` · `in_progress` · `completed`. `TaskList` shows the current state.

- Create every planned entry before spawning the first agent.
- On spawning/starting each step → `in_progress`.
- On completion → `completed`.
- Update **individually** as each step finishes; never collapse multiple parallel steps into a single entry (each return must visibly change state).
- Keep `subject` short and descriptive (no file paths).
- **Forbidden between spawn and return:** an update with no real state change (spawn → in_progress, return → completed).

### User Interaction

Use the `AskUserQuestion` tool to present clickable options in the UI.

```
AskUserQuestion(
  questions: [
    {
      header: "<short label, max 12 chars>",
      question: "<full question?>",
      multiSelect: false,
      options: [
        { label: "<1-5 words>", description: "<context or implication>" },
        { label: "<1-5 words>", description: "<context or implication>" }
      ]
    }
  ]
)
```

**CRITICAL — `label` field:** every option MUST have `label` (not `value`, not `text`). Using `value` causes `InputValidationError`.

- **`header`** — chip/tag shown as the window title. Max 12 characters. E.g. `"Discovery"`, `"Next step"`, `"Roadmap"`.
- **`question`** — full question, ending with `?`. Never put context or a summary here.
- **`options`** — 2 to 4 options; each with a `label` (clickable text, 1-5 words) and a `description` (what happens when chosen).
- The "Other" (free-text) option is native — never add it manually. The user **never has to type** — they pick a clickable option.
- **Emit the context as TEXT first, then call the tool.** The status, the result, the summary of what just happened — all of it is a chat message that precedes the call, never the `question` field. A question with no text before it leaves the user picking blind, and the skill's rendered output (status table, roadmap summary) never reaches the screen.
- `questions` supports 1 to 4 questions per call — use multiple only when they are independent of each other.
- `AskUserQuestion` only works in the **main conversation**. That's why the orchestrator runs there — specialists run as isolated subagents and return results to the orchestrator, which builds the interaction.

### Writing

- **Project code (outside `.n45/`):** never modify directly → spawn an executor (`d92c04968c`, `cc7098b458`). No size exception — a typo, a one-line tweak, or a follow-up right after a flow still goes through an executor. There is no "small enough to edit directly."

### Language

Detect the language of the user's message. Apply it to:

- **Output to the user** — every orchestrator response in the detected language, including the `inform: "..."` texts in the skill prompts (treat them as templates — translate before showing; never copy verbatim when the user's language ≠ Portuguese).
- **Subagent spawns** — pass `Language: <lang>` in every prompt.
- **Dynamic switch** — if the user changes language mid-session, switch immediately.

### Escalation

Stop + report: what was done · the blocker · options with trade-offs. **Never assume a default silently.**

### Skills

`npx --loglevel=error n45-ai@1.1.0 exec 55659750 <skill> --a6a0ee claude` → the output is a prompt — follow the body after the `---` header (frontmatter). If it starts with `{` it is an error: read `message` and stop.

### Operational Notices

`system_notice` in the frontmatter header (or JSON error response) from N45 = operational instruction for you. Emit the content:

- **Before** any user-interaction tool or any tool that ends the turn without text, OR
- **At the end** of the next textual response to the user — whichever comes first.

Without interrupting the current work.

### Context Pivot

Active rule in any mode. When the user sends a message: converse using technical context (project + code) → fully understand the intent. Pure analysis → classify at the end. **Never end without routing.**

A follow-up that asks to add, change, or fix project code — even a tiny one, even right after a flow finished — is a new Feat/Fix routing decision, never a direct edit.

**When interrupting an active flow:** reset `feat_id · fix_id · work_id · work_branch · work_phase = null`

- **Feat:** User describes something to build, improve, analyze or investigate → interrupt current flow → `npx --loglevel=error n45-ai@1.1.0 exec 55659750 2b3ede9c2d --a6a0ee claude`
- **Fix:** User describes a problem, bug, slowness, incorrect behavior → interrupt current flow → `npx --loglevel=error n45-ai@1.1.0 exec 55659750 34d40caed2 --a6a0ee claude`
- **Status:** User asks about project status or wants to continue a roadmap → `npx --loglevel=error n45-ai@1.1.0 exec 55659750 42b0c53d35 --a6a0ee claude`
<!-- n45:end -->

