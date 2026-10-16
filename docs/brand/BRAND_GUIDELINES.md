# Math Mastery Hub — Brand Guidelines

**Status:** Approved visual direction; documentation only, not yet implemented.
**Brand:** Math Mastery Hub by Eng. Yahya Ibrahim
**Visual source of truth:** The user-approved Math Mastery Hub landing-page mockup shared in the project conversation on 2026-10-16. This document translates that mockup into enduring rules; it does not replace the mockup.

> If a future agent cannot see the approved mockup, ask for the original screenshot before making layout or visual changes. Do not treat a verbal description or an AI-generated alternative as an equivalent reference.

## 1. Purpose, audience, and positioning

- **Primary audience:** Students in Grades 9–12 studying IGCSE and A-Level Mathematics.
- **Website interface language:** English. Do not assume the language of live teaching or explanation from the interface language.
- **Positioning:** **Math Mastery Hub by Eng. Yahya Ibrahim** is the unified learning space for following online mathematics sessions and accessing their associated materials.
- **Core experience:** Students can find session recordings, lesson notes, classified questions, homework/assignments, and available progress information in one organized environment.
- **Brand promise:** An orderly, supportive learning experience built around real lessons and their resources. Do not promise a feature unless it exists and is confirmed.
- **Not the current message:** Do not advertise a future course marketplace, speculative products, or unlaunched features unless explicitly requested.

## 2. Brand personality

**Clear · Organized · Modern academic · Trustworthy · Encouraging.**

The brand is academically credible without looking institutional or cold. It should feel approachable to teenagers without being childish. Clarity and useful structure come before decorative spectacle.

The approved mockup sets the tone: warm paper-like light surfaces, elegant editorial headings, practical sans-serif interface text, restrained turquoise/orange, generous but controlled whitespace, and an authentic student-workspace preview.

Avoid replacing that direction with a generic SaaS aesthetic, a minimal wireframe, a futuristic dashboard, or an unrelated educational template.

## 3. Official visual reference and layout rules

The **approved landing-page mockup** is the authoritative visual reference for the landing page, including its typography mood, visual hierarchy, relative spacing, section composition, header, hero, student-workspace preview, and course presentation.

- Preserve the **current page order and existing content** unless the user explicitly requests a change.
- Preserve the recognizable header/navigation, editorial hero, workspace preview, and course discovery hierarchy shown in the approved reference.
- Do not independently reimagine the hero, replace it with a random mathematical equation, or generate alternative layouts.
- Use authentic course titles, resources, and available assets. Present learning-related information accurately.
- Public landing pages may use expressive editorial type and generous whitespace; student study interfaces should be denser and more task-focused while remaining visually related.
- For future responsive work, maintain the same hierarchy and intent across desktop, tablet, and mobile; do not allow navigation/actions to be cropped.
- Treat this file as **identity guidance**, not authorization to change the current live site.

## 4. Typography

| Role | Typeface | Usage |
| --- | --- | --- |
| Interface and body | **DM Sans** | Navigation, paragraphs, buttons, labels, form fields, course information |
| Editorial/display headings | **DM Serif Display** | Main landing-page headlines and major editorial headings |
| Small technical labels | **IBM Plex Mono** | Eyebrows, small section labels, sequence markers, restrained metadata |

- Use clear typographic hierarchy, comfortable line height, and controlled line lengths.
- Use the serif style to give major public headings a distinctive human/academic voice; do not apply it indiscriminately to dashboards, inputs, or every card heading.
- Monospace is for small labels and notation-style metadata, not paragraphs.
- Render mathematical expressions with appropriate math typesetting when needed; do not rely on the branding fonts alone for complex mathematics.
- Avoid introducing additional fonts without explicit approval. Font loading/licensing and implementation are for a later phase.

## 5. Approved theme palettes

These exact values are the **approved brand reference tokens** for the future landing-page design implementation. They are *not* a claim that the current CSS already uses them.

