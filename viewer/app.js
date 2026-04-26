const params = new URLSearchParams(window.location.search);
const member = params.get("member") || "nishino-nanase";
const DEFAULT_PROFILE_AVATAR =
  "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='12' fill='%23e4eaf1'/%3E%3Ctext x='32' y='40' text-anchor='middle' font-family='Arial' font-size='26' fill='%238390a3'%3E7%3C/text%3E%3C/svg%3E";

const elements = {
  scrollPort: document.getElementById("scroll-port"),
  mainPanel: document.getElementById("main-panel"),
  sidebarToggle: document.getElementById("sidebar-toggle"),
  archiveSummary: document.getElementById("archive-summary"),
  archiveMembers: document.getElementById("archive-members"),
  status: document.getElementById("load-status"),
  sentinel: document.getElementById("top-sentinel"),
  feed: document.getElementById("feed"),
  profileCover: document.getElementById("profile-cover"),
  profileAvatar: document.getElementById("profile-avatar"),
  profileHandle: document.getElementById("profile-handle"),
  profileName: document.getElementById("profile-name"),
  profileDescription: document.getElementById("profile-description"),
  profileWatch: document.getElementById("profile-watch"),
  profileMember: document.getElementById("profile-member"),
  tabInfo: document.getElementById("tab-info"),
  tabMedia: document.getElementById("tab-media"),
  detailInfo: document.getElementById("detail-info"),
  detailMedia: document.getElementById("detail-media"),
  detailLead: document.getElementById("detail-lead"),
  detailGenerated: document.getElementById("detail-generated"),
  detailPosts: document.getElementById("detail-posts"),
  detailMediaCount: document.getElementById("detail-media-count"),
  mediaSummary: document.getElementById("media-summary"),
  mediaGrid: document.getElementById("media-grid"),
  lightbox: document.getElementById("lightbox"),
  lightboxImage: document.getElementById("lightbox-image"),
  lightboxClose: document.getElementById("lightbox-close"),
};

const state = {
  manifest: null,
  profile: null,
  archiveIndex: null,
  currentArchiveRecord: null,
  mediaIndex: null,
  files: [],
  nextOlderIndex: -1,
  loading: false,
  loaded: new Set(),
  postsById: new Map(),
  activeTab: "info",
  highlightTimer: null,
};

function isRemoteUrl(value) {
  return /^https?:\/\//i.test(value || "");
}

function buildPath(basePath, fileName = "") {
  if (!basePath) {
    return fileName;
  }

  if (!fileName) {
    return `/${basePath}`;
  }

  return `/${basePath}/${fileName}`;
}

function mediaPath(fileName, kind = "image", paths = state.manifest?.paths) {
  if (!fileName) {
    return "";
  }

  if (fileName.startsWith("/") || isRemoteUrl(fileName)) {
    return fileName;
  }

  const baseKey =
    kind === "thumbnail"
      ? "thumbnails"
      : kind === "video"
        ? "videos"
        : "images";
  return buildPath(paths?.[baseKey], fileName);
}

function bodyMediaPath(item, preferred, paths = state.manifest?.paths) {
  if (preferred === "thumbnail" && item.thumbnailUrl) {
    return mediaPath(item.thumbnailUrl, "thumbnail", paths);
  }

  if (preferred === "thumbnail" && item.thumbnail) {
    return mediaPath(item.thumbnail, "thumbnail", paths);
  }

  if (item.image) {
    return mediaPath(item.image, "image", paths);
  }

  if (item.thumbnail) {
    return mediaPath(item.thumbnail, "thumbnail", paths);
  }

  return "";
}

function profileMediaPath(fileName, key, paths = state.manifest?.paths) {
  if (!fileName || isRemoteUrl(fileName)) {
    return "";
  }

  if (fileName.startsWith("/")) {
    return fileName;
  }

  const kind =
    key && key.toLowerCase().includes("thumbnail") ? "thumbnail" : "image";
  return mediaPath(fileName, kind, paths);
}

function setStatus(message) {
  elements.status.textContent = message || "";
}

