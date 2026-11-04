---
name: n45
description: 'Start here: detects project context and routes work to create, fix, continue, or status flows automatically.'
disable-model-invocation: true
---

Run the command below via `exec_command` and follow the returned prompt — the body after the `---` frontmatter header:

`npx --loglevel=error n45-ai@1.1.0 exec init codex`

If the output starts with `{` (JSON error) → show `message` to the user.
