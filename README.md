# Rimbo-Notes

Remember every idea that comes to mind.

This repository contains the Idea Space app.

A minimal workspace for capturing, connecting, and rediscovering ideas. Built with plain HTML, CSS, and JavaScript; no compilation, npm dependencies, database, or API keys are required.

## Local setup

1. Clone this repository and open its directory:

```sh
git clone https://github.com/infoaamirkhan115-blip/Rimbo-Notes.git
cd Rimbo-Notes
```
2. Install Python 3 if it is not already available.
3. Start the static server:

```sh
python3 -m http.server 8000 --directory dist
```

On Windows:

```powershell
py -m http.server 8000 --directory dist
```

4. Open http://localhost:8000 in your browser. Use localhost for microphone support.

## Project structure

- `dist/index.html`: document and app entry point
- `dist/style.css`: responsive layout and visual styles
- `dist/app.js`: screens, interactions, sample ideas, persistence, and graph

Edit these files directly, then refresh the browser. An optional JavaScript syntax check requires Node.js:

```sh
node --check dist/app.js
```

## Features

- Quick text, photo, link, and voice capture
- Inbox, categories, tags, favorites, and full-text search
- Editable notes, purpose, context, attachments, and idea history
- Next steps and Captured / Exploring / Trying / Completed stages
- Draggable connected graph and manual links between ideas
- One-time and recurring in-app reminders with quiet hours
- Rediscovery, weekly review prompts, archive, trash, and restoration
- JSON backup/import and Markdown export

## Hosting

Upload the **contents of `dist/`** to any static web host. The published root must contain `index.html`, `style.css`, and `app.js` together. No build command or environment variables are necessary. Use HTTPS for microphone recording on a deployed site.

The original ChatGPT Sites deployment configuration is intentionally excluded; this repository is portable to any static host.

## Storage and current limitations

Ideas and settings are stored in `localStorage` under `idea-space-v1`. The initial sample ideas appear only when no saved data exists. There is no backend, user-account system, cloud synchronization, email delivery, or background push notification service. The private ChatGPT Sites access gate is hosting access control, not application account storage.

In-app reminders run while the app is open. Export backups from Settings before changing devices or clearing browser data. Import replaces the existing local collection after confirmation. Trash retains items until explicitly deleted permanently.

Attachments are limited to 2 MB each; total storage depends on browser quota. Voice recordings stop after 60 seconds. Recording requires microphone permission and a secure context (HTTPS or localhost). Transcription depends on browser support and may use the browser provider's speech service. Hindi and English can be selected in Settings.

Google Fonts and sample Unsplash images require an internet connection. User-created notes do not require these assets.

## Verification

Checked in the browser: quick capture, editing, persistence after reload, note search, reminder setup, graph display, soft deletion, and restoration. JavaScript syntax checked with Node.js. Live microphone capture was not tested.