function formatNumber(value) {
  if (value === null || value === undefined || value === "") {
    return "-";
  }

  return new Intl.NumberFormat("ja-JP").format(Number(value));
}

function formatTime(seconds) {
  if (!seconds) {
    return "";
  }

  return new Intl.DateTimeFormat("ja-JP", {
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
  }).format(new Date(seconds * 1000));
}

function formatGeneratedAt(value) {
  if (!value) {
    return "-";
  }

  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return String(value);
  }

  return new Intl.DateTimeFormat("ja-JP", {
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
  }).format(date);
}

function avatarPlaceholderText(user, fallbackText = "7") {
  const name = typeof user?.name === "string" ? user.name.trim() : "";

  if (name && name !== "削除されたユーザー") {
    return Array.from(name)[0] || fallbackText;
  }

  return fallbackText;
}

function createAvatar(
  user,
  placeholderText = "7",
  paths = state.manifest?.paths,
) {
  const thumbnail = user?.thumbnailUrl
    ? profileMediaPath(user.thumbnailUrl, "thumbnailUrl", paths)
    : "";
  const fallbackText =
    placeholderText || avatarPlaceholderText(user, placeholderText || "7");

  if (!thumbnail) {
    const placeholder = document.createElement("div");
    placeholder.className = "avatar placeholder";
    placeholder.textContent = fallbackText;
    return placeholder;
  }

  const image = document.createElement("img");
  image.className = "avatar";
  image.src = thumbnail;
  image.alt = "";
  image.loading = "lazy";
  image.addEventListener(
    "error",
    () => {
      image.replaceWith(createAvatar(null, fallbackText, paths));
    },
    { once: true },
  );
  return image;
}

function createTextBody(text) {
  const paragraph = document.createElement("p");
  paragraph.className = "text-body";
  paragraph.textContent = String(text ?? "");
  return paragraph;
}

function createReply(comment) {
  const box = document.createElement("div");
  box.className = "reply-box";

  const user = comment?.user || {};
  const avatar = createAvatar(user, avatarPlaceholderText(user, "?"));
  avatar.classList.add("reply-avatar");

  const content = document.createElement("div");
  content.className = "reply-content";

  const name = document.createElement("div");
  name.className = "reply-name";
  name.textContent = user.name || "削除されたユーザー";

  const text = document.createElement("p");
  text.className = "reply-text";
  text.textContent = comment?.comment?.body || "";

  content.append(name, text);
  box.append(avatar, content);
  return box;
}

function createImage(item) {
  const previewSrc = bodyMediaPath(item, "thumbnail");
  const fullSrc = item.image ? mediaPath(item.image, "image") : previewSrc;

  if (!previewSrc) {
    return createTextBody("[missing image]");
  }

  const button = document.createElement("button");
  button.type = "button";
  button.className = "media-button";
  button.title = "Open image";
  button.addEventListener("click", () => openLightbox(fullSrc));

  const image = document.createElement("img");
  image.className = "post-image";
  image.src = previewSrc;
  image.alt = "";
  image.loading = "lazy";
  image.addEventListener(
    "error",
    () => {
      button.replaceWith(createTextBody("[image unavailable]"));
    },
    { once: true },
  );

  button.append(image);
  return button;
}

function createVideo(item) {
  const videoFile =
    item.movieUrlHq ||
    item.videoUrlHq ||
    item.movieUrlNormal ||
    item.videoUrlNormal ||
    item.video ||
    item.movie ||
    item.videoUrl ||
    item.movieUrl;

  if (!videoFile) {
    return createTextBody("[missing video]");
  }

  const video = document.createElement("video");
  video.className = "post-video";
  video.src = mediaPath(videoFile, "video");
  video.poster = bodyMediaPath(item, "thumbnail");
  video.controls = true;
  video.preload = "metadata";
  return video;
}

function createNestedPost(item) {
  const box = document.createElement("div");
  box.className = "nested-post";

  const name = document.createElement("div");
  name.className = "nested-name";
  name.textContent = item.user?.name || "Shared post";

  const body = createBody(item.post?.body || []);
  box.append(name, body);
  return box;
}

