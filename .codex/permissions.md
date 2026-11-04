## Codex Permissions

Follow these permission rules when working on this project with Codex.

### Allowed

- Use `exec_command` to run `npx n45-ai@<version> exec ...` (the n45 skill entrypoint)
- Use `exec_command` to run the cached n45 binary at the absolute path returned in `content` (e.g. `~/.n45/bin/v<version>/n45`)
- Use `exec_command` for `git`, tests, linters, build and workspace inspection
- Use `apply_patch` to edit repository files
- Use `multi_tool_use.parallel` for independent reads and inspections

### Do Not Do Directly

- Do not edit files inside `.n45/docs` and `.n45/planning` directly
- Do not use Python, `jq` or pipes to post-process `n45` output
- Do not use shell commands to edit files manually when `apply_patch` would do

### How to Work With `.n45/`

- Every read and write of planning artifacts goes through the `n45` binary
- Treat `.n45/` as an area controlled by the project flow

### When to Ask for Approval

- When the command needs to leave the sandbox
- When the command needs network access
- When the command opens a GUI app
- When the command is potentially destructive and was not explicitly requested by the user

### Operational Rule

- First try to run normally inside the sandbox
- If the command fails due to a sandbox limitation and it is required, ask for approval with escalation
- Keep commands as specific as possible