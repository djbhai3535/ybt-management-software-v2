# Application Icons Placeholder

Tauri expects standard Windows icon formats in this directory:
- `32x32.png` (32x32 pixel app icon)
- `128x128.png` (128x128 pixel app icon)
- `128x128@2x.png` (256x256 pixel high-DPI app icon)
- `icon.ico` (Multi-resolution Windows executable icon including 16, 24, 32, 48, 64, 128, 256 px)
- `icon.icns` (macOS bundle icon)

In production builds, run `npx @tauri-apps/cli icon app-logo.png` to auto-generate all required sizes.