function createTalkRef(item) {
  const span = document.createElement("span");
  span.className = "talk-ref";
  span.textContent = item.talkId ? `talk: ${item.talkId}` : "system message";
  return span;
}

function createBodyItem(item) {
  if (!item || typeof item !== "object") {
    return document.createDocumentFragment();
  }

  if (item.text) {
    return createTextBody(item.text);
  }

  if (item.comment) {
    return createReply(item.comment);
  }

  if (item.image || item.thumbnail) {
    return createImage(item);
  }

  if (
    item.video ||
    item.movie ||
    item.videoUrl ||
    item.movieUrl ||
    item.videoUrlHq ||
    item.movieUrlHq ||
    item.videoUrlNormal ||
    item.movieUrlNormal
  ) {
    return createVideo(item);
  }

  if (item.post) {
    return createNestedPost(item);
  }

  if (item.talkId) {
    return createTalkRef(item);
  }

  return createTextBody("");
}

function createBody(body) {
  const stack = document.createElement("div");
  stack.className = "body-stack";

  if (!Array.isArray(body) || body.length === 0) {
    stack.append(createTextBody(""));
    return stack;
  }

  for (const item of body) {
    const node = createBodyItem(item);
    if (node) {
      stack.append(node);
    }
  }

  return stack;
}

function createStats(post) {
  const stats = document.createElement("div");
  stats.className = "stats";

  const items = [
    { value: post.rtCount, label: "リトーク数" },
    { value: post.commentCount, label: "コメント数" },
    { value: post.likeCount, label: "拍手数" },
  ];

  for (const { value, label } of items) {
    const pill = document.createElement("span");
    const count = formatNumber(value || 0);
    pill.className = "stat-pill";
    pill.setAttribute("aria-label", `${label} ${count}`);
    pill.title = `${label} ${count}`;
    pill.textContent = count;
    stats.append(pill);
  }

  return stats;
}

function createPostCard(entry) {
  const post = entry.post || {};
  const user = entry.user || {};
  const article = document.createElement("article");
  article.className = `post-card ${post.postType >= 100 ? "system" : ""}`;
  article.dataset.postId = post.postId || "";

  if (post.postId !== undefined && post.postId !== null) {
    article.id = `post-${post.postId}`;
    state.postsById.set(String(post.postId), article);
  }

  const avatar = createAvatar(user);
  const main = document.createElement("div");
  main.className = "post-main";

  const head = document.createElement("div");
  head.className = "post-head";

  const name = document.createElement("span");
  name.className = "post-name";
  name.textContent = user.name || state.manifest?.displayName || member;

  const time = document.createElement("time");
  time.className = "post-time";
  time.dateTime = post.time ? new Date(post.time * 1000).toISOString() : "";
  time.textContent = formatTime(post.time);

  head.append(name, time);

  const bubble = document.createElement("div");
  bubble.className = "bubble";
  bubble.append(createBody(post.body));

  main.append(head, bubble, createStats(post));
  article.append(avatar, main);
  return article;
}

async function fetchJson(path) {
  const response = await fetch(path, { cache: "no-cache" });
  if (!response.ok) {
    throw new Error(`${response.status} ${response.statusText}`);
  }
  return response.json();
}

async function fetchOptionalJson(path) {
  const response = await fetch(path, { cache: "no-cache" });

  if (response.status === 404) {
    return null;
  }

  if (!response.ok) {
    throw new Error(`${response.status} ${response.statusText}`);
  }

  return response.json();
}

function renderPosts(posts, position) {
  const fragment = document.createDocumentFragment();
  for (const entry of posts) {
    fragment.append(createPostCard(entry));
  }

  if (position === "prepend") {
    elements.feed.prepend(fragment);
  } else {
    elements.feed.append(fragment);
  }
}

