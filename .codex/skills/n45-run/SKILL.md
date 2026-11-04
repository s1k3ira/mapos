---
name: n45-run
description: 'Start or stop the application (dev server, containers, local services)'
disable-model-invocation: true
---

Run the command below via `exec_command` and follow the returned prompt — the body after the `---` frontmatter header:

`npx --loglevel=error n45-ai@1.1.0 exec init codex -c run`

If the output starts with `{` (JSON error) → show `message` to the user.
