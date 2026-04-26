# 7gogo Local Archiver

A tool to backup 7gogo talk histories and media files, complete with a local web viewer for offline archiving.

## 🎯 Core Features

1. **Backup Talk History**: Fully crawls public posts of 7gogo users.
2. **Download Media**: Saves images, videos, and avatars locally, rewriting links in JSON files.
3. **Local Offline Viewer**: Provides a static UI clone to browse archived content completely offline.

## 🚀 Quick Start

Assuming the user slug (usually the ID or member's name from the 7gogo URL) we want to backup is `nishino-nanase`.

### 1. Fetch Text Data (JSON)

Fetch all posts for the user:

```bash
php curl-data.php --member=nishino-nanase --mode=full
```

### 2. Download Media & Generate Configs

Download images and videos, and generate local configuration files for the web viewer:

```bash
php curl-media.php --member=nishino-nanase
```

_(Can be interrupted at any time; rerunning will automatically skip already downloaded files)_

### 3. Start the Local Viewer

Start the built-in PHP server in the project root:

```bash
php -S localhost:8000
```

Open in your browser: [http://localhost:8000/viewer/?member=nishino-nanase](http://localhost:8000/viewer/?member=nishino-nanase)

---

## 🛠 Incremental Updates & Advanced Usage

If you have already run `--mode=full` before and only want to fetch the latest updates, use the incremental mode:

```bash
# Fetch new posts since the last backup
php curl-data.php --member=nishino-nanase --mode=incremental

# Download media files from the new content
php curl-media.php --member=nishino-nanase
```

**Other useful commands:**

- **Resume broken downloads**: `php curl-data.php --member=nishino-nanase --mode=full --resume`
- **Safe test (fetch only 1 page)**: `php curl-data.php --member=nishino-nanase --mode=full --start=1 --max-pages=1`

## 📁 Directory Structure

Data is automatically stored in the `storage/` directory:

```text
storage/
  ├─ raw/          # Raw API JSON data
  ├─ local/        # Processed local JSON and viewer manifests
  ├─ media/        # Downloaded local media (images, videos, thumbnails)
  ├─ state/        # Fetch checkpoint state for resuming
  └─ logs/         # Execution logs
viewer/            # Static web viewer code
```

> **Note:** The **member list** on the viewer's left sidebar relies on `storage/local/index.json`. Make sure you have run `curl-media.php` before opening the page.