async function loadFile(index, position = "append") {
  if (
    index < 0 ||
    index >= state.files.length ||
    state.loaded.has(index) ||
    state.loading
  ) {
    return false;
  }

  state.loading = true;
  const file = state.files[index];
  const beforeHeight = elements.scrollPort.scrollHeight;
  const beforeTop = elements.scrollPort.scrollTop;
  setStatus("Loading");

  try {
    const posts = await fetchJson(
      buildPath(state.manifest?.paths?.data, file.file),
    );
    renderPosts(posts, position);
    state.loaded.add(index);

    if (position === "prepend") {
      const afterHeight = elements.scrollPort.scrollHeight;
      elements.scrollPort.scrollTop = beforeTop + (afterHeight - beforeHeight);
    }

    setStatus("");
    return true;
  } catch (error) {
    showError(`Failed to load ${file.file}: ${error.message}`);
    return false;
  } finally {
    state.loading = false;
  }
}

async function loadOlder() {
  if (state.nextOlderIndex < 0) {
    return;
  }

  const index = state.nextOlderIndex;
  const loaded = await loadFile(index, "prepend");
  if (loaded) {
    state.nextOlderIndex -= 1;
  }

  if (state.nextOlderIndex < 0) {
    setStatus("All messages loaded");
  }
}

function buildArchiveHref(memberKey) {
  const nextParams = new URLSearchParams(window.location.search);
  nextParams.set("member", memberKey);
  return `?${nextParams.toString()}`;
}

function buildFallbackArchiveRecord(manifest, profileDocument) {
  const profile = profileDocument?.profile || manifest.profile || {};
  return {
    member: manifest.member || member,
    talkId: manifest.talkId || member,
    displayName: profileDocument?.displayName || manifest.displayName || member,
    description:
      profileDocument?.description ||
      manifest.description ||
      profile.description ||
      "",
    watchCount: profileDocument?.watchCount ?? manifest.watchCount,
    generatedAt: profileDocument?.generatedAt || manifest.generatedAt || null,
    profile,
    paths: {
      manifest: `storage/local/${manifest.member || member}/manifest.json`,
      profile:
        manifest.paths?.profile ||
        `storage/local/${manifest.member || member}/profile.json`,
      media:
        manifest.paths?.media ||
        `storage/local/${manifest.member || member}/media.json`,
      images:
        manifest.paths?.images ||
        `storage/media/${manifest.member || member}/images`,
      thumbnails:
        manifest.paths?.thumbnails ||
        `storage/media/${manifest.member || member}/thumbnails`,
      videos:
        manifest.paths?.videos ||
        `storage/media/${manifest.member || member}/videos`,
    },
    totals: manifest.totals || {},
  };
}

function createArchiveMember(record) {
  const link = document.createElement("a");
  const displayName = record.displayName || record.member;
  link.className = `archive-member ${record.member === member ? "is-active" : ""}`;
  link.href = buildArchiveHref(record.member);
  link.setAttribute("aria-label", displayName);
  link.title = displayName;

  if (record.member === member) {
    link.setAttribute("aria-current", "page");
  }

  const avatar = createAvatar(
    {
      name: record.profile?.name || displayName,
      thumbnailUrl: record.profile?.thumbnailUrl || "",
    },
    avatarPlaceholderText({ name: displayName }),
    record.paths,
  );
  avatar.classList.add("archive-avatar");

  const copy = document.createElement("div");
  copy.className = "archive-member__copy";

  const name = document.createElement("p");
  name.className = "archive-member__name";
  name.textContent = displayName;

  const meta = document.createElement("p");
  meta.className = "archive-member__meta";
  meta.textContent = `${formatNumber(record.totals?.posts || 0)} posts`;

  const description = document.createElement("p");
  description.className = "archive-member__description";
  description.textContent = record.description || `@${record.member}`;

  copy.append(name, meta, description);
  link.append(avatar, copy);
  return link;
}

function renderArchiveList(indexDocument, fallbackRecord) {
  const members = Array.isArray(indexDocument?.members)
    ? [...indexDocument.members]
    : [];

  if (
    fallbackRecord &&
    !members.some((record) => record.member === fallbackRecord.member)
  ) {
    members.unshift(fallbackRecord);
  }

  state.archiveIndex = { members };
  state.currentArchiveRecord =
    members.find((record) => record.member === member) ||
    fallbackRecord ||
    null;

  elements.archiveMembers.replaceChildren();

  if (members.length === 0) {
    elements.archiveSummary.textContent = "No archived members found";
    return;
  }

  elements.archiveSummary.textContent = `${formatNumber(members.length)} archived members`;

  for (const record of members) {
    elements.archiveMembers.append(createArchiveMember(record));
  }
}

