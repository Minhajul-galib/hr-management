[OPEN] Debug Session: pi-wise-analysis-500

## Symptom
- `GET /procurement-followup/pi-wise-analysis` returns HTTP `500`
- Frontend now shows styled `ErrorPage`, so client bootstrap crash is already bypassed

## Hypotheses
1. `ProcurementFollowupController::piWiseAnalysis()` is throwing while building props for Inertia.
2. A page-specific dataset such as `merchantTeamNameMap` contains unexpected values and triggers an exception.
3. A backend dependency used only by `pi-wise-analysis` is unavailable or returning invalid data.
4. The page component expects props that are not being produced correctly, causing the Inertia response pipeline to fail.
5. A recent UI/backend refactor for procurement follow-up pages introduced a regression in this route only.

## Evidence Plan
- Capture the latest Laravel exception stack for this route.
- Reproduce the request locally if possible.
- If logs are insufficient, add minimal instrumentation to the controller entry path only.
- Confirm root cause from evidence before applying any logic fix.

## Evidence Collected
- Project has SSR enabled via [ssr.js](file:///root/development/yearn-management/resources/js/ssr.js).
- `PIwiseAnalysis.vue` was the only page with top-level browser-only imports: `jspdf` and `html2canvas`.
- Symptom was route-specific `500` on first page render, which matches SSR module-evaluation failure before client hydration.
- Existing Laravel log did not contain a fresh stack for this route, so the strongest runtime-adjacent evidence was the SSR-enabled environment plus page-unique browser-only imports.

## Hypothesis Status
1. `ProcurementFollowupController::piWiseAnalysis()` throws while building props: inconclusive, low confidence.
2. `merchantTeamNameMap` data shape crashes render: not supported by evidence.
3. Browser-only dependency import breaks SSR for this page: supported, highest confidence.
4. Props mismatch causes Inertia render failure: not supported by evidence.
5. Recent refactor caused route-only regression: supported in narrowed form via PDF feature imports.

## Fix Applied
- Removed top-level `jspdf` and `html2canvas` imports from `PIwiseAnalysis.vue`.
- Added lazy `import()` loading inside `downloadPDF()`, so those libraries load only in the browser when the PDF button is clicked.

## Instrumentation Added
- `resources/js/ssr.js`
  - `A`: SSR page entry
  - `B`: component resolve success/failure
  - `C`: SSR setup reached
- `bootstrap/app.php`
  - `D`: global exception responder logs exception class/message/status/url
- `app/Http/Controllers/ProcurementFollowupController.php`
  - `E`: `piWiseAnalysis()` controller entry and response-built milestone
