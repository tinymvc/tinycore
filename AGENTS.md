# TinyMVC / Spark core development

For TinyCore implementation, debugging, review, or test work, use the bundled [tinymvc-spark skill](skills/tinymvc-spark/SKILL.md). Read only the references needed for the task. This repository is the core engine; inspect `src/` and run its own `php tests/run.php` suite. For bounded API discovery from this root, use `php skills/tinymvc-spark/scripts/api-lookup.php <term> --source=src`.

Maintain the skill's affected guidance, references, and examples alongside public API or behavior changes. The skill ships in the Composer package so applications receive guidance for their installed version. Keep framework guidance here; Skeleton owns only the stable application entry point and application-specific conventions.
