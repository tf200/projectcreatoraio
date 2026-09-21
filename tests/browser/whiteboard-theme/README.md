# Whiteboard theme browser check

Renders the real `WhiteboardBoard` and `WhiteboardActivity` three times on one
page — themed with the modern props, themed with a failing activity read, and
plain with no props at all — against local HTTP fixtures. No live data is read
or written. The stylesheet is loaded after the component CSS, matching the new
interface's scoped override.

From the repository root (requires Docker, installed project dependencies, and
the local `project-mockup-renderer:local` Chromium image used for UI previews):

```sh
docker run --rm -v "$PWD:/app" -v "$PWD/tests/browser/whiteboard-theme:/check" -w /app node:22 node /check/build.mjs
docker run --rm -v "$PWD:/app:ro" -v "$PWD/tests/browser/whiteboard-theme:/check" -w /work project-mockup-renderer:local sh -c 'npm install playwright --no-audit --no-fund --ignore-scripts && node /check/check.cjs'
```

Checks that the themed board receives pointer events and the untouched one does
not, that the overlay button is hidden only under the theme, that a real click
reaches the handler, that the header and activity are restyled only under
`.pc-new .pc-whiteboard-theme`, that activity scrolls inside a fixed box with
sticky date headers, that an access failure reads as an error with a reader and
stays an empty list without one, that Focus mode unmounts the inline board while
the viewer overlay holds the file, and that the tab fits 390px. Screenshots are
saved beside the fixture.

The viewer handler here is a stub: the whiteboard app is not installed in this
harness, so this validates the host chrome and the opt-in props, **not** whether
the real editor persists while embedded. That has to be confirmed against a
running Nextcloud with the whiteboard app enabled.
