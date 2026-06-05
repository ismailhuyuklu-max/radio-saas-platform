# Radio SaaS Design System

## Grid

- Base layout: 12-column grid
- Desktop content width: 1440px
- Outer page padding: 28px
- Card radius tiers: `12px`, `16px`, `24px`, `30px`
- Spacing scale: `8 / 12 / 16 / 24 / 32 / 48`

## Typography

- Primary font: `Inter`
- Display font: `Plus Jakarta Sans`
- Fallbacks: `system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif`
- Locale note: Turkish glyph coverage must remain intact for `ı, ğ, ş, ç, ü, ö`

## Color Palette

- Deep Slate Blue: `#07111F`
- Elevated Surface: `#0C1728`
- Surface Soft: `#111C2F`
- Cyber Amber: `#F59E0B`
- Cyber Orange: `#FB923C`
- Emerald Green: `#10B981`
- Neon Red: `#F43F5E`
- Ice Blue Accent: `#38BDF8`
- Text Primary: `#E2E8F0`
- Text Muted: `#94A3B8`

## Components

- Buttons
  - Hover: slightly brighter background, subtle lift
  - Active: stronger shadow compression
  - Loading: spinner + reduced saturation
- Cards
  - Border radius: `24px` or `30px`
  - Glass effect: `backdrop-filter: blur(14px-18px)`
  - Border: `rgba(148, 163, 184, 0.18)`
- Status Colors
  - Success: Emerald Green
  - Warning: Cyber Amber
  - Danger: Neon Red

## Dashboard Layout

- Hero area: split layout with editorial copy and status chips
- Main map stage: centered SVG/image hotspot canvas
- Right rail: sticky operations rail with compact summary cards
- Matrix: 7x4 interactive cards with soft glow and waveform cues

## Interaction Rules

- Table rows must feel like premium list items, not spreadsheet cells
- Hotspots should glow on hover and active state
- Upload actions should show circular progress and loading spin states
- Copy actions should emit an immediate toast