function setTabState(tabName) {
  state.activeTab = tabName;
  const infoActive = tabName === "info";

  elements.tabInfo.setAttribute("aria-selected", String(infoActive));
  elements.tabInfo.tabIndex = infoActive ? 0 : -1;
  elements.detailInfo.hidden = !infoActive;

  elements.tabMedia.setAttribute("aria-selected", String(!infoActive));
  elements.tabMedia.tabIndex = infoActive ? -1 : 0;
  elements.detailMedia.hidden = infoActive;
}

function renderProfile(profileDocument, manifest, record) {
  const profile =
    profileDocument?.profile || manifest.profile || record?.profile || {};
  const displayName =
    record?.displayName ||
    profileDocument?.displayName ||
    manifest.displayName ||
    member;
  const description =
    profile.description ||
    profileDocument?.description ||
    manifest.description ||
    record?.description ||
    "";
  const watchCount =
    profileDocument?.watchCount ?? manifest.watchCount ?? record?.watchCount;
  const memberKey = manifest.member || member;

  elements.profileHandle.textContent = `@${memberKey}`;
  elements.profileName.textContent = profile.name || displayName;
  elements.profileDescription.textContent =
    description || "No archived profile description.";
  elements.profileWatch.textContent = formatNumber(watchCount);
  elements.profileMember.textContent = `@${memberKey}`;

  elements.detailLead.textContent =
    description ||
    "Local archived talk timeline with offline profile and media navigation.";
  elements.detailGenerated.textContent = formatGeneratedAt(
    profileDocument?.generatedAt || manifest.generatedAt || record?.generatedAt,
  );
  elements.detailPosts.textContent = formatNumber(
    manifest.totals?.posts || record?.totals?.posts || 0,
  );
  elements.detailMediaCount.textContent = formatNumber(
    state.mediaIndex?.stats?.count || 0,
  );

  const avatar = profileMediaPath(
    profile.thumbnailUrl,
    "thumbnailUrl",
    manifest.paths,
  );
  elements.profileAvatar.hidden = false;
  elements.profileAvatar.src = avatar || DEFAULT_PROFILE_AVATAR;
  elements.profileAvatar.alt = `${displayName} profile image`;

  const coverSource =
    profileMediaPath(profile.coverImageUrl, "coverImageUrl", manifest.paths) ||
    profileMediaPath(
      profile.coverImageThumbnailUrl,
      "coverImageThumbnailUrl",
      manifest.paths,
    );

  if (coverSource) {
    elements.profileCover.hidden = false;
    elements.profileCover.src = coverSource;
    elements.profileCover.alt = `${displayName} cover image`;
  } else {
    elements.profileCover.hidden = true;
    elements.profileCover.removeAttribute("src");
  }
}

function mediaLabel(item) {
  return item.type === "video" ? "動画" : "画像";
}

function mediaPreviewPath(item) {
  if (item.thumbnail) {
    return mediaPath(item.thumbnail, "thumbnail");
  }

  if (item.image) {
    return mediaPath(item.image, "image");
  }

  return "";
}

function createMediaPlaceholder(item) {
  const placeholder = document.createElement("div");
  placeholder.className = "media-card__placeholder";
  placeholder.textContent = mediaLabel(item);
  return placeholder;
}