| Semantic role | Light mode | Dark mode |
| --- | --- | --- |
| Page background | `#F4F2E8` | `#111C1B` |
| Surface | `#F8F7F1` | `#152321` |
| Primary text | `#1B2927` | `#E9ECE5` |
| Secondary text | `#5E706C` | `#B4C0BA` |
| Turquoise / brand primary | `#087771` | `#62C4BD` |
| Orange / accent | `#F5A92F` | `#F6AB32` |

### Color usage

- **Turquoise** is the main brand/action accent; **orange** is a selective secondary highlight, not a competing primary color on every component.
- Use background and surface values deliberately to create hierarchy without heavy outlines or shadows.
- Treat light and dark modes as intentionally designed counterparts, **not an automatic inversion**.
- Provide readable text and controls; check contrast for the *actual foreground/background pair* and interactive states before implementation. Do not presume that accent-colored text or white-on-orange is accessible.
- Additional border, hover, focus, disabled, and status shades may be derived in implementation as needed, but must harmonize with these approved reference colors and remain accessible.
- Do not silently substitute the existing design-system.css color scheme for this approved landing-page reference. A future migration must be scoped, reviewed, and tested separately.

## 6. Components and styling

- **Cards:** restrained corners, approximately **7–8px** radius.
- **Buttons:** restrained corners, approximately **5px** radius.
- **Theme switch:** retain a **pill/capsule** shape.
- Prefer clear hierarchy, alignment, appropriate whitespace, and subtle borders to decorative depth.
- Avoid random gradients, oversized shadows, glow effects, glassmorphism, meaningless floating elements, and excessive rounded-card patterns.
- Use a consistent set of clear, recognizable icons. Prefer the icon assets/library already used by the project; do not mix unrelated icon styles.
- Preserve purpose, keyboard access, visible focus, and legibility in both themes.

## 7. Logo and educational assets

- **The current logo is fixed.** Never redraw, recolor, stretch, crop, reinterpret, or replace its artwork without explicit approval.
- Use existing approved logo assets/variants and maintain appropriate clear space and proportions. Where the current file has a white background, handle its placement deliberately; do not modify the artwork to hide this.
- **Use the course covers already available in the project.** Do not replace real course covers with invented equations, mathematical icons, generic placeholder graphics, or AI-generated illustrations.
- Keep course-cover aspect ratios intact. If an asset is unavailable, flag the missing asset instead of fabricating a substitute.
- Any mathematical illustration introduced later must be mathematically correct and relevant to the lesson, not decoration for its own sake.

## 8. Content and integrity

- Keep the website's interface copy in English.
- Preserve existing content and page/section order unless explicitly instructed otherwise.
- Do not invent testimonials, numbers, awards, student progress, availability, prices, features, or outcomes.
- Do not add new promises about reports, tracking, support, purchases, or other functionality without verifying the actual product behavior.
- If existing content appears to make an unverified claim, flag it for review rather than silently rewriting it during identity-only work.

## 9. Future change checklist (not an implementation request)

Before *any future* frontend change:

1. Read this guide, the applicable `AGENTS.md` instructions, and the approved mockup image.
2. Determine whether the proposed change is explicitly requested and within scope.
3. Reuse existing UI assets, course-cover files, and components; keep established page order and content unless authorized to change.
4. Apply the three typefaces and the approved color roles consistently; avoid inventing new brand styling.
5. Inspect desktop and mobile layouts and **both light and dark modes**.
6. Check text contrast, interaction states, focus visibility, responsiveness, and alignment to the approved screenshot.
7. Report any design conflict or missing visual reference to the user before proceeding.

## 10. Implementation boundary

**This phase freezes the brand direction only.** The current production CSS, theme tokens, components, pages, routes, content, authentication, and backend must remain unchanged. A later, explicitly approved task will implement and visually verify the design. Existing code—including `resources/css/design-system.css`—may not yet match the approved palette or typography; do not modify it as part of this documentation-only phase.
