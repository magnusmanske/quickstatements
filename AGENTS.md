# QuickStatements contributor guidance

## Hard rules

- Never edit generated or linked files in `public_html/php` or `public_html/resources`.
- Do not add AI or assistant attribution to commit messages.
- Keep mobile layouts and touch-friendly interactions in mind.
- Treat this as a web-facing product: preserve security boundaries and never commit OAuth credentials, tokens, or local configuration.
- Prefer simple, readable, maintainable changes. Follow SOLID and DRY where they help rather than adding abstraction for its own sake.
- Add or update tests when the repository has a practical test seam for the change.

## What this is

QuickStatements is a Wikimedia Toolforge application for importing, reviewing, and running batches of Wikidata statements. The active frontend is a Vue 2 single-page application served directly from `public_html/`; the PHP API performs parsing, batch management, OAuth, and Wikidata edits.

## Architecture

### Frontend

- `public_html/index.html` is the application shell and navigation.
- `public_html/vue.js` loads configuration and Vue components, then creates the hash-based router.
- `public_html/vue_components/` contains Vue 2 templates and component scripts.
- `public_html/quickstatements.css` contains the local Vector/Codex-style presentation layer.
- `public_html/index_old.html` and `public_html/quickstatements.js` are the legacy interface. Leave them alone unless a task explicitly targets the old UI.

### Backend

- `public_html/api.php` is the request dispatcher keyed by `action`.
- `public_html/quickstatements.php` contains parsing, batch, OAuth, and edit behavior.
- `bot.php` and `new_job_from_qs.php` support background batch execution.
- `schema.sql` describes the MariaDB tables used by the tool.

### Shared library and local configuration

- `public_html/php` and `public_html/resources` are generated or linked from the sibling `magnustools` project and are ignored by Git.
- `public_html/config.json` is local/deployment configuration copied from `config.json.template` and is ignored by Git.
- OAuth configuration lives outside the repository. Never print, copy into tracked files, or commit consumer secrets or access tokens.

## Working in this codebase

- Vue 2 is used, not Vue 3. Do not introduce Composition API or single-file components without a deliberate migration.
- There is no frontend build step; edit the served files and reload.
- Run `git diff --check`, `node --check public_html/vue.js`, and PHP syntax checks for touched PHP files.
- PHPUnit requires installed Composer dependencies and an appropriate local configuration.
- Toolforge database and OAuth behavior cannot be fully reproduced by a plain local PHP process; keep local fallbacks isolated from production behavior.
- Preserve ToolTranslate attributes and rerun `tt.updateInterface` when translated elements are inserted asynchronously.
