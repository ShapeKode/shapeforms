Browser suites — run with `npm run test:browser`.

These drive the plugin's own admin assets in a real browser (Playwright) against
the real WordPress admin stylesheets, loaded in the real order. They exist
because the things they check cannot be asserted any other way:

  ai-loader.mjs    the AI builder's "Building your form…" state — that the
                   shimmer actually moves, that a failure clears it, that a
                   retry does not leave a stale error underneath, and that
                   prefers-reduced-motion removes the animation rather than
                   freezing it mid-sweep.

  start-cards.mjs  the new-form and empty-canvas card grids at every card count
                   and every breakpoint — that a row which is not full is
                   centred, that cards keep one width, and that nothing
                   overflows sideways.

The fixtures link the plugin's real CSS and JS by absolute path, so a change to
either is picked up without touching the fixture. Hand-written stand-ins were
deliberately avoided: a rule can pass against a copy for reasons the shipped
page does not share.

These are not packaged — `tests` is absent from package.json's `files`, which is
the only thing that decides what ships.

  ai-settings.mjs  the AI settings tab, driven against the REAL rendered markup
                   and the real settings.css. It exists because of a bug no PHP
                   test could see: every provider's API key field carried the
                   `hidden` attribute and rendered anyway, because
                   `.shapeforms-field-row { display: flex }` beats the user-agent
                   `[hidden] { display: none }` rule. Markup right, CSS right in
                   isolation, page wrong.

                   Its fixture is built by build-settings-fixture.mjs, which
                   lifts the inline provider-switch script straight out of the
                   admin class rather than keeping a second copy that can drift.
                   Refresh the captured markup with the wp eval in that file's
                   header comment when the tab's PHP changes.
