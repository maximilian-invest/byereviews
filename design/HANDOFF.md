# Handoff: byereviews – Website, Order Flow, Customer Dashboard & Blog

## Overview
byereviews is a single-service brand: removal of individual false, unfair or policy-violating **Google reviews** (the profile stays intact). English only, markets US/UK/CA/AU (USD) and EU (EUR). Pricing is **No Cure, No Pay** – nothing is charged upfront; the customer pays only for reviews that were actually removed.

This package covers:
1. Marketing landing page
2. Multi-step order flow (find business → pick reviews → contact → confirm)
3. Success page with confetti + account creation
4. Login + per-customer dashboard (status, invoice/payment, support chat, new order)
5. Blog / guides (index + article template) with 10 finished SEO posts
6. Mobile layouts for all of the above (mobile is **higher priority than desktop**)

## About the Design Files
The files in this bundle are **design references built in HTML** – working prototypes that show the intended look, copy and behavior. They are **not production code**. Recreate them in the target stack (brief: **Next.js** marketing site + dashboard, light Node/Fastify backend, Postgres, Stripe Payment Links + webhook, transactional email, deploy on Railway) using that codebase's patterns. Open the `.dc.html` files directly in a browser to click through (they load `support.js` next to them). All mock data (profiles, reviews, statuses, accounts) is simulated client-side and must be replaced by real backend logic.

## Fidelity
**High-fidelity.** Final colors, typography, spacing, copy and interactions. Recreate pixel-accurately.

---

## Design Tokens
**Colors (monochrome only – no accent colors)**
| Token | Hex | Use |
|---|---|---|
| ink | `#151515` | primary text, primary buttons, dark cards |
| page-outer | `#E4E4E4` | body background |
| page | `#EFEFEF` | main rounded canvas |
| surface | `#FFFFFF` | cards, nav pill, inputs on dark |
| input | `#F4F4F4` / `#F7F7F7` | input fields (border `#D2D2D2` on light forms) |
| hero-dark | `#0E0E0E` | hero video background |
| dark-2 | `#2A2A2A` | secondary buttons on black |
| grey-headline | `#9E9E9E` (on light) / `#6B6B6B`–`#7A7A7A` (on dark) | first half of two-tone headlines |
| muted | `#6B6B6B`, `#8A8A8A`, `#B5B5B5`, `#C8C8C8` | secondary text (lighter on dark) |
| hairline | `#E6E6E6`, `#EFEFEF`, `#DCDCDC` | dividers |

**Typography** – `Geist` (400/500/600) for everything, `Geist Mono` (400/500) for small meta labels (`// order BR-12345`, step numbers, "SHORT ANSWER").
- Hero H1: `clamp(40px, 6.2vw, 92px)`, weight 500, line-height .98, letter-spacing -.045em
- Section H2: `clamp(36px, 4.4vw, 64px)`, 500, lh 1, ls -.04em
- Wizard/dashboard H1: `clamp(34px, 4vw, 52–56px)`, 500, ls -.04em
- Body: 15–17px, lh 1.45–1.7; meta 12–14px
- **Signature pattern:** two-tone headlines – first clause grey, second clause ink/white, e.g. `<grey>Priced per review.</grey> Charged only when it's gone.`

**Radii:** canvas 36px; large cards 28–32px; cards 20–24px; buttons 14–22px; small chips 9–12px; icon squares 12–17px.
**Spacing:** fluid via `clamp()` – section padding `clamp(56px,8vw,96px) clamp(6px,2.6vw,36px)`; card padding `clamp(18px,2.4vw,28px)`; grid gaps 10–16px.
**Shadows:** used sparingly – floating bars/buttons `0 8px 30px rgba(0,0,0,.16–.22)`; modals `0 30px 80px rgba(0,0,0,.3)`.
**Signature button:** pill with an inset square icon block containing a right arrow (`→` path `M3 8h9M8.5 4l4 4-4 4`). Dark button = black pill + white icon square; light button = white pill + black icon square.
**Breakpoint:** mobile layout below **760px** viewport width. Test on iPhone 16 Pro (393×852).

