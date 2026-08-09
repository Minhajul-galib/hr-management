# Pages Structure Guide

Use feature-based folders under `resources/js/Pages`.

## Recommended pattern

- `Feature/Index.vue` for listing/dashboard/home page
- `Feature/Create.vue` for create form
- `Feature/Edit.vue` for edit form
- `Feature/Show.vue` for details page
- `Feature/Partials/*` for feature-only reusable UI blocks
- `Feature/Composables/*` for feature-only state/logic helpers

## Current direction

Folder-based pages are now preferred, for example:

- `Dashboard/Index.vue`
- `Welcome/Index.vue`
- `Credits/Index.vue`
- `Units/{Index,Create,Edit,Show}.vue`

When creating new modules, always keep pages inside their own feature folder.
