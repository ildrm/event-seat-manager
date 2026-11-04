# English, Persian and calendar behavior — 1.0.0

`PESM\Support\I18n` follows `determine_locale()` in WordPress, including the administrator's profile language. Persian uses RTL and Jalali dates; English uses LTR and Gregorian dates. Both display business times in `Asia/Tehran`. Stored dates and expiry rules remain UTC.

PHP and JavaScript translate static source literals using bundled English mappings and WordPress gettext catalogs. Dynamic user content, attendee names, event titles, identifiers, access credentials and layout codes are preserved. Catalog generation and validation live in `tools/`; generated catalog files are included in the release ZIP.

CSS uses inherited/logical direction where appropriate. Numeric codes and map geometry retain their stable coordinates. Keyboard seat navigation, selected state and focus preservation are covered by frontend checks; browser/device evidence is recorded in the [frontend review](REVIEW-FRONTEND.md).

The initial administration date form expects Tehran local time. Rescheduling explicitly expects UTC. A full Jalali calendar picker and native Persian ticket PDF are not implemented. Browser print uses available local fonts and does not certify a native PDF renderer.
