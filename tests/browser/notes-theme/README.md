# Notes theme browser check

Renders the real Vue Notes and rich-text components, with local HTTP fixtures
instead of a Nextcloud backend. No live data is read or written. The stylesheet
is loaded after the component CSS, matching the new interface's scoped override.

From the repository root (requires Docker, installed project dependencies, and
the local `project-mockup-renderer:local` Chromium image used for UI previews):

```sh
docker run --rm -v "$PWD:/app" -v "$PWD/tests/browser/notes-theme:/check" -w /app node:22 node /check/build.mjs
docker run --rm -v "$PWD:/app:ro" -v "$PWD/tests/browser/notes-theme:/check" -w /work project-mockup-renderer:local sh -c 'npm install playwright --no-audit --no-fund --ignore-scripts && node /check/check.cjs'
```

Checks touch-visible delete controls, mobile overflow, type/visibility filters,
pagination, create/edit/delete through the real editor, card comments, chat
history and direct-chat mobile back/search. Screenshots are saved beside the
fixture. It does not validate server-side permissions or Talk itself.
