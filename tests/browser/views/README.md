# New views browser check

Renders `ProjectFilesBrowser` (themed and untouched), `NewIntake`, `NewActivity` (with a
403 project) and `NewOverview` against local fixtures, with the real In Zicht theme
stylesheet mounted. No live data is read or written. From the repository root:

```sh
docker run --rm -v "$PWD:/app" -v "$PWD/tests/browser/views:/check" -w /app node:22 node /check/build.mjs
docker run --rm -v "$PWD:/app:ro" -v "$PWD/tests/browser/views:/check" -v "$PWD/../../themes/inzicht/core/css:/theme:ro" -w /work project-mockup-renderer:local sh -c 'npm install playwright --no-audit --no-fund --ignore-scripts && node /check/check.cjs'
```
