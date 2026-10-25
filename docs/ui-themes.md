# Light and dark appearance

The shared authenticated and guest layouts load `public/js/app-theme.js` before
styles. It reads `ariya.display-theme.v1`, defaults to light, and applies
`data-app-theme` and Bootstrap's `data-bs-theme` to `html`. The shared
`layouts/theme-toggle` button is keyboard accessible and has a state-dependent
Persian accessible label. Storage failure does not disable the control. Storage
events synchronize other open tabs on the same origin. No server preference,
database migration, or business action is involved.

`public/css/app-theme.css` owns shared dark tokens and Bootstrap controls. Both
dark tokens and page compatibility rules apply only to screen media. Printing
retains the original light variables and styles, independently of the preference.
The toggle is hidden in print.

## Existing page styles

`public/js/app-theme-compat.js` provides a color-only bridge for legacy styles:

- Reads only stylesheets actually loaded by the current page and accessible on
  the same origin, including inline style blocks. It never fetches other pages.
- Produces separate dark selectors; it never edits the original stylesheet or
  element style attributes. Images and SVG image URLs are preserved.
- Maps text, surfaces and borders separately. Color custom properties get
  separate role aliases so a variable used for both text and background remains
  readable. Non-color properties and business values are not rewritten.
- Handles CSSOM's empty longhands for variable-containing shorthands, nested
  variable fallbacks, grouped selectors, media conditions and support rules.
- Observes dynamically inserted styles and inline colors. Parsed stylesheet
  output is cached; DOM changes are debounced. The generated style node is
  excluded from observation to prevent a feedback loop.
- Does not convert print-only rules. Cross-origin stylesheets cannot be read;
  shared controls have explicit theme styles instead. Chart canvases, raster
  images and third-party frames are not recolored.

New UI should use `--app-*` tokens directly rather than adding more fixed colors.
Use `app-surface` for panels, `app-text`/`app-muted` for text, and the success,
warning and danger token pairs for states. Do not invert the page or its images.
For a new stylesheet or widget, verify its disabled, selected, hover, focus and
validation states in both themes. The compatibility layer covers existing
hex/RGB/named colors; new color syntaxes or rendered canvases need explicit review.

## Verification

Run `node --test tests/Js/app-theme.test.cjs` for startup preference, storage
failure, accessible toggle state, tab synchronization, scoped selectors, variable
fallbacks, image preservation, shorthand handling and representative contrast.
Check desktop and narrow screens, the Persian calendar, Select2 popups, forms,
modal dialogs, tables, and print preview before expanding the theme contract.
Appearance changes must never submit forms or mutate stock/accounting data.