## Assets
**All images + videos:** open `assets.html` (preview + download links) or run `download-assets.sh` (macOS/Linux) / `download-assets.ps1` (Windows) in this folder – they download all 17 files into `assets/img`, `assets/blog`, `assets/video` with clean names (see `assets/manifest.json`). The HTML files and `blog-posts.json` in this package already point to these local paths.

**Blog posts:** `blog-posts/*.md` – all 10 posts as Markdown with front-matter (title, slug, keyword, cluster, cover, author) and internal links as `/blog/{slug}`. Same content as `blog-posts.json`.

- `assets/byereviews-logo.png` – wordmark (black on transparent). On black backgrounds use CSS `filter: invert(1)`.
- Hero video (Veo 3.1 via OpenArt, 8s, no audio, plays at **0.6× speed**, muted/autoplay/loop/playsinline):
  - Desktop 16:9: `https://cdn.openart.ai/openart-ai/production/2026-09/create-video/tytMMZ5WaC4DgstcABWW/sample_0_1790775852876_6f0f043e.mp4`
  - Mobile 9:16: `https://cdn.openart.ai/openart-ai/production/2026-09/create-video/tytMMZ5WaC4DgstcABWW/sample_0_1790775856153_523e0aee.mp4`
  - **Download these and self-host** – CDN links are not permanent.
- "How it works" step images + 10 blog cover images: OpenArt CDN URLs inside the HTML / `blog-posts.json` – download and self-host.
- Icons are inline SVG (arrow, check, burger, close, chat bubble, envelope). No icon font.

---

## Screens / Views

### 1. Header (all pages)
- Sticky (`top: 8px`), `justify-content: space-between`.
- Left: white nav pill (radius 20) with logo (22px high) + links **How it works · Pricing · Cases · FAQ · Guides · Log in** (15px, `#555`; Log in 500 ink).
- Right (desktop): white "Remove a review" pill with black icon square.
- **Mobile:** logo left, **burger button right** (52×52 white, radius 18). No other buttons in the mobile top bar.
- **Mobile menu:** full-screen black overlay (`position:fixed; inset:0`), own top row with **white logo left and dark X button right**, large links (30px, 500, divider `#2A2A2A`), white "Remove a review" button at bottom + caption "No upfront payment · pay only for removed reviews".
- No currency switch anywhere (see Currency rules).

### 2. Landing page (in order)
1. **Hero** – dark (`#0E0E0E`), full-bleed looping video background with gradient overlay (desktop: left→right `rgba(14,14,14,.95)` → transparent at 62%; mobile: top/bottom darkening). H1: grey "One fake review is costing you customers." / white "Get it removed." Bottom row: white CTA "Remove a review" + "No upfront payment", and glass badges (`rgba(255,255,255,.08)`, border `.14`): "Trustpilot ★ 4.9", "No cure, no pay", "Profile stays intact". Min-height `clamp(640px, 88vh, 820px)`.
2. **How it works** – 3 cards with image (4:3): 01 Pick the reviews / 02 We check & remove it / 03 Pay only for what's gone (third card black).
3. **What we can remove** – one white card, checklist (Fake reviews, Policy violations, Defamatory claims, Competitor attacks).
4. **Pricing** – 3 cards: `90` recent (≤ 4 weeks) · `125` older (> 4 weeks) · black "No cure, no pay." card with volume discount line and example total.
5. **Cases** – H2 "Thousands of reviews deleted." + **3 marquee rows** of 12 case cards each, auto-scrolling (left / right / left at different speeds), pause on hover, edge fade mask. Plus one quote card ("… gone in 24 hours …").
6. **FAQ** – accordion (cost, time, legal, failure, which qualify, profile intact).
7. **CTA band** (black) + footer (Imprint, Terms, Right of Withdrawal, Privacy).
8. **Mobile:** fixed bottom CTA bar "Remove a review / No upfront payment".
> All case numbers, rating, quotes are **placeholders** – replace with real data.

