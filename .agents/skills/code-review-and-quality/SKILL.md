---
name: code-review-and-quality
description: Conducts multi-axis code review. Use before merging any change. Use when reviewing code written by yourself, another agent, or a human. Use when you need to assess code quality across multiple dimensions before it enters the main branch.
---

# Code Review and Quality

Source: https://github.com/addyosmani/agent-skills/blob/91d4d07522de9577caf5d213e5bf1acc38fa3df2/skills/code-review-and-quality/SKILL.md

Use the upstream `code-review-and-quality` skill when reviewing changes in this repository. Apply its five-axis review across correctness, readability and simplicity, architecture, security, and performance. Review tests first, categorize findings by severity, verify the verification story, check for dead code after refactors, and apply dependency discipline before approving changes.

This repository uses the condensed guidance in this file as its local runtime fallback. The pinned upstream link records immutable provenance and is not required for a review to run.