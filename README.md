# Clipbin

Your own little video host. Upload a clip, get a short link like `clips.example.com/k7Qx2mP`, and paste it into a forum, Discord, Slack or a chat. The link unfurls into a playable video, and the page itself is a clean player that works well on phones.

It is built to run on ordinary **shared PHP hosting** such as DreamHost: no Docker, no background workers, no database server and no ffmpeg on the server.

## What it does

- **Upload from anywhere.** Drag and drop on a computer, tap to pick from your camera roll on a phone, or paste a file. Share videos straight to Clipbin from your phone's share menu: on Android through the installed app, on iPhone through a small Shortcut.
- **Converts in your browser.** Phone videos (like iPhone HEVC `.mov`), `.webm`, `.mkv` and 4K clips are converted to a standard H.264 MP4 (up to 1080p) on your own device before upload, using the browser's built-in video encoder. Files that are already web-friendly MP4s are uploaded untouched. A thumbnail is made at the same time.
- **Reliable uploads.** Files go up in small pieces with automatic retries, so big videos get past hosting limits and flaky phone connections. Interrupted uploads resume where they stopped.
- **Short links and rich embeds.** Every video gets a 7-character link with OpenGraph and Twitter player tags (inline video in Discord, Slack, Telegram, X and most forums) plus oEmbed, and an iframe player at `/e/<id>`.
- **Only you can upload.** Uploading needs your password; watching only needs the link. Rename and delete videos from your library.

## Install on DreamHost

You need a DreamHost shared (or VPS) plan with PHP 8.1 or newer. Everything below happens in the DreamHost panel and one SSH session.

1. **Make a site for it.** In the panel go to *Websites → Add Website*, and add a domain or subdomain such as `clips.example.com`. Choose PHP 8.2 or newer.
2. **Turn on HTTPS.** Under *Websites → Secure Certificates*, add the free Let's Encrypt certificate. Discord and X only show inline video for `https://` links.
3. **Enable SSH for your user.** *Websites → Manage Websites → (your site) → Manage → SFTP Users & Files*, then edit the user and set it to *SSH*.
4. **Put the code on the server.** SSH in and run (change the folder name to your domain):

   ```sh
   cd ~/clips.example.com
   mkdir -p ~/clips-old && mv ./* ./.[!.]* ~/clips-old/ 2>/dev/null
   git clone https://github.com/syntaxless/clipbin.git .
   ```

   The second line moves DreamHost's placeholder files out of the way into `~/clips-old` so the folder is empty.

5. **Run the setup** and answer its questions (site address, name, and your upload password):

   ```sh
   php lib/setup.php
   ```

   It writes `config.php` with your password stored as a hash.

6. **Open the site,** sign in, and upload a video.

To update later, SSH in and run `git pull` in the site folder. Your `config.php`, `data/` (the database) and `media/` (the videos) are never part of the repository, so updates don't touch them.

### Optional: deploy automatically on every push

The workflow in `.github/workflows/deploy.yml` copies the code to DreamHost whenever `main` changes. It skips itself until you add these secrets in GitHub (*Settings → Secrets and variables → Actions*):

| Secret | Value |
| --- | --- |
| `DH_HOST` | Your server's host name, e.g. `iad1-shared-b7-01.dreamhost.com` (shown on the SFTP user page), or your domain |
| `DH_USER` | Your SSH user |
| `DH_PATH` | The site folder, e.g. `/home/youruser/clips.example.com` |
| `DH_SSH_KEY` | A private SSH key. Make one with `ssh-keygen -t ed25519 -f clipbin-deploy -N ""`, put the contents of `clipbin-deploy.pub` on its own line in `~/.ssh/authorized_keys` on DreamHost, and paste the contents of `clipbin-deploy` here |

You still run `php lib/setup.php` on the server once (step 5 above).

## Using it from your phone