function createMediaCard(item) {
  const button = document.createElement("button");
  const label = `${mediaLabel(item)} / talk ${item.postId || "-"} / ${formatTime(item.time) || "time unavailable"}`;

  button.type = "button";
  button.className = "media-card";
  button.setAttribute("aria-label", label);
  button.title = `${label} / scroll to talk`;
  button.addEventListener("click", async () => {
    await focusPost(item.postId);
  });

  const preview = mediaPreviewPath(item);
  const previewNode = preview
    ? document.createElement("img")
    : createMediaPlaceholder(item);

  if (preview) {
    previewNode.className = "media-card__thumb";
    previewNode.src = preview;
    previewNode.alt = "";
    previewNode.loading = "lazy";
    previewNode.addEventListener(
      "error",
      () => {
        previewNode.replaceWith(createMediaPlaceholder(item));
      },
      { once: true },
    );
  }

  button.append(previewNode);

  const body = document.createElement("div");
  body.className = "media-card__body";

  const kind = document.createElement("p");
  kind.className = "media-kind";
  kind.textContent = mediaLabel(item);

  const title = document.createElement("p");
  title.className = "media-card__title";
  title.textContent = item.userName || state.manifest?.displayName || member;

  const meta = document.createElement("p");
  meta.className = "media-card__meta";
  meta.textContent = `${formatTime(item.time) || "No timestamp"} / talk ${item.postId || "-"}`;

  body.append(kind, title, meta);
  button.append(body);
  return button;
}

function renderMediaGallery(mediaDocument) {
  const items = Array.isArray(mediaDocument?.items) ? mediaDocument.items : [];
  state.mediaIndex = mediaDocument;
  elements.mediaGrid.replaceChildren();
  elements.detailMediaCount.textContent = formatNumber(
    mediaDocument?.stats?.count || items.length,
  );

  if (items.length === 0) {
    const empty = document.createElement("div");
    empty.className = "media-empty";
    empty.textContent =
      "No archived images or videos were found for this talk.";
    elements.mediaGrid.append(empty);
    elements.mediaSummary.textContent = "0 archived items";
    return;
  }

  elements.mediaSummary.textContent = `${formatNumber(items.length)} archived items`;

  for (const item of items) {
    elements.mediaGrid.append(createMediaCard(item));
  }
}

function findFileIndexByPostId(postId) {
  const numericPostId = Number(postId);
  if (!Number.isFinite(numericPostId)) {
    return -1;
  }

  return state.files.findIndex((file) => {
    if (file.minPostId === null || file.maxPostId === null) {
      return false;
    }

    return numericPostId >= file.minPostId && numericPostId <= file.maxPostId;
  });
}

async function ensurePostLoaded(postId) {
  const key = String(postId);
  const existing = state.postsById.get(key);
  if (existing) {
    return existing;
  }

  const targetIndex = findFileIndexByPostId(postId);
  if (targetIndex < 0) {
    return null;
  }

  while (
    !state.loaded.has(targetIndex) &&
    state.nextOlderIndex >= targetIndex
  ) {
    const loaded = await loadFile(state.nextOlderIndex, "prepend");
    if (!loaded) {
      break;
    }
    state.nextOlderIndex -= 1;
  }

  return state.postsById.get(key) || null;
}

function clearHighlightedPost() {
  if (state.highlightTimer !== null) {
    window.clearTimeout(state.highlightTimer);
    state.highlightTimer = null;
  }

  for (const node of state.postsById.values()) {
    node.classList.remove("is-highlighted");
    node.removeAttribute("tabindex");
  }
}

function highlightPost(article) {
  clearHighlightedPost();
  article.classList.add("is-highlighted");
  article.tabIndex = -1;
  article.focus({ preventScroll: true });
  state.highlightTimer = window.setTimeout(() => {
    article.classList.remove("is-highlighted");
    article.removeAttribute("tabindex");
    state.highlightTimer = null;
  }, 2600);
}

async function focusPost(postId) {
  if (!postId) {
    return;
  }

  const article = await ensurePostLoaded(postId);
  if (!article) {
    setStatus(`Talk ${postId} was not found in the archive`);
    return;
  }

  article.scrollIntoView({ behavior: "smooth", block: "center" });
  highlightPost(article);
  setStatus("");
}

function openLightbox(src) {
  if (!src) {
    return;
  }

  elements.lightboxImage.src = src;
  if (typeof elements.lightbox.showModal === "function") {
    elements.lightbox.showModal();
  }
}

function closeLightbox() {
  if (elements.lightbox.open) {
    elements.lightbox.close();
  }
  elements.lightboxImage.removeAttribute("src");
}

