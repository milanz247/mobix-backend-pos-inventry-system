# Ecommerce Web Settings

The Ecommerce sidebar now includes **Web Settings** for the announcement strip above the Next.js navigation. Staff with the existing `storefront.manage-homepage` permission can edit the plain-text message (up to 240 characters), choose right-to-left or left-to-right movement, and show or hide it. The edit page includes a preview. The public API is `GET /api/storefront/v1/site-announcement`, returning only `text`, `direction`, and `enabled` for the storefront business. When no setting has been saved, Next.js retains the original notice. A saved disabled setting hides the strip.

The additive migration `2026_11_02_000010_create_web_storefront_settings.php` creates one business-scoped settings row. It was created as source only and was not run against the active POS database. Apply it through the reviewed deployment process before using the settings page.

Next.js reads the endpoint through its same-origin public catalog proxy with no-store responses. The notice text is rendered as text, not HTML. The animation loops in the selected direction. It no longer pauses merely because the pointer is over the strip. An explicit Play/Pause control is available; reduced-motion preferences pause it by default, and the visitor can choose Play. No order, inventory, or payment behavior is affected.
