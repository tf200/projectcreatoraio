# Whiteboard tab

The modern Whiteboard tab edits the board in place instead of showing a picture
of it. The current interface is unchanged: every modern behaviour is a prop its
callers do not pass, and the styling lives in a stylesheet only the modern entry
loads.

## What changed, and where

- `src/new/whiteboard-theme.css`, scoped under `.pc-new .pc-whiteboard-theme`:
  flat header on a divider, file chip at `--border-radius-element`, buttons at
  the same radius, taller frame, activity without its card, and the activity
  list as a fixed-height scroller with sticky date headers. The same scope
  re-enables pointer events on the embedded handler and hides the overlay
  button, both of which are presentation rules in the component's own scoped
  styles.
- `WhiteboardBoard.vue` gains `inlineEditing`, `openMode` and `activityReader`.
  Defaults (`false`, `'popout'`, `null`) reproduce the current interface exactly.
  `inlineEditing` also drives `is-embedded`: the whiteboard app's
  `renderWhiteboardView` mounts its `ReadOnlyViewer` instead of the full `App`
  whenever `isEmbedded` is set, so an embedded board has no palette and no menu
  however interactive its CSS is. The current interface keeps `is-embedded`
  true, and with it the DAV `versionSource`; the modern tab passes false and
  loads the live collaborative document, the same component the viewer overlay
  has always used.
  With `openMode: 'overlay'` the primary action opens the Nextcloud viewer
  overlay in the same page — the path the popout window already used — and is
  labelled Focus mode.
- `WhiteboardActivity.vue` gains `reader`. Without it the legacy service keeps
  swallowing its own failures; with it a failure is shown instead of an empty
  board, and the list already on screen survives a failed later page.
- `src/new/ProjectModule.vue` passes those props and `api.whiteboardActivity`,
  a strict read added to `src/new/api.js`. `ProjectsService` is untouched.

## Editing and saving

While `inlineEditing` is set, the first pointer press inside the frame starts
the existing ten-second autosave, and leaving the tab force-syncs once more,
because an inline edit can be newer than the last cycle. Opening Focus mode
unmounts the inline board first: the board keeps its snapshot in IndexedDB keyed
by file id, so only one live instance may hold a file. Closing the overlay syncs,
refreshes the file metadata and remounts the inline board at the new revision.

## Verification

40 Node tests pass, 8 of them covering the opt-in props, the reader contract,
the embed flag and the suspend rule. The browser check under
`tests/browser/whiteboard-theme/` renders themed and untouched boards side by
side and asserts the difference, including the editor mounting only where
is-embedded is false, and a real click reaching the handler. Build and the
existing lint baseline are unchanged; `stylelint` cannot run in this checkout,
and already could not before these changes.

Not covered: the harness stubs the viewer handler, so it mirrors the
embedded/read-only branch but not Excalidraw itself. Saving, collaboration and
the palette in the tab have to be confirmed on a Nextcloud with the whiteboard
app and its websocket server running. If anything there misbehaves,
`inlineEditing` is the single flag to turn off, which returns the tab to a
read-only preview with Focus mode for editing.