### 3. Order flow (4 steps; tabs: Business · Reviews · Contact · Confirm)
- **Step 1 – Find your business:** centered column (max 760), one input "Business name or Google profile link" + black "Find" button → loading skeleton → profile card (name, rating, address, "✓ Found"). "No profile found" state → button "Add reviews by link". Link below: "Can't find your business? Add reviews by link". **Continue only appears once a business is found.** Order summary hidden on step 1.
- **Step 2 – Tap the reviews that should go:** list of the profile's reviews sorted lowest stars first; filter `1–3 ★` (default) / `All`; search. Each row is a selectable card (checkbox, avatar, name, stars, age, text, price tag auto-derived from age). **Star-only reviews without text are greyed out and not selectable** ("Star rating only — no text, can't be removed"). Collapsible fallback "+ Review not listed? Add it by link" → per-review input that accepts a Google share link (`share.google/…`, `maps.app.goo.gl/…`) or pasted text (+ reviewer name); pasted link shows a **preview card** (name, stars, date, text, "Review found", "Not this one?") and auto-sets the age tier. Button "How to find the review link" opens an animated 6-step browser demo (search business → See all reviews → find review → click it, then share icon → Copy link → paste).
  - Manual-only mode (no profile) shows only the link fields.
- **Step 3 – Where should we reach you?:** Full name, Email, Company, Phone (optional), Street, ZIP & city, Country. **Company, phone, street, city and country auto-fill from the Google profile** with a "✓ from Google" tag. **Email check:** if the email already has an account, show inline "✓ Welcome back! You already have an account — this order will be added to your dashboard."
- **Step 4 – Confirm:** black 3-column principle card (No upfront payment · Billed per removal · Due on removal day), contact summary, required checkbox for Terms + Right of Withdrawal.
- **Order summary (right sidebar desktop / below content on mobile):** Recent × n, Older × n, Subtotal, Volume discount line, 4-tier strip (1 — / 2 5% / 3–5 10% / 6+ 15%, active tier black), black total card "Total if every review is removed" + "avg. per review · $0 due today", button "Add N more for X% off everything".
- **Navigation:** desktop – floating sticky Back (white) + Continue (black) buttons at bottom, **no bar behind them**. Mobile – fixed bottom bar: back arrow, total + "$0 due today", Continue.

### 4. Success page (after submit)
- Full page, **confetti burst** (black/grey/white rectangles + a few stars, canvas, ~4.5s, fades out).
- Centered white card: check icon, "Order received.", confirmation text, `// order BR-xxxxx`, black info box with envelope icon:
  - new account → "Your login details are on the way" + "…emailed your login details to {email}."
  - existing account → "Added to your existing account".
- Primary full-width button **"Open my dashboard"** → login page with email prefilled (password must be entered from the email). "Back to home" as text link.
- **Password is never shown on screen.**

### 5. Login
Centered card: "Your dashboard.", Email, Password, error "Email or password doesn't match.", Log in button.

### 6. Customer dashboard (per customer)
- Header: `// order BR-xxxxx · {business}`, "Hi {first name}, here's your status." Actions: **+ New order** (black), Log out.
- **Tabs:** Overview · Support (unread badge).
- **Overview:** 4 stat cards (Removed x / y · In progress · Google rating now "from" before · black payment card with amount due + "Pay now"); 4-step progress bar (Order received → In review → Removed → Paid, with dates); **reviews split into "Active" (submitted, in progress) and "Done" (removed, not eligible)** with count badges; status chips: Submitted (grey), In progress (outlined), ✓ Removed (black, text struck through, "$x due" / "Paid $x"), Not eligible (outlined grey, "Cancelled · free"); **Invoice** (line per removed review, volume discount, total, buttons "Apple Pay" + "Pay by card" → Stripe, then "✓ Paid").
- **Support tab:** chat thread (team bubbles `#F4F4F4`, customer bubbles black), textarea (Enter sends, Shift+Enter newline), send button; header "Usually replies within 2 hours".
- **Floating chat button** bottom-right (60×60 black, radius 20, speech-bubble icon, unread badge) on Overview → opens Support tab. Support exists **only inside the dashboard**.
- "+ New order" re-enters the order flow with business + contact data kept (starts at step 2 if the business is known).