function showError(message) {
  setStatus("");
  const box = document.createElement("div");
  box.className = "error-box";
  box.textContent = message;
  elements.feed.append(box);
}

function setupObserver() {
  const observer = new IntersectionObserver(
    (entries) => {
      if (entries.some((entry) => entry.isIntersecting)) {
        loadOlder();
      }
    },
    {
      root: elements.scrollPort,
      rootMargin: "240px 0px 0px 0px",
      threshold: 0,
    },
  );

  observer.observe(elements.sentinel);
}

function bindControls() {
  elements.tabInfo.addEventListener("click", () => setTabState("info"));
  elements.tabMedia.addEventListener("click", () => setTabState("media"));

  elements.sidebarToggle.addEventListener("click", () => {
    const root = document.documentElement;
    const collapsed = root.classList.toggle("sidebar-collapsed");
    sessionStorage.setItem("sidebarCollapsed", collapsed ? "1" : "0");
  });

  elements.lightboxClose.title =
    elements.lightboxClose.getAttribute("aria-label") || "Close";
}

async function init() {
  try {
    bindControls();
    setTabState("info");

    const archiveIndex = await fetchOptionalJson("/storage/local/index.json");
    const manifest = await fetchJson(`/storage/local/${member}/manifest.json`);
    const profileDocument = manifest.paths?.profile
      ? await fetchOptionalJson(`/${manifest.paths.profile}`)
      : null;
    const mediaDocument = manifest.paths?.media
      ? await fetchOptionalJson(`/${manifest.paths.media}`)
      : null;

    state.manifest = manifest;
    state.profile = profileDocument;
    state.mediaIndex = mediaDocument;
    state.files = [...(manifest.dataFiles || [])].sort(
      (left, right) => (left.start || 0) - (right.start || 0),
    );

    if (state.files.length === 0) {
      showError(
        `No data files are listed in storage/local/${member}/manifest.json`,
      );
      return;
    }

    const fallbackRecord = buildFallbackArchiveRecord(
      manifest,
      profileDocument,
    );
    renderArchiveList(archiveIndex, fallbackRecord);
    renderProfile(profileDocument, manifest, state.currentArchiveRecord);
    renderMediaGallery(mediaDocument);

    const latestIndex = state.files.length - 1;
    state.nextOlderIndex = latestIndex - 1;
    await loadFile(latestIndex, "append");
    if (state.nextOlderIndex < 0) {
      setStatus("All messages loaded");
    }

    await new Promise((resolve) => requestAnimationFrame(resolve));
    const port = elements.scrollPort;
    const savedFromBottom = sessionStorage.getItem(
      "scrollFromBottom:" + member,
    );
    if (savedFromBottom !== null) {
      const fromBottom = parseInt(savedFromBottom, 10) || 0;
      port.scrollTop = Math.max(
        0,
        port.scrollHeight - port.clientHeight - fromBottom,
      );
    } else {
      port.scrollTop = port.scrollHeight;
    }
    setupObserver();

    let _scrollSaveRaf = 0;
    port.addEventListener("scroll", () => {
      if (_scrollSaveRaf) return;
      _scrollSaveRaf = requestAnimationFrame(() => {
        const fromBottom =
          port.scrollHeight - port.clientHeight - port.scrollTop;
        sessionStorage.setItem(
          "scrollFromBottom:" + member,
          Math.max(0, fromBottom),
        );
        _scrollSaveRaf = 0;
      });
    });
  } catch (error) {
    showError(
      `Cannot open ${member}. Run php curl-media.php --member=${member} first. ${error.message}`,
    );
  }
}

elements.lightboxClose.addEventListener("click", closeLightbox);
elements.lightbox.addEventListener("click", (event) => {
  if (event.target === elements.lightbox) {
    closeLightbox();
  }
});
elements.profileAvatar.addEventListener("error", () => {
  elements.profileAvatar.src = DEFAULT_PROFILE_AVATAR;
});
elements.profileCover.addEventListener("error", () => {
  elements.profileCover.hidden = true;
  elements.profileCover.removeAttribute("src");
});

init();
