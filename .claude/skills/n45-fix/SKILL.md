---
name: n45-fix
description: "Something isn't working? Investigate and fix bugs, errors, or unexpected behavior"
---

Run the command below via `Bash` and follow the returned prompt — the body after the `---` frontmatter header:

`npx --loglevel=error n45-ai@1.1.0 exec init claude -c fix`

If the output starts with `{` (JSON error) → show `message` to the user.