### 7. Blog (`byereviews Blog.dc.html` + `blog-posts.json`)
- Same header/menu/footer as the site.
- **Index:** meta line, two-tone H1 "Bad Google review? Here's every honest way to get it removed.", featured pillar card (black, image + text), cluster filter chips, card grid (16:9 image, cluster chip, read time, title).
- **Article template:** breadcrumb → H1 → author/updated/read-time row → 21:9 cover → **"SHORT ANSWER"** card (answer-first) → sections (H2, paragraphs with inline internal links, bullet lists, numbered step cards, tables, dashed `[SCREENSHOT]` placeholders) → **inline CTA card after section 2** → FAQ accordion → author box → black CTA block → "Keep reading" 3 related posts (from linking map). Desktop: sticky sidebar (table of contents + black CTA card "Which of your reviews can go?"). Mobile: fixed bottom CTA bar "Free review audit / Answer within 24 hours".
- All CTAs → order flow. URLs should be `/blog/{slug}` (slugs in JSON).
- **SEO to implement (not possible in the prototype):** `Article` schema (author, datePublished, dateModified), `BreadcrumbList`, `Organization`, `FAQPage` on FAQ blocks, one H1 per page, visible "Last updated", XML sitemap, IndexNow, Search Console + Bing Webmaster Tools. Internal link syntax in JSON: `[[3|link text]]` = link to post #3.
- **Before publishing:** replace `[AUTHOR]` bio, `[SCREENSHOT]` placeholders; legally review post #9.

---

## Business Rules
- **Pricing per review (No Cure No Pay):** ≤ 4 weeks old = **90**, > 4 weeks = **125** (same number in USD and EUR).
- **Volume discount** on the whole order by number of reviews: 2 → 5%, 3–5 → 10%, 6+ → 15%. On the invoice apply it to the number of **removed** reviews.
- Invoice total = (n≤4w × 90 + n>4w × 125) × (1 − discount).
- **Currency:** derived from location of the Google profile or the country entered in step 3. **EU-27 → EUR (`90 €`), everything else → USD (`$90`).** Landing page pre-guesses from browser time zone. Language is always English.
- **Not eligible / free cancellation:** star-only ratings without text. Age is **never** a cancellation reason (handled by the 125 tier).
- **Accounts:** username = email. On first order: create account, generate password, send it by email (transactional "Order confirmation" mail with login details). Same email on a later order → no new account, order attached to existing account, no new password. Detect existing email live while typing in step 3.
- **Payment:** only after removal; Stripe Payment Link with line items per tier matching removed counts (find-or-create, idempotent via metadata); webhook marks order "paid".

## State (per order, for backend modelling)
Order: id (`BR-#####`), customer (email, name, company, phone, street, city, country, currency), business (Google place id/name/address), reviews[] (google review id / share link / text+name, author, stars, posted date, tier `recent|older`, status `submitted|in_progress|removed|not_eligible`), totals, discount, payment status, timestamps. Customer: email (unique), password hash, orders[], support messages[].

## Interactions & Behavior (summary)
- Hero video: muted autoplay loop, `playbackRate 0.6`; set `muted` via JS (React won't reflect the attribute).
- Case marquee: requestAnimationFrame translateX loop, duplicated track, pause on hover.
- FAQ accordions: single open item.
- Confetti: canvas particles, gravity, ~4.5s.
- Mobile ≥ 44px hit targets; fixed bottom bars on mobile landing / order flow / blog.

## Files
- `byereviews Site.dc.html` – landing, order flow, success, login, dashboard (all views in one file; see the `renderVals` / `renderBase` / `portalVals` logic for state and rules).
- `byereviews Blog.dc.html` – blog index + article template.
- `blog-posts.json` – all 10 posts (title, slug, keyword, cluster, lead, sections, FAQ, cover image) + linking map + author + CTA copy.
- `assets/byereviews-logo.png` – logo.
- `assets.html`, `download-assets.sh`, `download-assets.ps1`, `assets/manifest.json` – image & video download.
- `blog-posts/*.md` – the 10 blog posts as Markdown.
- `support.js` – runtime needed only to open the prototypes in a browser (not for production).