- **iPhone:** open the site in Safari, tap *Share → Add to Home Screen*. Clipbin then opens like an app. Tap the upload box to pick a video from Photos. After upload the link is ready to copy or send with the *Share* button.
- **iPhone share menu:** Safari can't add itself to the share menu, so a Shortcut does it. Sign in to Clipbin, open *Upload from the iPhone share menu* under the upload box, copy the shortcut address, and then:
  1. In the **Shortcuts** app tap **+** and name it *Clipbin*.
  2. Open its details (ⓘ), turn on **Show in Share Sheet**, and set it to receive **Media** only.
  3. Add **Encode Media** on *Shortcut Input*; under *Show More* set Size to **1920×1080** (not HEVC). This makes an H.264 MP4 that plays everywhere.
  4. Add **Get Contents of URL** with the copied address; under *Show More* choose Method **POST**, Request Body **Form**, and add a **File** field named `video` set to *Encoded Media*.
  5. Add **Copy to Clipboard** (*Contents of URL*) and **Show Notification** (*Contents of URL*).

  Now share a video from Photos and pick *Clipbin*: it uploads, and the link is on your clipboard when the notification appears (or an error saying what went wrong). The address contains a secret key; *Make a new key* on the same panel turns the old one off. The video goes up in one request, so it is limited by `upload_max_filesize` in `.user.ini` (1 GB); choose 1280×720 in step 3 for long clips. The thumbnail is made the next time you open your library.
- **Android:** open the site in Chrome, tap the menu → *Add to Home screen* (or *Install app*). Clipbin then shows up in the share sheet: share a video from Photos/Gallery to Clipbin and it uploads straight away.
- Keep the page open while a video converts and uploads. The screen is kept awake automatically where the browser allows it. If you lock the phone or switch apps anyway, the upload pauses and continues from the last piece when you come back (a conversion starts over by itself). Even if the phone reloads the page, the upload picks up again the next time you open Clipbin.

## Browser support for conversion

Conversion uses WebCodecs, available in current Chrome, Edge, Safari (16.4+, including iPhone) and Firefox (130+). A browser that can't decode the source format (for example HEVC on some Linux desktop browsers) shows a clear error; already-compatible MP4s always upload as-is.

## Settings

All in `config.php`:

| Key | Default | Meaning |
| --- | --- | --- |
| `site_name` | `Clipbin` | Name in the header, page titles and embeds |
| `base_url` | auto | Public address, e.g. `https://clips.example.com` |
| `password` | | Upload password (plain or `password_hash` output) |
| `max_upload_mb` | `2048` | Largest upload after conversion |
| `chunk_mb` | `8` | Upload piece size; shrinks automatically if the host refuses |
| `max_dimension` | `1920` | Longest side of converted video in pixels (1920 = 1080p) |

To change the app name on phones' home screens, also edit `name` and `short_name` in `manifest.webmanifest`.

## Running locally

```sh
cp config.sample.php config.php   # set a password
php -S localhost:8080 router.php
```

`router.php` mirrors the `.htaccess` rules for PHP's built-in server.

## How it's put together

| Path | What |
| --- | --- |
| `index.php` | Sign-in, upload area and your library |
| `api.php` | Upload (init, chunk, status, finish), thumbnails, rename and delete; needs sign-in |
| `share.php` | One-request upload with the secret key, used by the iPhone shortcut |
| `watch.php` | Public player page with OpenGraph / Twitter / oEmbed tags |
| `embed.php` | Bare player for iframes (`/e/<id>`) |
| `oembed.php` | oEmbed JSON endpoint (`/oembed?url=…`) |
| `assets/upload.js` | In-browser conversion, thumbnail and chunked upload |
| `sw.js`, `manifest.webmanifest` | Home-screen install and Android share target |
| `data/` | SQLite database, sessions, partial uploads (blocked from the web) |
| `media/` | Finished `<id>.mp4` and `<id>.jpg` files, served directly by Apache |

Video conversion uses [Mediabunny](https://mediabunny.dev) (MPL-2.0), vendored in `assets/vendor/`.
