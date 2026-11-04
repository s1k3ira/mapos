---
name: n45-next
description: 'Keep going? Resume a paused roadmap and continue pending tasks'
disable-model-invocation: true
---

Run the command below via `exec_command` and follow the returned prompt — the body after the `---` frontmatter header:

`npx --loglevel=error n45-ai@1.1.0 exec init codex -c next`

If the output starts with `{` (JSON error) → show `message` to the user.
