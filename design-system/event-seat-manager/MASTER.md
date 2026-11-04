# Event Seat Manager — UI/UX design system

Applies to the WordPress/PHP/vanilla-JavaScript plugin in both Persian and English. This task explicitly uses the installed UI UX Pro Max skill at `/Users/shahinilderemi/.codex/skills/uiux-ui-ux-pro-max-6b1995f0/SKILL.md`.

## Verified guidance and product fit

The skill search `management SaaS dashboard --design-system --variance 4 --density 7` returned a suitable trust-blue palette, approachable sans-serif typography and light-mode direction. Its marketing hero pattern and decorative glass treatment do not fit this operational plugin, so they are not used. The event-oriented query also returned a conference landing pattern, not an admin workbench. No verified progressive-disclosure result was found after one narrower retry; the section/navigation design below is a project decision grounded in the skill quick-reference rules for hierarchy, primary actions, content priority and forms, not a claimed database match.

## Direction

A calm, precise event operations workspace. Warm white content cards on a cool light background, a deep navy navigation rail, blue primary actions, restrained teal availability and amber warnings. Public booking uses the same language with more space and a clear reservation summary. Avoid marketing graphics, gradients, glass blur, emoji icons, fake analytics or invented revenue. Preserve WordPress's own global navigation and every permission boundary.

## Tokens

| Token | Value |
|---|---|
| `--pesm-primary` / hover | `#2563eb` / `#1d4ed8` |
| `--pesm-ink` | `#17243b` |
| `--pesm-muted` | `#52627a` |
| `--pesm-surface` / canvas | `#ffffff` / `#f4f6fa` |
| `--pesm-border` | `#dce3ed` |
| `--pesm-nav` | `#101d33` |
| `--pesm-success` | `#0f766e` |
| `--pesm-warning` | `#92400e` on `#fff7e6` |
| `--pesm-danger` | `#b42318` on `#fff1f0` |
| Spacing | `4, 8, 12, 16, 24, 32, 48px` |
| Radii | controls `10px`, cards `16px`, badge `999px` |
| Controls | minimum `44px`; body `16px`, labels `14px`, metadata `12px` |
| Typography | local Vazirmatn where available; system sans-serif fallback; no external font runtime requests |
| Motion | color/shadow `160ms`; respect reduced motion; no layout-shifting hover |

## Admin structure

A scoped `.pesm-admin` app shell with a clear header, descriptive page heading, small real counts, grouped navigation and a content column. Main areas: Overview, Events & Sessions, Halls & Layouts, Tickets & Refunds, Reports, Settings. Navigation is accessible, URL/hash addressable, restores the active section, and supports mobile. Render only permitted actions. Organize existing forms/tables into titled cards; preserve all form actions, field names, nonces and database behavior. Use progressive disclosure for exceptional/technical actions. Render every section when enhancement JavaScript is unavailable.

## Public structure

Scope shared components under `.pesm-ui`. Event listing uses readable event cards and a true empty state. Event detail prioritizes title, organizer, date/location and session selection. Seat booking has numbered steps, distinct map and summary panels, explicit state legend with labels/shapes, clear total and one primary reservation action. Secondary lease controls are visually subordinate. Checkout fields follow a consistent grid with permanent labels. Tickets and recovery use clear panels, receipt and print-friendly ticket cards. Gate prioritizes one scan/manual-entry flow. Never reveal or copy private credentials into decoration, navigation or URLs.

## Responsive and accessibility

Desktop content uses max-width 1360px; public max-width 1160px. At 1024px collapse optional rails; below 768px use one column and wrapping navigation. Verify at 375,768,1440px, RTL/LTR, 200% zoom. Tables scroll inside their own containers, never widen the entire page. Form controls and menu items have 44px targets; seat geometry remains unchanged and uses the existing map zoom/search/keyboard controls. Visible 3px focus, native buttons/labels/details, aria-current/expanded when relevant; no role=tab unless full tab interaction exists. All normal text meets 4.5:1. `[hidden]` always wins over layout styles. No global WordPress/theme CSS leakage. Loading/error/empty/success states use truthful text, not color alone.

## Ownership

Root owns shared `assets/css/ui.css`, existing public/map/cart/editor styles and `src/Support/Ui.php`. Admin worker owns `src/Admin/Pages.php`, `assets/css/admin.css`, `assets/js/admin.js`. Public worker owns `src/Frontend/Shortcodes.php`, presentation in `src/Integrations/CartSeatManager.php`, and public JS where needed. QA/localization worker owns new translation keys/catalog regeneration and review evidence. Coordinate before touching another scope.
